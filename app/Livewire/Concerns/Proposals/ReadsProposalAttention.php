<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\SyncLogframeTask;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalAttentionDismissal;
use App\Models\ProposalReviewer;
use App\Models\ProposalStatusNote;
use App\Models\Result;
use App\Models\Risk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * The "Needs attention" bar of proposals/show: every late or soon item,
 * grouped by category, each with the one button that fixes it.
 *
 * It reads the page's existing readings (deadlines, money by currency,
 * projects, targets, files) so the bar and the sections below can never
 * disagree, and adds the "before it goes to review" checks while the
 * proposal is still being written. Hidden items are kept per person in
 * proposal_attention_dismissals. Used by proposals/show only.
 *
 * Item shape: key (stable, used to hide it), cat (one of CATEGORIES), late
 * (red) or soon (grey), text, when (the small tag), fix (label + a Livewire
 * call, or label + url), section (where "Show me" goes).
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read list<array{key: string, kind: string, date: CarbonImmutable, title: string, detail: string, state: string, days: int, url: string|null, tab: string}> $deadlines  #[Computed] deadlines()
 * @property-read array<string, array{committed: float, received: float, pending: float, unscheduled: float, percent: int, overdue: float, overdueCount: int, failedCount: int, nextDue: CarbonImmutable|null}> $moneyByCurrency  #[Computed] moneyByCurrency()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 * @property-read Collection<int, ProposalStatusNote> $statusNotes  #[Computed] statusNotes()
 * @property-read array<int, array{missingNetCount: int}> $agreementMoney  #[Computed] agreementMoney()
 * @property-read list<array{key: string, cat: string, late: bool, text: string, when: string, fix: array{label: string, call?: string, url?: string, go?: bool}|null, section: string}> $attentionItems  #[Computed] attentionItems()
 * @property-read list<string> $hiddenAttentionKeys  #[Computed] hiddenAttentionKeys()
 */
trait ReadsProposalAttention
{
    /** The categories in the order the bar shows them. */
    public const ATTENTION_CATEGORIES = ['ready', 'money', 'reports', 'work', 'projects', 'results', 'risks', 'grant'];

    /** How many days ahead an item counts as coming soon. */
    private const ATTENTION_SOON_DAYS = 30;

    public bool $attentionOpen = false;

    public string $attentionFilter = 'all';

    /**
     * The framework counts of this request; never sent to the browser.
     *
     * @var array{unmeasured: Collection<int, Result>, happened: Collection<int, Risk>, indicatorCount: int, noTarget: int, loose: int}|null
     */
    private ?array $attentionFrameworkMemo = null;

    /**
     * Hide one item for the signed-in person only.
     */
    public function hideAttentionItem(string $key): void
    {
        Gate::authorize('view', $this->proposal);

        if (! in_array($key, array_column($this->attentionItems, 'key'), true)) {
            return;
        }

        ProposalAttentionDismissal::query()->firstOrCreate([
            'user_id' => (int) auth()->id(),
            'proposal_id' => $this->proposalId,
            'item_key' => $key,
        ]);

        unset($this->hiddenAttentionKeys);
    }

    /**
     * Bring back every item the signed-in person hid on this proposal.
     */
    public function showHiddenAttention(): void
    {
        Gate::authorize('view', $this->proposal);

        ProposalAttentionDismissal::query()
            ->where('user_id', (int) auth()->id())
            ->where('proposal_id', $this->proposalId)
            ->delete();

        unset($this->hiddenAttentionKeys);
    }

    /**
     * "Show me": open the place that holds the item, then scroll to it and
     * flash it in the browser.
     */
    public function showMe(string $section): void
    {
        if ($section === 'logframe') {
            $this->dispatch('pp-flash', target: 'lf');

            return;
        }

        if ($section === 'card') {
            $this->cardMore = true;
            $this->dispatch('pp-flash', target: 'card');

            return;
        }

        $this->openSection(match ($section) {
            'status' => 'history',
            'documents' => 'docs',
            'deadlines' => 'coming',
            default => $section,
        });
    }

