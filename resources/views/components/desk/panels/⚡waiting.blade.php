<?php

use App\Actions\Desk\SendNudge;
use App\Enums\DeliveryState;
use App\Enums\MonthlyReportStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Task;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Waiting on others: work the viewer handed on that is stuck with someone
 * else (user decision, .ai/rules/desk.md) — tasks they created for others
 * that are past due, monthly reports they submitted that MEAL has not
 * validated, reviewers who have not opened their proposals, and
 * deliverables their projects requested that are not delivered yet.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * Each row carries the record a nudge is about and the people it may reach.
     *
     * @return Collection<int, array{key: string, text: string, sub: string, href: string, date: CarbonImmutable|null, subject: Task|MonthlyReport|Proposal|null, targets: list<int>}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $user = auth()->user();
        $today = CarbonImmutable::today();
        $format = __('desk.date_short');
        $myProjectIds = fn (): Builder => Project::query()->select('id')->where('user_id', $user->employee_id ?? 0);

        $tasks = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'slug', 'title', 'due_date', 'source_type', 'source_id'])
            ->where('created_by', $user->id)
            ->whereNull('department_id')
            ->where('status', '!=', TaskStatus::Done)
            ->whereDate('due_date', '<', $today)
            ->whereHas('taskAssignees', fn (Builder $query) => $query->where('employee_id', '!=', $user->employee_id ?? 0))
            ->with('taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar')
            ->get()
            ->map(fn (Task $task): array => [
                'key' => 'task-'.$task->id,
                'text' => __('desk.waiting_task', ['task' => $task->title, 'people' => $task->taskAssignees->map(fn ($assignee) => $assignee->employee?->name)->filter()->implode(', ')]),
                'sub' => __('desk.was_due', ['date' => $task->due_date->translatedFormat($format)]),
                'href' => route('tasks.show', $task),
                'date' => $task->due_date,
                'subject' => $task,
                'targets' => $this->others($task->taskAssignees->pluck('employee_id')->all()),
            ]);

        $reports = $this->visible(MonthlyReport::query(), 'reports')
            ->select(['id', 'slug', 'project_id', 'period', 'submitted_at'])
            ->where('submitted_by', $user->id)
            ->where('status', MonthlyReportStatus::Submitted)
            ->with('project')
            ->get()
            ->map(fn (MonthlyReport $report): array => [
                'key' => 'monthly-report-'.$report->id,
                'text' => __('desk.waiting_report', ['project' => $report->project?->name ?? '—', 'month' => $report->period->translatedFormat('F')]),
                'sub' => $report->submitted_at === null ? '' : __('desk.sent_on', ['date' => $report->submitted_at->translatedFormat($format)]),
                'href' => route('monthly-reports.show', $report),
                'date' => $report->submitted_at?->toImmutable(),
                'subject' => $report,
                'targets' => $this->others($report->project === null ? [] : [MealCoverage::responsibleFor($report->project)]),
            ]);

        $reviews = ProposalReviewer::query()
            ->whereNull('seen_at')
            ->whereIn('proposal_id', DB::table('project_proposal')->select('proposal_id')->whereIn('project_id', $myProjectIds()))
            ->with('proposal')
            ->get();
        $reviewerNames = HrEmployee::query()->whereKey($reviews->pluck('employee_id')->unique()->values()->all())->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);
        $reviews = $reviews->map(fn (ProposalReviewer $review): array => [
                'key' => 'review-'.$review->id,
                'text' => __('desk.waiting_review', ['person' => $reviewerNames->get($review->employee_id, '—'), 'proposal' => $review->proposal?->name ?? '—']),
                'sub' => $review->stage->label(),
                'href' => $review->proposal === null ? route('reviews.index') : route('proposals.show', $review->proposal),
                'date' => $review->created_at,
                'subject' => $review->proposal,
                'targets' => $review->proposal === null ? [] : $this->others([$review->employee_id]),
            ]);

        $deliverables = $this->visible(Task::query(), 'tasks')
            ->select(['id', 'slug', 'title', 'kind', 'due_date', 'delivery_state'])
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->where('status', '!=', TaskStatus::Done)
            ->where(fn (Builder $query) => $query->whereNull('delivery_state')->orWhereIn('delivery_state', [DeliveryState::Requested, DeliveryState::Returned]))
            ->where('source_type', 'milestone')
            ->whereIn('source_id', Milestone::query()->select('id')->whereIn('project_id', $myProjectIds()))
            ->with('taskAssignees:id,task_id,employee_id')
            ->get()
            ->map(fn (Task $task): array => [
                'key' => 'deliverable-'.$task->id,
                'text' => __('desk.waiting_deliverable', ['task' => $task->title]),
                'sub' => $task->kind->label().($task->due_date === null ? '' : ' · '.__('desk.due_on', ['date' => $task->due_date->translatedFormat($format)])),
                'href' => route('tasks.show', $task),
                'date' => $task->due_date,
                'subject' => $task,
                'targets' => $this->others($task->taskAssignees->pluck('employee_id')->all()),
            ]);

        return $tasks->concat($reports)->concat($reviews)->concat($deliverables)
            ->sortBy(fn (array $row): string => $row['date']?->toDateString() ?? '9999')
            ->values();
    }

    /**
     * Nudge everyone a row may reach. The row and its people are read back
     * from rows(), never from the request, so a tampered key nudges nobody.
     */
    public function nudge(string $key, SendNudge $nudge): void
    {
        $row = $this->rows->firstWhere('key', $key);

        if ($row === null || $row['subject'] === null) {
            return;
        }

        $sent = 0;

        foreach ($row['targets'] as $employeeId) {
            $sent += $nudge->handle(auth()->user(), $row['subject'], $employeeId) ? 1 : 0;
        }

        Flux::toast(text: $sent > 0 ? __('desk.nudged') : __('desk.nudged_already'), variant: $sent > 0 ? 'success' : null);
    }

    /**
     * @param  array<int, mixed>  $employeeIds
     * @return list<int>
     */
    private function others(array $employeeIds): array
    {
        $mine = auth()->user()->employee_id;

        return array_values(array_unique(array_filter(array_map(fn (mixed $id): int => (int) $id, $employeeIds), fn (int $id): bool => $id > 0 && $id !== $mine)));
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="waiting" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-wait">
            @foreach ($pg['slice'] as $row)
                <li wire:key="{{ $row['key'] }}">
                    <div><p><a href="{{ $row['href'] }}" wire:navigate>{{ $row['text'] }}</a></p>@if ($row['sub'] !== '')<p class="dk-is">{{ $row['sub'] }}</p>@endif</div>
                    @if ($row['targets'] !== [])
                        <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="nudge('{{ $row['key'] }}')"><x-desk.icon name="bell" />{{ __('desk.nudge') }}</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.waiting.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
