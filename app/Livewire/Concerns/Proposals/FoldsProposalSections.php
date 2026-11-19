<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;

/**
 * "More about this proposal" on proposals/show: the folded sections that
 * replaced the tab strip on the proposal's own page (stage 3 of the
 * redesign). Every section starts folded; which ones are open is kept per
 * person in users.page_prefs. A section's body renders only while it is
 * open, so a folded section reads nothing heavy.
 *
 * An old `?ptab=` link (or setting `tab`) opens the matching section, so
 * deep links from notifications, the Desk and other pages keep working.
 * The embedded view (project Story) keeps its tabs.
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read list<array{key: string, tab: string, icon: string, title: string, summary: string, flag: string|null}> $sections  #[Computed] sections()
 */
trait FoldsProposalSections
{
    /** Section key => the old tab it replaces. */
    public const SECTION_TABS = [
        'money' => 'funding',
        'coming' => 'deadlines',
        'projects' => 'projects',
        'plan' => 'plan',
        'reports' => 'reports',
        'targets' => 'targets',
        'docs' => 'documents',
        'history' => 'status',
    ];

    /** The users.page_prefs key of the open sections. */
    private const SECTIONS_PREF = 'proposal_sections';

    /** @var list<string> */
    public array $openSections = [];

    /**
     * Load the person's open sections, or the one an old tab link asked for.
     */
    public function mountFoldsProposalSections(): void
    {
        $asked = request()->query('ptab', request()->query('tab'));

        if (is_string($asked) && $asked !== '' && ($key = array_search($asked === 'tasks' ? 'plan' : $asked, self::SECTION_TABS, true)) !== false) {
            $this->openSections = [$key];

            return;
        }

        $saved = auth()->user()?->page_prefs[self::SECTIONS_PREF] ?? [];
        $this->openSections = array_values(array_intersect(is_array($saved) ? $saved : [], array_keys(self::SECTION_TABS)));
    }

    public function toggleSection(string $key): void
    {
        if (! array_key_exists($key, self::SECTION_TABS)) {
            return;
        }

        $this->openSections = in_array($key, $this->openSections, true)
            ? array_values(array_diff($this->openSections, [$key]))
            : [...$this->openSections, $key];
        $this->saveSections();
    }

    public function openAllSections(): void
    {
        $this->openSections = array_column($this->sections, 'key');
        $this->saveSections();
    }

    public function closeAllSections(): void
    {
        $this->openSections = [];
        $this->saveSections();
    }

    /**
     * Open one section (Show me, or an old tab being set) and scroll to it.
     */
    public function openSection(string $key): void
    {
        if (! array_key_exists($key, self::SECTION_TABS)) {
            return;
        }

        if (! in_array($key, $this->openSections, true)) {
            $this->openSections[] = $key;
        }

        $this->dispatch('pp-flash', target: 'sec-'.$key);
    }

