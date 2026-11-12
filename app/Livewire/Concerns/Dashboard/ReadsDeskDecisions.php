<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Dashboard;

use App\Enums\DeliveryState;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProposalStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\HrEmployee;
use App\Models\MonthlyReport;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Task;
use App\Models\User;
use App\Support\ProposalStoryLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Needs you (user decision 2026-10-27): only what waits on the viewer's
 * decision and no other card owns. Reviews asked of them, monthly reports
 * waiting for their validation, tasks they created that were sent for
 * their review, and media deliverables they requested that were delivered
 * for their acceptance. The viewer's own work to do belongs to the Tasks
 * card and never appears here; risks are not decisions.
 *
 * A decision is late when its due date has passed or, with no due date,
 * when it has waited more than LateAfterDays. Used by the Needs you panel
 * and the Desk greeting, so both count the same things. The host provides
 * visible() and canView() (IsDeskPanel).
 *
 * @property-read Collection<int, array{key: string, kind: string, title: string, context: string, who: string, since: CarbonImmutable|null, due: CarbonImmutable|null, late: bool, href: string, action: string, taskId: int|null}> $decisions  #[Computed] decisions()
 */
trait ReadsDeskDecisions
{
    /**
     * An undated decision waiting longer than this many days is late.
     */
    private const int LateAfterDays = 7;

    /**
     * Every decision waiting on the viewer: late first, then oldest wait.
     *
     * @return Collection<int, array{key: string, kind: string, title: string, context: string, who: string, since: CarbonImmutable|null, due: CarbonImmutable|null, late: bool, href: string, action: string, taskId: int|null}>
     */
    #[Computed]
    public function decisions(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            return new Collection;
        }

        $reviews = ProposalReviewer::query()
            ->when($user->employee_id === null, fn (Builder $query) => $query->whereRaw('1 = 0'), fn (Builder $query) => $query->forEmployee((int) $user->employee_id))
            ->awaitingReview()
            ->with(['proposal:id,slug,name,status', 'proposal.projects:projects.id,projects.slug', 'addedBy:id,employee_id,hr_user_id'])
            ->oldest('id')
            ->get()
            ->unique('proposal_id')
            ->map(fn (ProposalReviewer $review): array => $this->decision(
                key: 'review-'.$review->id,
                kind: 'review',
                title: $review->proposal->name,
                context: __('desk.decision.review', ['stage' => $review->stage->label()]),
                who: $review->addedBy?->name ?? '',
                since: $review->created_at,
                due: null,
                href: ProposalStoryLink::review($user, $review->proposal),
            ));

