<?php

use App\Actions\Desk\SendNudge;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\MonthlyReport;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\Task;
use App\Support\Desk\DeskWork;
use App\Support\MealCoverage;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
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
        $format = __('desk.date_short');

        $tasks = DeskWork::waitingTasks($user)
            ->select(['id', 'slug', 'title', 'due_date', 'source_type', 'source_id'])
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

        $reports = DeskWork::waitingReports($user)
            ->select(['id', 'slug', 'project_id', 'period', 'submitted_at'])
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

        $reviews = DeskWork::waitingReviews($user)
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

        $deliverables = DeskWork::waitingDeliverables($user)
            ->select(['id', 'slug', 'title', 'kind', 'due_date', 'delivery_state'])
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
    public function nudge(string $key, SendNudge $nudge, ?string $note = null): void
    {
        $row = $this->rows->firstWhere('key', $key);

        if ($row === null || $row['subject'] === null) {
            return;
        }

        $sent = 0;

        foreach ($row['targets'] as $employeeId) {
            $sent += $nudge->handle(auth()->user(), $row['subject'], $employeeId, $note) ? 1 : 0;
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
    $today = CarbonImmutable::today();
    $icons = ['task' => 'check', 'monthly-report' => 'file', 'review' => 'search', 'deliverable' => 'board'];
@endphp

<x-desk.panel :facets="$pg['facets']" id="waiting" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-wait" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $row)
                @php
                    $kind = \Illuminate\Support\Str::beforeLast($row['key'], '-');
                    $waited = $row['date'] !== null && $row['date']->lt($today) ? (int) $row['date']->startOfDay()->diffInDays($today) : null;
                @endphp
                <li wire:key="{{ $row['key'] }}" data-waiting="{{ $kind }}">
                    <span class="dk-wt-ic dk-wt-{{ $kind }}" aria-hidden="true"><x-desk.icon :name="$icons[$kind] ?? 'dot'" /></span>
                    <div class="dk-wt-m">
                        <a href="{{ $row['href'] }}" wire:navigate class="dk-wt-t" title="{{ $row['text'] }}">{{ $row['text'] }}</a>
                        <p class="dk-is">{{ __('desk.waiting_kind.'.$kind) }}@if ($row['sub'] !== '') · {{ $row['sub'] }}@endif</p>
                    </div>
                    <div class="dk-wt-a">
                        @if ($waited !== null && $waited > 0)
                            <span @class(['dk-pill dk-num', 'dk-bad' => $waited > 7, 'dk-warn' => $waited <= 7]) title="{{ $row['date']->translatedFormat(__('formats.date')) }}">{{ trans_choice('desk.waiting_days', $waited, ['count' => $waited]) }}</span>
                        @endif
                        @if ($row['targets'] !== [])
                            @php
                                $pokeCall = "nudge('".$row['key']."'";
                            @endphp
                            <x-desk.poke :call="$pokeCall" :name="$row['text']" labelled />
                        @endif
                    </div>
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