    /**
     * The sections this proposal shows, with their one-line summaries.
     *
     * @return list<array{key: string, tab: string, icon: string, title: string, summary: string, flag: string|null}>
     */
    #[Computed]
    public function sections(): array
    {
        $proposal = $this->proposal;
        $payroll = $proposal->isPayroll();
        $today = CarbonImmutable::today();
        $list = [];

        if ($this->canViewAgreements()) {
            $money = $this->moneyByCurrency;
            $list[] = [
                'key' => 'money', 'icon' => 'banknotes', 'title' => __('proposals.sections.money'),
                'summary' => $money === []
                    ? __('proposals.sections.money_none')
                    : collect($money)->map(fn (array $line, string $currency): string => __('proposals.sections.money_line', ['received' => number_format($line['received'], 0), 'committed' => number_format($line['committed'], 0), 'currency' => $currency]))->implode(' · '),
                'flag' => collect($money)->contains(fn (array $line): bool => $line['overdueCount'] > 0 || $line['failedCount'] > 0) ? __('proposals.sections.flag_money_late') : null,
            ];
        }

        $upcoming = array_values(array_filter($this->deadlines, fn (array $item): bool => $item['days'] >= 0));
        $list[] = [
            'key' => 'coming', 'icon' => 'calendar-days', 'title' => __('proposals.sections.coming'),
            'summary' => $upcoming === []
                ? __('proposals.sections.coming_none')
                : __('proposals.sections.coming_next', ['item' => str($upcoming[0]['title'])->limit(40), 'date' => $upcoming[0]['date']->translatedFormat(__('formats.date_short'))])
                    .(count($upcoming) > 1 ? ' · '.trans_choice('proposals.sections.n_more', count($upcoming) - 1, ['count' => count($upcoming) - 1]) : ''),
            'flag' => null,
        ];

        if (! $payroll) {
            $grantEnd = $proposal->effectiveDateEnd() ?? $proposal->planned_date_end;
            $outrun = $this->projects->contains(fn ($project): bool => $grantEnd !== null && $project->end_date !== null && $project->end_date->gt($grantEnd));
            $missingCentre = collect($this->locationRows)->contains('status', 'missing_center');
            $list[] = [
                'key' => 'projects', 'icon' => 'map-pin', 'title' => __('proposals.sections.projects'),
                'summary' => trans_choice('proposals.sections.n_projects', $this->projects->count(), ['count' => $this->projects->count()]).' · '.trans_choice('proposals.sections.n_locations', count($this->locationRows), ['count' => count($this->locationRows)]),
                'flag' => $outrun || $missingCentre ? __('proposals.sections.flag_needs_fix') : null,
            ];

            $plan = $this->planTaskRows;
            $list[] = [
                'key' => 'plan', 'icon' => 'user-group', 'title' => __('proposals.sections.plan'),
                'summary' => $plan->isEmpty()
                    ? __('proposals.sections.plan_none')
                    : __('proposals.sections.plan_line', ['tasks' => trans_choice('proposals.sections.n_tasks', $plan->count(), ['count' => $plan->count()]), 'done' => $plan->filter(fn ($task): bool => $task->status === TaskStatus::Done)->count()]),
                'flag' => $plan->contains(fn ($task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt($today)) ? __('proposals.sections.flag_late_tasks') : null,
            ];

            $owed = collect($this->deadlines)->whereIn('kind', ['report', 'donor-report']);
            $late = $owed->where('state', 'overdue')->count();
            $total = $this->reports->count() + $this->donorReports->count();
            $list[] = [
                'key' => 'reports', 'icon' => 'document-text', 'title' => __('proposals.sections.reports'),
                'summary' => $total === 0 ? __('proposals.sections.reports_none') : __('proposals.sections.reports_line', ['filed' => max(0, $total - $owed->count()), 'owed' => $owed->count()]),
                'flag' => $late > 0 ? trans_choice('proposals.sections.flag_late', $late, ['count' => $late]) : null,
            ];

            $list[] = [
                'key' => 'targets', 'icon' => 'flag', 'title' => __('proposals.tab_targets'),
                'summary' => trans_choice('proposals.sections.n_targets', $this->targets->count(), ['count' => $this->targets->count()]),
                'flag' => null,
            ];
        }

        $files = $this->proposalFiles->count();
        $list[] = [
            'key' => 'docs', 'icon' => 'folder', 'title' => __('proposals.sections.docs'),
            'summary' => $files === 0 ? __('proposals.sections.docs_none') : trans_choice('proposals.sections.n_files', $files, ['count' => $files]),
            'flag' => null,
        ];

        $latest = $this->statusNotes->first(fn ($note): bool => $note->status !== null);
        $myReview = collect($this->reviewStages)->contains(fn (array $stage): bool => collect($stage['rows'])->contains(fn (array $row): bool => $row['isMine'] && $row['seenAt'] === null));
        $noReviewers = ! $payroll && collect($this->reviewStages)->every(fn (array $stage): bool => $stage['rows'] === []);
        $list[] = [
            'key' => 'history', 'icon' => 'clock', 'title' => __('proposals.sections.history'),
            'summary' => ($latest === null ? $proposal->statusLabel() : __('proposals.sections.history_line', ['status' => $proposal->statusLabel(), 'date' => $latest->date?->translatedFormat(__('formats.date'))]))
                .($payroll ? '' : ' · '.trans_choice('proposals.sections.n_review_stages', count($this->reviewStages), ['count' => count($this->reviewStages)])),
            'flag' => $myReview ? __('proposals.sections.flag_your_review') : ($noReviewers && in_array($proposal->status, [ProposalStatus::ConceptNoteReview, ProposalStatus::ProposalReview, ProposalStatus::ProposalDraft, ProposalStatus::ConceptNote], true) ? __('proposals.sections.flag_no_reviewers') : null),
        ];

        foreach ($list as $i => $section) {
            $list[$i]['tab'] = self::SECTION_TABS[$section['key']];
        }

        return $list;
    }

    private function saveSections(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $prefs = $user->page_prefs ?? [];
        $prefs[self::SECTIONS_PREF] = $this->openSections;
        $user->forceFill(['page_prefs' => $prefs])->saveQuietly();
    }
}
