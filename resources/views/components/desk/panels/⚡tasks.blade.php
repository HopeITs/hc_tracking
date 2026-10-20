<?php

use App\Actions\Tasks\CompleteTask;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Milestone;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Tasks: the viewer's own tasks or every task they may see, by status.
 * Ticking a box completes the task through CompleteTask, so the policy
 * (updateStatus) and activity log apply as anywhere else; a done task is
 * closed and cannot be reopened here.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;

    public string $scope = 'mine';

    public function setScope(string $scope): void
    {
        $this->scope = $scope === 'team' ? 'team' : 'mine';
        $this->page = 0;
    }

    public function shownStatus(): TaskStatus
    {
        return TaskStatus::tryFrom($this->filter) ?? TaskStatus::Todo;
    }

    public function complete(int $taskId): void
    {
        $task = $this->base()->find($taskId);

        if ($task === null) {
            return;
        }

        app(CompleteTask::class)->handle($task);
        unset($this->rows);
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->base()
            ->with([
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name', 'proposal:id,name']]),
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount(['subtasks', 'subtasks as done_subtasks_count' => fn (Builder $query) => $query->where('status', TaskStatus::Done)])
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * task id => its project or proposal name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parents(): array
    {
        return $this->rows->mapWithKeys(fn (Task $task): array => [$task->id => $this->taskParentName($task)])->all();
    }

    /**
     * @return Builder<Task>
     */
    private function base(): Builder
    {
        $employeeId = auth()->user()->employee_id;

        return $this->visible(Task::query(), 'tasks')
            ->whereNull('parent_task_id')
            ->when($this->scope === 'mine', fn (Builder $query) => $query->whereHas('taskAssignees', fn (Builder $assignee) => $assignee->where('employee_id', $employeeId ?? 0)));
    }
};
?>

@php
    $rows = $this->rows;
    $status = $this->shownStatus();
    $list = $rows->where('status', $status)->values();
    $pg = $this->paginate($list, 5);
    $today = \Carbon\CarbonImmutable::today();
    $user = auth()->user();
@endphp

<x-desk.panel id="tasks" register="tasks.index" :size="$size" :index="$index" :total="$total" :count="$rows->where('status', '!=', TaskStatus::Done)->count()">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach (TaskStatus::cases() as $option)
                <button type="button" wire:click="setFilter('{{ $option->value }}')" aria-pressed="{{ $option === $status ? 'true' : 'false' }}">{{ $option->label() }} {{ $rows->where('status', $option)->count() }}</button>
            @endforeach
        </div>
        <label class="dk-sr" for="ps-tasks-scope">{{ __('desk.whose') }}</label>
        <select class="dk-mini" id="ps-tasks-scope" wire:change="setScope($event.target.value)">
            <option value="mine" @selected($scope === 'mine')>{{ __('desk.scope_mine_tasks') }}</option>
            <option value="team" @selected($scope === 'team')>{{ __('desk.scope_team') }}</option>
        </select>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ul class="dk-tasks">
            @foreach ($pg['slice'] as $task)
                @php
                    $done = $task->status === TaskStatus::Done;
                    $overdue = ! $done && $task->due_date !== null && $task->due_date->lt($today);
                    $canTick = ! $done && $user->can('updateStatus', $task);
                    $who = $task->taskAssignees->map(fn ($assignee) => $assignee->employee?->name)->filter()->implode(', ');
                @endphp
                <li wire:key="tk-{{ $task->id }}" @class(['dk-done' => $done])>
                    <label for="tk-{{ $task->id }}">
                        <input type="checkbox" id="tk-{{ $task->id }}" @checked($done) @disabled(! $canTick) @if ($canTick) wire:click="complete({{ $task->id }})" @endif>
                        <span class="dk-tt">@if ($task->kind !== \App\Enums\TaskKind::General)<span class="dk-ms">{{ $task->kind->label() }}</span>@endif{{ $task->title }}<span class="dk-is"> · {{ $this->parents[$task->id] }}</span></span>
                    </label>
                    <span @class(['dk-tm', 'dk-overdue' => $overdue])>@if ($task->subtasks_count > 0)<span class="dk-subc dk-num">{{ $task->done_subtasks_count }}/{{ $task->subtasks_count }}</span> @endif{{ $who !== '' ? $who : '—' }}@if ($task->due_date !== null) · {{ $task->due_date->translatedFormat(__('desk.date_short')) }}@endif</span>
                    <a href="{{ route('tasks.show', $task) }}" wire:navigate class="dk-iconbtn dk-sm" aria-label="{{ __('desk.open_named', ['name' => $task->title]) }}" title="{{ __('desk.open_task') }}"><x-desk.icon name="edit" /></a>
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.nothing_here') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
