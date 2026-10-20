<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Desk\SendNudge;
use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\Desk\Money;
use App\Support\Desk\ProjectStage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The project Story (Tracking Desk stage 3): where the project stands on its
 * seven stages, its health, the one next step and who holds it, and a
 * summary line per chapter.
 *
 * Stage and health come from App\Support\Desk\ProjectStage, so the Story and
 * the Desk's Projects panel always agree. Editing stays on the existing
 * screens until the side sheets arrive (stage 4): every next step links to
 * the page that does it.
 *
 * Used by projects/show only. The page provides projectId, the #[Computed]
 * project(), fundingByCurrency() and canViewMoney().
 *
 * @property int $projectId
 * @property-read Project $project  #[Computed] project()
 * @property-read bool $canStartConceptNote  #[Computed] canStartConceptNote()
 * @property-read Collection<string, array{currency: Currency, committed: float, received: float}> $fundingByCurrency  #[Computed] fundingByCurrency()
 * @property-read array{lead: Proposal|null, stage: int, health: string, donor: string, reports: array{validated: int, total: int}, next: array{text: string, owner: int|null, mine: bool, ownerName: string|null, meal: bool, href: string|null, label: string|null}, chapters: list<array{state: string, summary: string, when: string|null}>, lede: string} $story  #[Computed] story()
 */
trait ReadsProjectStory
{
    /**
     * @return array{lead: Proposal|null, stage: int, health: string, donor: string, reports: array{validated: int, total: int}, next: array{text: string, owner: int|null, mine: bool, ownerName: string|null, meal: bool, href: string|null, label: string|null}, chapters: list<array{state: string, summary: string, when: string|null}>, lede: string}
     */
    #[Computed]
    public function story(): array
    {
        $project = Project::query()
            ->select(['id', 'status', 'start_date', 'end_date', 'user_id', 'created_at'])
            ->with([
                'proposals:proposals.id,proposals.name,proposals.status,proposals.created_at',
                'proposals.donorNames',
                'milestones' => fn ($query) => $query->select(['id', 'project_id', 'name', 'planned_end_date', 'status'])->whereIn('status', [MilestoneStatus::Planned, MilestoneStatus::Ongoing]),
                'monthlyReports:id,project_id,period,status,returned_at',
                ...($this->canViewMoney() ? ['proposals.donorProposals:id,proposal_id,budget,currency', 'proposals.donorProposals.transfers'] : []),
            ])
            ->findOrFail($this->projectId);

        $lead = ProjectStage::lead($project->proposals);
        $stage = ProjectStage::stage($project, $lead);
        $health = ProjectStage::health($stage, ProjectStage::signals($project));
        $donor = $lead?->donorNames->pluck('name')->implode(', ') ?? '';
        $reports = [
            'validated' => $project->monthlyReports->where('status', MonthlyReportStatus::Validated)->count(),
            'total' => $project->monthlyReports->count(),
        ];

        return [
            'lead' => $lead,
            'stage' => $stage,
            'health' => $health,
            'donor' => $donor,
            'reports' => $reports,
            'next' => $this->nextStep($project, $lead, $stage, $donor),
            'chapters' => $this->chapters($project, $lead, $stage, $donor, $reports),
            'lede' => $this->storyLede($reports),
        ];
    }

    /**
     * Nudge whoever holds the next step. The person is read back from
     * story(), never from the request.
     */
    public function nudgeNextStep(SendNudge $nudge): void
    {
        $next = $this->story['next'];

        if ($next['owner'] === null || $next['mine']) {
            return;
        }

        $sent = $nudge->handle(auth()->user(), Project::query()->findOrFail($this->projectId), $next['owner']);

        Flux::toast(text: $sent ? __('desk.nudged') : __('desk.nudged_already'), variant: $sent ? 'success' : null);
    }

    /**
     * The one thing that has to happen next, who holds it (an HR employee
     * id, null when it sits with MEAL or nobody) and the page that does it.
     *
     * @return array{text: string, owner: int|null, mine: bool, ownerName: string|null, meal: bool, href: string|null, label: string|null}
     */
    private function nextStep(Project $project, ?Proposal $lead, int $stage, string $donor): array
    {
        $owner = $project->user_id;
        $meal = false;
        $href = null;
        $label = null;
        $donorName = $donor !== '' ? $donor : (string) __('story.the_donor');
        $proposalLink = fn (string $tab): ?string => $lead !== null && auth()->user()?->can('view', $lead) ? route('proposals.show', ['proposal' => $lead, 'tab' => $tab]) : null;

        switch ($stage) {
            case 0:
                $text = (string) __('story.next.start_concept_note');
                $href = $this->canStartConceptNote ? route('proposals.index', ['for_project' => $project->id]) : null;
                $label = (string) __('proposals.new_concept_note');
                break;
            case 1:
                $text = match ($lead->status) {
                    ProposalStatus::ConceptNoteReview => (string) __('story.next.collect_concept_reviews'),
                    ProposalStatus::ConceptNoteSubmitted => (string) __('story.next.wait_concept_answer', ['donor' => $donorName]),
                    default => (string) __('story.next.finish_concept_note'),
                };
                $href = $proposalLink('status');
                break;
            case 2:
                $text = $lead->status === ProposalStatus::ProposalReview
                    ? (string) __('story.next.send_proposal', ['donor' => $donorName])
                    : (string) __('story.next.finish_proposal');
                $href = $proposalLink('status');
                break;
            case 3:
                if ($lead->status === ProposalStatus::Approved) {
                    $text = (string) __('story.next.acknowledge_dates');
                } elseif ($lead->status === ProposalStatus::Rejected) {
                    $text = (string) __('story.next.after_rejection');
                } else {
                    $text = (string) __('story.next.record_decision', ['donor' => $donorName]);
                    $href = $proposalLink('status');
                }
                break;
            case 4:
                $text = (string) __('story.next.confirm_template');
                $owner = null;
                $meal = true;
                $href = route('reporting-duties.index');
                break;
            case 5:
                [$text, $owner, $href, $meal] = $this->runningStep($project);
                break;
            case 6:
                $text = (string) __('story.next.final_report');
                $href = $proposalLink('reports');
                break;
            default:
                $text = $project->status === ProjectStatus::Archived ? (string) __('story.next.archived') : (string) __('story.next.closed');
                $owner = null;
        }

        $label ??= $href !== null ? (string) __('story.go_to_it') : null;
        $mine = $owner !== null && $owner === auth()->user()?->employee_id;

        return [
            'text' => $text,
            'owner' => $owner,
            'mine' => $mine,
            'ownerName' => $owner !== null ? $this->project->user?->name : null,
            'meal' => $meal,
            'href' => $href,
            'label' => $label,
        ];
    }