    public function updatedAttentionFilter(): void
    {
        if (! in_array($this->attentionFilter, ['all', 'late', 'soon', ...self::ATTENTION_CATEGORIES], true)) {
            $this->attentionFilter = 'all';
        }
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function hiddenAttentionKeys(): array
    {
        return array_values(ProposalAttentionDismissal::query()
            ->where('user_id', (int) auth()->id())
            ->where('proposal_id', $this->proposalId)
            ->pluck('item_key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all());
    }

    /**
     * Every item, hidden ones included; the view filters the hidden out.
     *
     * @return list<array{key: string, cat: string, late: bool, text: string, when: string, fix: array{label: string, call?: string, url?: string, go?: bool}|null, section: string}>
     */
    #[Computed]
    public function attentionItems(): array
    {
        $proposal = $this->proposal;
        $items = [];
        $today = CarbonImmutable::today();
        $mayUpdate = auth()->user()?->can('update', $proposal) === true;

        $push = function (string $key, string $cat, bool $late, string $text, string $when, ?array $fix, string $section) use (&$items, $mayUpdate): void {
            $items[] = ['key' => $key, 'cat' => $cat, 'late' => $late, 'text' => $text, 'when' => $when, 'fix' => $mayUpdate || isset($fix['url']) || isset($fix['go']) ? $fix : null, 'section' => $section];
        };

        $whenFor = fn (int $days): string => match (true) {
            $days === 0 => __('proposals.attention.due_today'),
            $days < 0 => trans_choice('proposals.attention.days_late', -$days, ['count' => -$days]),
            default => trans_choice('proposals.attention.in_days', $days, ['count' => $days]),
        };

        foreach ($this->deadlines as $deadline) {
            if ($deadline['kind'] === 'grant' || $deadline['kind'] === 'project' || $deadline['days'] > self::ATTENTION_SOON_DAYS) {
                continue;
            }

            $late = $deadline['state'] === 'overdue';
            [$cat, $fix, $section] = match ($deadline['kind']) {
                'transfer' => ['money', $this->canViewAgreements() ? ['label' => __('proposals.attention.fix_received'), 'call' => 'openTransferEdit('.(int) substr($deadline['key'], 9).')'] : null, 'money'],
                'donor-report' => ['reports', $deadline['url'] !== null ? ['label' => __('proposals.attention.fix_open'), 'url' => $deadline['url']] : null, 'reports'],
                'report' => ['reports', $deadline['url'] !== null ? ['label' => __('proposals.attention.fix_open'), 'url' => $deadline['url']] : null, 'reports'],
                default => ['work', $deadline['url'] !== null ? ['label' => __('proposals.attention.fix_open'), 'url' => $deadline['url']] : null, 'plan'],
            };

            $push(($late ? 'late-' : 'soon-').$deadline['key'], $cat, $late, $deadline['title'].' · '.$deadline['detail'], $whenFor($deadline['days']), $fix, $section);
        }

        if ($this->canViewAgreements()) {
            foreach ($this->moneyByCurrency as $currency => $line) {
                if ($line['failedCount'] > 0) {
                    $push('failed-'.$currency, 'money', true, trans_choice('proposals.warning_failed_transfers', $line['failedCount'], ['count' => $line['failedCount'], 'currency' => $currency]), __('proposals.attention.when_failed'), null, 'money');
                }

                if (round($line['unscheduled'], 2) > 0.0 && ! in_array($proposal->status, [ProposalStatus::Rejected, ProposalStatus::Completed], true)) {
                    $push('unscheduled-'.$currency, 'money', true, __('proposals.attention.unscheduled', ['amount' => number_format($line['unscheduled'], 0), 'currency' => $currency]), __('proposals.attention.when_not_planned'), ['label' => __('proposals.attention.fix_schedule'), 'call' => 'openTransferCreate'], 'money');
                }
            }

            $missingNet = (int) collect($this->agreementMoney)->sum('missingNetCount');

            if ($missingNet > 0) {
                $push('missing-net', 'money', true, trans_choice('funding_agreements.received_tranches_missing_net', $missingNet, ['count' => $missingNet]), __('proposals.attention.when_missing'), null, 'money');
            }

            if (in_array($proposal->status, [ProposalStatus::Active, ProposalStatus::Completed], true) && $this->agreements->isEmpty()) {
                $push('no-agreement', 'money', true, __('proposals.attention.no_agreement'), __('proposals.attention.when_missing'), $this->canRecordAgreement ? ['label' => __('funding_agreements.record_agreement'), 'call' => 'openAgreementCreate'] : null, 'money');
            }
        }

        // Existence only, never an amount, so it shows without the money permission
        // (as the old warnings list did). The status is corrected in History.
        if (! $proposal->status->isApprovedByDonor() && DonorProposal::query()->where('proposal_id', $this->proposalId)->exists()) {
            $push('agreement-status-inconsistent', 'money', true, __('funding_agreements.proposal_status_inconsistent_with_agreement'), __('proposals.attention.when_mismatch'), null, 'history');
        }

        if ($proposal->donor_id !== null && Donor::query()->pending()->whereKey($proposal->donor_id)->exists()) {
            $push('donor-pending', 'money', false, __('proposals.donor_request_pending'), __('proposals.attention.when_waiting'), null, 'card');
        }

        if (! $proposal->isPayroll()) {
            $grantEnd = $proposal->effectiveDateEnd() ?? $proposal->planned_date_end;

            foreach ($this->projects as $project) {
                if ($grantEnd !== null && $project->end_date !== null && $project->end_date->gt($grantEnd)) {
                    $over = (int) $grantEnd->diffInDays($project->end_date);
                    $push('project-outrun-'.$project->id, 'projects', true, __('proposals.attention.project_outrun', ['project' => $project->headline(), 'end' => $project->end_date->translatedFormat(__('formats.date')), 'grant' => $grantEnd->translatedFormat(__('formats.date'))]), trans_choice('proposals.attention.days_over', $over, ['count' => $over]), ['label' => __('proposals.attention.fix_open'), 'url' => route('projects.show', $project)], 'card');
                }
            }

            // The Projects & places fold that used to flag this was removed (PR feedback 2026-11-23).
            $missingCentres = collect($this->locationRows)->where('status', 'missing_center')->count();

            if ($missingCentres > 0) {
                $push('missing-centres', 'projects', false, trans_choice('proposals.attention.missing_centres', $missingCentres, ['count' => $missingCentres]), __('proposals.attention.when_missing'), $this->canFillCenters ? ['label' => __('proposals.add_centers'), 'call' => 'openCenterFill'] : null, 'card');
            }

            foreach ($this->targets as $target) {
                if ($target->reports->isEmpty() && $proposal->status->isApprovedByDonor() && $proposal->planned_date_begin !== null && $proposal->planned_date_begin->lte($today)) {
                    $push('unreported-'.$target->id, 'results', true, __('proposals.attention.unreported', ['indicator' => str($target->indicator->name)->limit(50)]), __('proposals.attention.when_unreported'), null, 'logframe');
                }
            }

            $framework = $this->attentionFramework();

            foreach ($framework['unmeasured'] as $result) {
                $push('no-indicator-'.$result->id, 'results', true, __('proposals.attention.no_indicator', ['level' => $result->type->label(), 'statement' => str($result->statement)->limit(50)]), __('proposals.attention.when_not_measured'), self::frameworkGo('ind', __('proposals.attention.fix_add_indicator')), 'logframe');
            }

            foreach ($framework['happened'] as $risk) {
                $push('risk-happened-'.$risk->id, 'risks', true, __('proposals.attention.risk_happened', ['risk' => $risk->name]), __('proposals.attention.when_happened'), null, 'logframe');
            }
        }

        $isLive = ! in_array($proposal->status, [ProposalStatus::Rejected, ProposalStatus::Completed], true);

        $termEnd = $proposal->effectiveDateEnd() ?? $proposal->planned_date_end;

        if ($isLive && $proposal->actual_date_end === null && $termEnd !== null && $termEnd->lt($today) && $proposal->status->isApprovedByDonor()) {
            $push('term-over', 'grant', true, __('proposals.attention.term_over'), $whenFor((int) $today->diffInDays($termEnd, false)), $proposal->status === ProposalStatus::Active ? ['label' => __('proposals.extend_proposal'), 'call' => 'openExtensionModal'] : null, 'history');
        }

        if ($this->isBeforeSubmitted()) {
            $this->pushReadinessItems($push, $whenFor);
        }

        usort($items, fn (array $a, array $b): int => [array_search($a['cat'], self::ATTENTION_CATEGORIES, true), $b['late']] <=> [array_search($b['cat'], self::ATTENTION_CATEGORIES, true), $a['late']]);

        return $items;
    }

    /**
     * A fix button that only opens a view of the framework box (no write),
     * so everyone who sees the item gets it. The box listens for framework-go.
     *
     * @return array{label: string, call: string, go: true}
     */
    private static function frameworkGo(string $key, string $label): array
    {
        return ['label' => $label, 'call' => "\$dispatch('framework-go', { key: '{$key}' })", 'go' => true];
    }

    /**
     * Whether the proposal is still being written (before Submitted).
     */
    private function isBeforeSubmitted(): bool
    {
        $lifecycle = ProposalStatus::lifecycle();
        $position = array_search($this->proposal->status, $lifecycle, true);

        return $position !== false && $position < array_search(ProposalStatus::Submitted, $lifecycle, true);
    }

    /**
     * The "before it goes to review" items.
     *
     * @param  callable(string, string, bool, string, string, ?array<string, string>, string): void  $push
     * @param  callable(int): string  $whenFor
     */
    private function pushReadinessItems(callable $push, callable $whenFor): void
    {
        $proposal = $this->proposal;
        $before = __('proposals.attention.when_before_review');

        if (($returned = $this->returnedFromReview()) !== null) {
            $push('returned-'.$returned->id, 'ready', true, __('proposals.attention.returned', ['reason' => str((string) $returned->context)->squish()->limit(160)]), __('proposals.attention.when_sent_back'), null, 'status');
        }

        if (! $proposal->isPayroll()) {
            $task = app(SyncLogframeTask::class)->taskOf($proposal);

            if ($task !== null && $task->status !== TaskStatus::Done) {
                $days = $task->due_date === null ? 1 : (int) CarbonImmutable::today()->diffInDays($task->due_date, false);
                $push('framework-task', 'ready', $days < 0, __('proposals.attention.framework_task'), $task->due_date === null ? $before : $whenFor($days), ['label' => __('proposals.attention.fix_open_task'), 'url' => route('tasks.show', $task)], 'logframe');
            }
        }

        if ($proposal->requested_budget !== null && $proposal->requested_currency === null && $this->canViewAgreements()) {
            $push('no-currency', 'ready', true, __('proposals.attention.no_currency', ['amount' => number_format((float) $proposal->requested_budget, 0)]), $before, $proposal->status->isApprovedByDonor() ? null : ['label' => __('proposals.attention.fix_currency'), 'call' => 'openEditModal('.$proposal->id.')'], 'card');
        }

        if (! $proposal->isPayroll()) {
            $framework = $this->attentionFramework();

            if ($framework['indicatorCount'] > 0 && $framework['noTarget'] > 0) {
                $push('no-targets', 'ready', true, __('proposals.attention.no_targets', ['count' => $framework['noTarget'], 'total' => $framework['indicatorCount']]), $before, self::frameworkGo('tgt', __('proposals.attention.fix_set_targets')), 'logframe');
            }

            if ($framework['loose'] > 0) {
                $push('loose-activities', 'ready', true, trans_choice('proposals.attention.loose_activities', $framework['loose'], ['count' => $framework['loose']]), $before, self::frameworkGo('act', __('proposals.attention.fix_sort_them')), 'logframe');
            }

            if (! ProposalReviewer::query()->where('proposal_id', $proposal->id)->exists()) {
                $push('no-reviewers', 'ready', true, __('proposals.attention.no_reviewers'), $before, null, 'status');
            }

            if ($this->needAssessments->isEmpty()) {
                $push('no-need-assessment', 'ready', false, __('proposals.attention.no_need_assessment'), __('proposals.attention.when_recommended'), auth()->user()?->can('uploadNeedAssessment', $proposal) === true ? ['label' => __('proposals.attention.fix_need_assessment'), 'call' => 'openNeedAssessmentModal'] : null, 'status');
            }
        }

        if ($this->proposalFiles->isEmpty()) {
            $push('no-file', 'ready', true, __('proposals.attention.no_file'), $before, ['label' => __('proposals.attention.fix_upload'), 'call' => 'openCreateFileModal'], 'documents');
        }
    }

    /**
     * The status note that sent the proposal back from review to where it is
     * now (Concept note review → Concept note, Proposal review or Submitted →
     * Proposal draft), or null. Every such move requires a reason, kept as
     * the note's context. Only while the proposal still sits where it landed.
     */
    private function returnedFromReview(): ?ProposalStatusNote
    {
        $moves = $this->statusNotes->filter(fn (ProposalStatusNote $note): bool => $note->status !== null)->values();
        $latest = $moves->get(0);
        $previous = $moves->get(1);

        if ($latest === null || $previous === null || $latest->status !== $this->proposal->status) {
            return null;
        }

        $returnedFrom = match ($latest->status) {
            ProposalStatus::ConceptNote => [ProposalStatus::ConceptNoteReview],
            ProposalStatus::ProposalDraft => [ProposalStatus::ProposalReview, ProposalStatus::Submitted],
            default => [],
        };

        return in_array($previous->status, $returnedFrom, true) && trim((string) $latest->context) !== '' ? $latest : null;
    }

    /**
     * The framework counts the bar needs, in four queries.
     *
     * @return array{unmeasured: Collection<int, Result>, happened: Collection<int, Risk>, indicatorCount: int, noTarget: int, loose: int}
     */
    private function attentionFramework(): array
    {
        if ($this->attentionFrameworkMemo !== null) {
            return $this->attentionFrameworkMemo;
        }

        $results = Result::query()
            ->select(['id', 'proposal_id', 'parent_id', 'type', 'statement'])
            ->where('proposal_id', $this->proposalId)
            ->withCount('indicators')
            ->get();

        $indicators = Indicator::query()
            ->whereIn('result_id', $results->pluck('id'))
            ->get(['id', 'result_id', 'target']);

        return $this->attentionFrameworkMemo = [
            'unmeasured' => $results->filter(fn (Result $result): bool => in_array($result->type, [ResultType::Outcome, ResultType::Output], true) && $result->indicators_count === 0)->values(),
            'happened' => Risk::query()->select(['id', 'name', 'proposal_id'])->where('proposal_id', $this->proposalId)->where('happened', true)->get(),
            'indicatorCount' => $indicators->count(),
            'noTarget' => $indicators->whereNull('target')->count(),
            'loose' => $results->filter(fn (Result $result): bool => $result->type === ResultType::Activity && $result->parent_id === null)->count(),
        ];
    }
}
