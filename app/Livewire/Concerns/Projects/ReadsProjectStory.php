<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Desk\SendNudge;
use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Models\DonorProposal;
use App\Models\HrEmployee;
use App\Models\LibraryIndicator;
use App\Models\Media;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ReportTemplateLine;
use App\Models\Task;
use App\Support\Desk\Money;
use App\Support\Desk\ProjectStage;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The project Story (Tracking Desk stage 3): where the project stands on its
 * seven stages, its health, the one next step and who holds it, and a
 * summary line per chapter.
 *
 * Stage and health come from App\Support\Desk\ProjectStage, so the Story and
 * the Desk's Projects panel always agree. A proposal step links to the
 * Story's own Proposal block (stage 4: the proposal page is embedded there,
 * chosen by ?proposal= and opened on ?ptab=).
 *
 * Used by projects/show only. The page provides projectId, the #[Computed]
 * project(), attachedProposals(), fundingByCurrency() and canViewMoney().
 *
 * @property int $projectId
 * @property-read Collection<int, Proposal> $attachedProposals  #[Computed] attachedProposals()
 * @property-read int|null $embeddedProposalId  #[Computed] embeddedProposalId()
 * @property-read Project $project  #[Computed] project()
 * @property-read bool $canStartConceptNote  #[Computed] canStartConceptNote()
 * @property-read Collection<string, array{currency: Currency, committed: float, received: float}> $fundingByCurrency  #[Computed] fundingByCurrency()
 * @property-read Collection<int, Task> $tasks  #[Computed] tasks()
 * @property-read array{lead: Proposal|null, stage: int, health: string, donor: string, reports: array{validated: int, total: int}, next: array{text: string, owner: int|null, mine: bool, ownerName: string|null, meal: bool, nudge: bool, href: string|null, label: string|null}, chapters: list<array{state: string, summary: string, when: string|null}>, lede: string, months: list<array{label: string, title: string, state: string, href: string|null}>} $story  #[Computed] story()
 */
trait ReadsProjectStory
{
    /** The proposal shown in the Story's Proposal block ('' = the lead one). */
    #[Url(as: 'proposal', except: '')]
    public string $storyProposal = '';

    /**
     * Which proposal the Proposal block embeds: the one in ?proposal= when it
     * is linked to this project and the viewer may open it, else the lead
     * proposal, else the first linked one the viewer may open.
     */
    /**
     * Ids of the linked proposals the viewer may open (ProposalPolicy::view:
     * proposals.view, or named as a reviewer), in one query however many
     * proposals are linked.
     *
     * @return list<int>
     */
    #[Computed]
    public function viewableProposalIds(): array
    {
        $viewer = auth()->user();
        $ids = $this->attachedProposals->pluck('id')->map(fn (mixed $id): int => (int) $id);

        if ($viewer === null || $ids->isEmpty()) {
            return [];
        }

        if ($viewer->can('proposals.view')) {
            return array_values($ids->all());
        }

        $reviewing = $viewer->employee_id === null ? collect() : ProposalReviewer::query()
            ->whereIn('proposal_id', $ids->all())
            ->where('employee_id', $viewer->employee_id)
            ->pluck('proposal_id')
            ->map(fn (mixed $id): int => (int) $id);

        return array_values($ids->intersect($reviewing)->all());
    }

    #[Computed]
    public function embeddedProposalId(): ?int
    {
        $viewable = collect($this->viewableProposalIds);
        $chosen = ctype_digit($this->storyProposal) ? (int) $this->storyProposal : null;
        $leadId = $this->story['lead']?->id;

        return match (true) {
            $chosen !== null && $viewable->contains($chosen) => $chosen,
            $leadId !== null && $viewable->contains($leadId) => $leadId,
            default => $viewable->first(),
        };
    }

    /**
     * The Story opened on one proposal (and tab) in its Proposal block.
     */
    public function proposalStoryUrl(int $proposalId, ?string $tab = null): string
    {
        return route('projects.show', array_filter([
            'project' => $this->project,
            'proposal' => $proposalId,
            'ptab' => $tab,
        ], fn (mixed $value): bool => $value !== null)).'#proposal';
    }