    /**
     * While running, the next step is the oldest report that needs someone:
     * a late one to submit, a returned one to fix, a submitted one for MEAL.
     *
     * @return array{0: string, 1: int|null, 2: string|null, 3: bool}
     */
    private function runningStep(Project $project): array
    {
        $reports = $project->monthlyReports->sortBy(fn (MonthlyReport $report): string => $report->period->toDateString());
        $monthStart = now()->startOfMonth();
        $link = fn (MonthlyReport $report): ?string => auth()->user()?->can('view', $report) ? route('monthly-reports.show', $report) : null;

        $late = $reports->first(fn (MonthlyReport $report): bool => $report->status === MonthlyReportStatus::Draft && $report->period->lt($monthStart));

        if ($late !== null) {
            return [(string) __('story.next.submit_report', ['month' => $late->period->translatedFormat('F Y')]), $project->user_id, $link($late), false];
        }

        $returned = $reports->first(fn (MonthlyReport $report): bool => $report->status === MonthlyReportStatus::Draft && $report->returned_at !== null);

        if ($returned !== null) {
            return [(string) __('story.next.fix_report', ['month' => $returned->period->translatedFormat('F Y')]), $project->user_id, $link($returned), false];
        }

        $submitted = $reports->firstWhere('status', MonthlyReportStatus::Submitted);

        if ($submitted !== null) {
            return [(string) __('story.next.validate_report', ['month' => $submitted->period->translatedFormat('F Y')]), null, $link($submitted), true];
        }

        $milestone = $project->milestones->filter(fn ($milestone): bool => $milestone->planned_end_date !== null)->sortBy('planned_end_date')->first();

        if ($milestone !== null) {
            return [(string) __('story.next.reach_milestone', ['name' => $milestone->name, 'date' => $milestone->planned_end_date->translatedFormat(__('formats.date'))]), $project->user_id, auth()->user()?->can('milestones.view') ? route('milestones.show', $milestone) : null, false];
        }

        return [(string) __('story.next.nothing_waiting'), null, null, false];
    }

    /**
     * One line per stage: what happened (done), what happens here (now and later).
     *
     * @param  array{validated: int, total: int}  $reports
     * @return list<array{state: string, summary: string, when: string|null}>
     */
    private function chapters(Project $project, ?Proposal $lead, int $stage, string $donor, array $reports): array
    {
        $donorName = $donor !== '' ? $donor : (string) __('story.the_donor');
        $date = fn ($value): ?string => $value?->translatedFormat(__('formats.date'));
        $done = [
            0 => [(string) __('story.done.project', ['program' => $this->project->program->name, 'owner' => $this->project->user->name ?? '—']), $date($project->created_at)],
            1 => [(string) __('story.done.concept_note', ['donor' => $donorName]), null],
            2 => [(string) __('story.done.proposal', ['name' => $lead->name ?? '—']), null],
            3 => [$project->start_date === null
                ? (string) __('story.done.decision_no_dates', ['donor' => $donorName])
                : (string) __('story.done.decision', ['donor' => $donorName, 'start' => $date($project->start_date), 'end' => $date($project->end_date) ?? '…']), null],
            4 => [(string) __('story.done.template'), null],
            5 => [(string) __('story.done.running', ['validated' => $reports['validated'], 'total' => $reports['total']]), $date($project->end_date)],
            6 => [(string) __('story.done.closing'), null],
        ];
        $chapters = [];

        foreach (range(0, 6) as $i) {
            $state = $i < $stage ? 'done' : ($i === $stage ? 'now' : 'later');
            $chapters[] = [
                'state' => $state,
                'summary' => $state === 'done' ? $done[$i][0] : (string) __('story.later.'.$i),
                'when' => $state === 'done' ? $done[$i][1] : null,
            ];
        }

        return $chapters;
    }

    /**
     * The sentence under the title: status, results so far, money in.
     *
     * @param  array{validated: int, total: int}  $reports
     */
    private function storyLede(array $reports): string
    {
        $parts = [$this->project->status->label().'.'];

        if ($reports['total'] > 0) {
            $parts[] = (string) __('story.lede_reports', ['validated' => $reports['validated'], 'total' => $reports['total']]);
        }

        $money = $this->canViewMoney() ? $this->fundingByCurrency->first() : null;

        if ($money !== null && (float) $money['committed'] > 0) {
            $parts[] = (string) __('story.lede_money', [
                'received' => Money::format((float) $money['received'], $money['currency']->value),
                'committed' => Money::format((float) $money['committed'], $money['currency']->value),
            ]);
        }

        return implode(' ', $parts);
    }
}