        $reports = $this->visible(MonthlyReport::query(), 'reports')
            ->validatableBy($user)
            ->where('status', MonthlyReportStatus::Submitted)
            ->with(['project:id,name', 'preparer:id,employee_id,hr_user_id'])
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (MonthlyReport $report): array => $this->decision(
                key: 'report-'.$report->id,
                kind: 'validate',
                title: $report->project?->name ?? '—',
                context: __('desk.decision.validate', ['month' => $report->period->translatedFormat('F Y')]),
                who: $report->preparer?->name ?? '',
                since: $report->submitted_at,
                due: null,
                href: route('monthly-reports.show', $report),
            ));

        $tasks = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'slug', 'title', 'kind', 'status', 'delivery_state', 'due_date', 'delivered_on', 'updated_at', 'created_by'])
            ->with('taskAssignees:id,task_id,employee_id')
            ->where('created_by', $user->id)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('status', TaskStatus::UnderReview)->where(fn (Builder $query) => $query->whereNull('kind')->orWhere('kind', '!=', TaskKind::MediaDeliverable)))
                ->orWhere(fn (Builder $query) => $query->where('kind', TaskKind::MediaDeliverable)->where('delivery_state', DeliveryState::Delivered)))
            ->get();

        $names = HrEmployee::query()->whereKey($tasks->pluck('taskAssignees')->flatten()->pluck('employee_id')->unique()->all())
            ->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        $tasks = $tasks
            ->reject(fn (Task $task): bool => $task->taskAssignees->pluck('employee_id')->all() === [$user->employee_id])
            ->map(function (Task $task) use ($names): array {
                $deliverable = $task->kind === TaskKind::MediaDeliverable;

                return $this->decision(
                    key: 'task-'.$task->id,
                    kind: $deliverable ? 'accept' : 'approve',
                    title: $task->title,
                    context: __($deliverable ? 'desk.decision.accept' : 'desk.decision.approve'),
                    who: $task->taskAssignees->map(fn ($assignee): string => (string) $names->get($assignee->employee_id, ''))->filter()->implode(', '),
                    since: $deliverable ? $task->delivered_on : $task->updated_at,
                    due: $task->due_date,
                    href: route('tasks.show', $task),
                    taskId: $task->id,
                );
            });

        $proposals = $this->ownerProposalSteps($user);

        return $reviews->concat($reports)->concat($tasks)->concat($proposals)
            ->sortBy([
                fn (array $a, array $b): int => $b['late'] <=> $a['late'],
                fn (array $a, array $b): int => ($a['since']?->timestamp ?? PHP_INT_MAX) <=> ($b['since']?->timestamp ?? PHP_INT_MAX),
            ])
            ->values();
    }

    /**
     * Proposals of the viewer's own projects that wait on them (user decision
     * 2026-11-12: the project owner is its project manager): a concept note
     * or a proposal still being written, or in review and ready to move on.
     *
     * @return Collection<int, array{key: string, kind: string, title: string, context: string, who: string, since: CarbonImmutable|null, due: CarbonImmutable|null, late: bool, href: string, action: string, taskId: int|null}>
     */
    private function ownerProposalSteps(User $user): Collection
    {
        if ($user->employee_id === null) {
            return new Collection;
        }

        $steps = [
            ProposalStatus::ConceptNote->value => 'finish_concept_note',
            ProposalStatus::ConceptNoteReview->value => 'collect_concept_reviews',
            ProposalStatus::ProposalDraft->value => 'finish_proposal',
            ProposalStatus::ProposalReview->value => 'send_proposal',
        ];

        return Proposal::query()
            ->select(['proposals.id', 'proposals.slug', 'proposals.name', 'proposals.status', 'proposals.updated_at'])
            ->whereIn('proposals.status', array_keys($steps))
            ->whereHas('projects', fn (Builder $query) => $query->where('projects.user_id', $user->employee_id))
            ->oldest('proposals.updated_at')
            ->get()
            ->map(fn (Proposal $proposal): array => $this->decision(
                key: 'proposal-'.$proposal->id,
                kind: 'proposal',
                title: $proposal->name,
                context: __('desk.decision.proposal.'.$steps[$proposal->status->value]),
                who: '',
                since: $proposal->updated_at,
                due: null,
                href: route('proposals.show', $proposal),
            ));
    }

    /**
     * The decisions one view shows: 'week' (late, due by the end of this
     * week, or undated), 'late', or 'all'.
     *
     * @return Collection<int, array{key: string, kind: string, title: string, context: string, who: string, since: CarbonImmutable|null, due: CarbonImmutable|null, late: bool, href: string, action: string, taskId: int|null}>
     */
    protected function decisionsIn(string $view): Collection
    {
        $weekEnd = CarbonImmutable::today()->endOfWeek();

        return match ($view) {
            'late' => $this->decisions->where('late', true)->values(),
            'all' => $this->decisions,
            default => $this->decisions->filter(fn (array $item): bool => $item['late'] || $item['due'] === null || $item['due']->lessThanOrEqualTo($weekEnd))->values(),
        };
    }

    /**
     * @return array{key: string, kind: string, title: string, context: string, who: string, since: CarbonImmutable|null, due: CarbonImmutable|null, late: bool, href: string, action: string, taskId: int|null}
     */
    private function decision(string $key, string $kind, string $title, string $context, string $who, mixed $since, mixed $due, string $href, ?int $taskId = null): array
    {
        $today = CarbonImmutable::today();
        $since = $since === null ? null : CarbonImmutable::parse($since);
        $due = $due === null ? null : CarbonImmutable::parse($due)->startOfDay();

        return [
            'key' => $key,
            'kind' => $kind,
            'title' => $title,
            'context' => $context,
            'who' => $who,
            'since' => $since,
            'due' => $due,
            'late' => $due !== null ? $due->lessThan($today) : ($since !== null && $since->startOfDay()->diffInDays($today) > self::LateAfterDays),
            'href' => $href,
            'action' => __('desk.decision_action.'.$kind),
            'taskId' => $taskId,
        ];
    }
}