    /**
     * @return array{lead: Proposal|null, stage: int, health: string, donor: string, reports: array{validated: int, total: int}, next: array{text: string, owner: int|null, mine: bool, ownerName: string|null, meal: bool, nudge: bool, href: string|null, label: string|null}, chapters: list<array{state: string, summary: string, when: string|null}>, lede: string, months: list<array{label: string, title: string, state: string, href: string|null}>}
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
            'months' => $this->monthsStrip($project),
        ];
    }

    /**
     * Nudge whoever holds the next step. The person is read back from
     * story(), never from the request.
     */
    public function nudgeNextStep(SendNudge $nudge, ?string $note = null): void
    {
        $next = $this->story['next'];

        if ($next['owner'] === null || ! $next['nudge']) {
            return;
        }

        $sent = $nudge->handle(auth()->user(), Project::query()->findOrFail($this->projectId), $next['owner'], $note);

        Flux::toast(text: $sent ? __('desk.nudged') : __('desk.nudged_already'), variant: $sent ? 'success' : null);
    }

    /**
     * The one thing that has to happen next, who holds it (an HR employee
     * id, null when it sits with MEAL or nobody) and the page that does it.
     *
     * @return array{text: string, owner: int|null, mine: bool, ownerName: string|null, meal: bool, nudge: bool, href: string|null, label: string|null}
     */
    private function nextStep(Project $project, ?Proposal $lead, int $stage, string $donor): array
    {
        $owner = $project->user_id;
        $meal = false;
        $href = null;
        $label = null;
        $nudge = true;
        $donorName = $donor !== '' ? $donor : (string) __('story.the_donor');
        $proposalLink = fn (string $tab): ?string => $lead !== null && auth()->user()?->can('view', $lead) ? $this->proposalStoryUrl($lead->id, $tab) : null;

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
                    $nudge = false;
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
            'nudge' => $nudge && $owner !== null && ! $mine,
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
            0 => [(string) __('story.done.project', ['program' => $this->project->program->name ?? '—', 'owner' => $this->project->user->name ?? '—']), $date($project->created_at)],
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
     * The running chapter's months strip (desk.js monthsStrip): one cell per
     * monthly report, oldest first, with its state.
     *
     * @return list<array{label: string, title: string, state: string, href: string|null}>
     */
    private function monthsStrip(Project $project): array
    {
        $monthStart = now()->startOfMonth();
        $viewer = auth()->user();

        return array_values($project->monthlyReports
            ->sortBy(fn (MonthlyReport $report): string => $report->period->toDateString())
            ->map(function (MonthlyReport $report) use ($monthStart, $viewer): array {
                $state = match (true) {
                    $report->status === MonthlyReportStatus::Validated => 'validated',
                    $report->status === MonthlyReportStatus::Submitted => 'submitted',
                    $report->returned_at !== null => 'returned',
                    $report->period->lt($monthStart) => 'late',
                    default => 'open',
                };

                return [
                    'label' => $report->period->translatedFormat('M'),
                    'title' => $report->period->translatedFormat('F Y').': '.__('story.month_state.'.$state),
                    'state' => $state,
                    'href' => $viewer?->can('view', $report) ? route('monthly-reports.show', $report) : null,
                ];
            })
            ->all());
    }

    /**
     * The plan chapter's details (desk.js planBlock, read only): what the
     * lead proposal plans, its budget for money holders, and its reviewers.
     *
     * @return array{indicators: int, targets: int, milestones: int, tasks: int, budget: list<string>|null, reviewers: list<array{name: string, slot: string, seen: bool}>}|null
     */
    #[Computed]
    public function planSummary(): ?array
    {
        $lead = $this->story['lead'];

        if ($lead === null) {
            return null;
        }

        $targets = PlanIndicator::query()
            ->where('source_type', $lead->getMorphClass())
            ->where('source_id', $lead->id)
            ->get(['id', 'indicator_id', 'target']);

        $budget = null;

        if ($this->canViewMoney() && $lead->relationLoaded('donorProposals')) {
            $budget = $lead->donorProposals
                ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
                ->map(fn (EloquentCollection $agreements, string $currency): string => Money::format((float) $agreements->sum('budget'), $currency))
                ->all();
            $budget = array_values($budget);
        }

        $rows = ProposalReviewer::query()
            ->select(['id', 'proposal_id', 'stage', 'employee_id', 'slot', 'seen_at'])
            ->where('proposal_id', $lead->id)
            ->where('stage', ReviewStage::Proposal->value)
            ->orderBy('id')
            ->get();
        $employees = $rows->isEmpty()
            ? collect()
            : HrEmployee::query()->whereKey($rows->pluck('employee_id')->unique()->values()->all())->get(HrEmployee::NameColumns)->keyBy('id');

        return [
            'indicators' => $targets->pluck('indicator_id')->unique()->count(),
            'targets' => $targets->whereNotNull('target')->count(),
            'milestones' => (int) $this->project->milestones_count,
            'tasks' => $this->tasks->count(),
            'budget' => $budget,
            'reviewers' => array_values($rows->map(fn (ProposalReviewer $row): array => [
                'name' => $employees->has($row->employee_id) ? (string) $employees[$row->employee_id]->name : '#'.$row->employee_id,
                'slot' => $row->slot->label(),
                'seen' => $row->seen_at !== null,
            ])->all()),
        ];
    }

    /**
     * The template chapter's lines (desk.js templateLines, read only): each
     * reporting line's indicator code, name and whether it is disaggregated.
     *
     * @return list<array{code: string, name: string, disaggregated: bool}>
     */
    #[Computed]
    public function templateLines(): array
    {
        return array_values(ReportTemplateLine::query()
            ->select(['report_template_lines.id', 'report_template_lines.report_template_id', 'report_template_lines.plan_indicator_id'])
            ->whereHas('template', fn ($query) => $query->where('project_id', $this->projectId))
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,code,name,library_indicator_id', 'planIndicator.indicator.libraryIndicator:id,is_disaggregated'])
            ->orderBy('report_template_lines.id')
            ->get()
            ->map(function (ReportTemplateLine $line): array {
                $indicator = $line->planIndicator->indicator;
                $library = $indicator->relationLoaded('libraryIndicator') ? $indicator->getRelation('libraryIndicator') : null;

                return [
                    'code' => (string) $indicator->code,
                    'name' => $indicator->name,
                    'disaggregated' => $library instanceof LibraryIndicator && $library->is_disaggregated,
                ];
            })
            ->all());
    }

    /**
     * The lead proposal's files for the concept note, decision and closing
     * chapters, keyed by chapter. Funding papers stay hidden from those
     * without funding-agreements.view (hiddenCollections()).
     *
     * @return array<int, list<string>>
     */
    #[Computed]
    public function chapterFiles(): array
    {
        $lead = $this->story['lead'];

        if ($lead === null) {
            return [];
        }

        $chapters = [
            1 => [MediaCollection::Proposal, MediaCollection::NeedAssessment],
            3 => [MediaCollection::DonorApproval, MediaCollection::Agreement, MediaCollection::Mou],
            6 => [MediaCollection::FinalReport, MediaCollection::FinalReportAcceptance],
        ];
        $hidden = $this->hiddenCollections();
        $wanted = [];

        foreach ($chapters as $collections) {
            foreach ($collections as $collection) {
                if (! in_array($collection->value, $hidden, true)) {
                    $wanted[] = $collection->value;
                }
            }
        }

        $media = Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name'])
            ->where('mediable_type', $lead->getMorphClass())
            ->where('mediable_id', $lead->id)
            ->whereIn('collection', $wanted)
            ->orderBy('id')
            ->get();

        $files = [];

        foreach ($chapters as $chapter => $collections) {
            $names = $media
                ->filter(fn (Media $file): bool => in_array($file->collection, $collections, true))
                ->map(fn (Media $file): string => (string) $file->original_name)
                ->all();
            $names = array_values($names);

            if ($names !== []) {
                $files[$chapter] = $names;
            }
        }

        return $files;
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
