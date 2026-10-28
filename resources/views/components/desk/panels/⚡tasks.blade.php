<?php

use App\Actions\Tasks\ChangeTaskStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Milestone;
use App\Models\Task;
use App\Support\Desk\TeamScope;
use App\Support\TaskWorkflow;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Tasks: the viewer's tasks (assigned to them or created by them), their
 * team's (TeamScope, for anyone who has a team) or their department's,
 * one phase at a time. Each row carries a four-dot phase stepper: the next
 * allowed phase is a button, every other phase says why it is not
 * (TaskWorkflow). Tasks sent for the viewer's review show Approve and Send
 * back (with a required note). Every move goes through ChangeTaskStatus, so
 * the policy, the workflow and the activity log apply as anywhere else.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;

    public string $scope = 'mine';

    public function mount(): void
    {
        $this->filter = 'open';
    }

    public function updatedScope(string $scope): void
    {
        $this->scope = in_array($scope, ['team', 'department'], true) ? $scope : 'mine';
        $this->page = 0;
        unset($this->rows);
    }

    /**
     * Move a task to another phase; refusals arrive as a toast with the reason.
     */
    public function move(int $taskId, string $status, ?string $note = null): void
    {
        $task = $this->base()->find($taskId);
        $to = TaskStatus::tryFrom($status);

        if ($task === null || $to === null) {
            return;
        }

        try {
            app(ChangeTaskStatus::class)->handle($task, $to, $note);
        } catch (ValidationException $refused) {
            Flux::toast(variant: 'danger', text: $refused->validator->errors()->first());

            return;
        }

        unset($this->rows);
        Flux::toast(variant: 'success', text: match (true) {
            $to === TaskStatus::Done => __('tasks.workflow.approved'),
            $to === TaskStatus::UnderReview => __('tasks.workflow.sent_for_review'),
            $note !== null && trim($note) !== '' => __('tasks.workflow.sent_back'),
            default => __('tasks.task_status_changed', ['status' => $to->label()]),
        });
    }

    /**
     * Whether the viewer has a team, so the "My team" choice is offered.
     */
    #[Computed]
    public function hasTeam(): bool
    {
        return TeamScope::for(auth()->user()) !== [];
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
     * Each phase of one task: whether it is passed, current, a move the
     * viewer may make, or refused with the reason.
     *
     * @return list<array{status: TaskStatus, state: string, reason: string|null}>
     */
    public function steps(Task $task): array
    {
        $user = auth()->user();
        $order = array_search($task->status, TaskStatus::cases(), true);

        return array_map(function (TaskStatus $status, int $at) use ($task, $user, $order): array {
            if ($status === $task->status) {
                return ['status' => $status, 'state' => 'now', 'reason' => null];
            }

            $reason = TaskWorkflow::refusal($user, $task, $status, $task->status === TaskStatus::UnderReview ? 'note' : null);

            return ['status' => $status, 'state' => $reason === null ? 'go' : ($at < $order ? 'past' : 'no'), 'reason' => $reason];
        }, TaskStatus::cases(), array_keys(TaskStatus::cases()));
    }

    /**
     * @return Builder<Task>
     */
    private function base(): Builder
    {
        $user = auth()->user();
        $employeeIds = match ($this->scope) {
            'team' => array_keys(TeamScope::for($user)),
            'department' => $user->departmentEmployeeIds(),
            default => [],
        };

        return $this->visible(Task::query(), 'tasks')
            ->whereNull('parent_task_id')
            ->when($this->scope === 'mine', fn (Builder $query) => $query->where(fn (Builder $mine) => $mine
                ->where('created_by', $user->id)
                ->orWhereHas('taskAssignees', fn (Builder $assignee) => $assignee->where('employee_id', $user->employee_id ?? 0))))
            ->when($this->scope !== 'mine', fn (Builder $query) => $query->whereHas('taskAssignees', fn (Builder $assignee) => $assignee->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds)));
    }
};
?>

@php
    $rows = $this->rows;
    $open = $rows->where('status', '!=', TaskStatus::Done);
    $status = TaskStatus::tryFrom($filter);
    $list = $status === null ? $open->values() : $rows->where('status', $status)->values();
    $pg = $this->paginate($list, self::PerPage);
    $today = \Carbon\CarbonImmutable::today();
    $user = auth()->user();
@endphp

<x-desk.panel :facets="$pg['facets']" :filtered="$scope !== 'mine'" id="tasks" register="tasks.index" :size="$size" :index="$index" :total="$total" :count="$open->count()">
    <x-slot:filters>
        <div class="contents" data-filter-defaults='{"scope":"mine"}'>
            <x-ui.header-filter-select :label="__('desk.whose')" wire:model="scope" :value="$scope" deferred>
                <option value="mine">{{ __('desk.scope_mine_tasks') }}</option>
                @if ($this->hasTeam)
                    <option value="team">{{ __('desk.scope_team') }}</option>
                @endif
                <option value="department">{{ __('desk.scope_department') }}</option>
            </x-ui.header-filter-select>
        </div>
    </x-slot:filters>

    <x-slot:tools>
        <div class="dk-seg" role="group">
            <button type="button" wire:click="setFilter('open')" aria-pressed="{{ $status === null ? 'true' : 'false' }}">{{ __('desk.tasks_open') }} <span class="dk-num">{{ $open->count() }}</span></button>
            @foreach (TaskStatus::cases() as $option)
                <button type="button" wire:click="setFilter('{{ $option->value }}')" aria-pressed="{{ $option === $status ? 'true' : 'false' }}">{{ $option->label() }} <span class="dk-num">{{ $rows->where('status', $option)->count() }}</span></button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ul class="dk-tk">
            @foreach ($pg['slice'] as $task)
                @php
                    $done = $task->status === TaskStatus::Done;
                    $overdue = ! $done && $task->due_date !== null && $task->due_date->lt($today);
                    $people = $task->taskAssignees->map(fn ($assignee) => $assignee->employee?->name)->filter()->values();
                    $steps = $done ? [] : $this->steps($task);
                    $toReview = $task->status === TaskStatus::UnderReview && TaskWorkflow::approves($user, $task);
                @endphp
                <li wire:key="tk-{{ $task->id }}" @class(['dk-done' => $done])>
                    <div class="dk-tk-main">
                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="dk-tk-t" title="{{ $task->title }}">@if ($task->kind !== TaskKind::General)<span class="dk-ms">{{ $task->kind->label() }}</span>@endif{{ $task->title }}</a>
                        <p class="dk-is"><span class="dk-tk-p" title="{{ $this->parents[$task->id] }}">{{ $this->parents[$task->id] }}</span>@if ($task->due_date !== null)<span @class(['dk-bad' => $overdue])> · {{ $overdue ? __('desk.tasks_late_since', ['date' => $task->due_date->translatedFormat(__('desk.date_short'))]) : $task->due_date->translatedFormat(__('desk.date_short')) }}</span>@endif</p>
                    </div>
                    <span class="dk-tk-av" title="{{ $people->implode(', ') }}">
                        @foreach ($people->take(3) as $name)
                            <span class="dk-av dk-av-xs">{{ collect(preg_split('/\s+/u', trim($name)))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                        @endforeach
                        @if ($people->count() > 3)<span class="dk-av dk-av-xs dk-num">+{{ $people->count() - 3 }}</span>@endif
                    </span>
                    @if ($done)
                        <span class="dk-pill dk-good dk-tk-steps">{{ TaskStatus::Done->label() }}</span>
                    @else
                        <ol class="dk-tk-steps" aria-label="{{ __('desk.tasks_phase') }}">
                            @foreach ($steps as $step)
                                <li>
                                    @if ($step['state'] === 'go' && ! ($toReview && $step['status'] !== TaskStatus::Done))
                                        <button type="button" class="dk-step dk-go" wire:click="move({{ $task->id }}, '{{ $step['status']->value }}')" title="{{ __('desk.tasks_move_to', ['phase' => $step['status']->label()]) }}" aria-label="{{ __('desk.tasks_move_to', ['phase' => $step['status']->label()]) }}"></button>
                                    @else
                                        <span @class(['dk-step', 'dk-now' => $step['state'] === 'now', 'dk-past' => $step['state'] === 'past', 'dk-no' => in_array($step['state'], ['no', 'go'], true)]) title="{{ $step['status']->label() }}{{ $step['reason'] !== null ? ' — '.$step['reason'] : '' }}" @if ($step['state'] === 'now') aria-current="step" @endif></span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($toReview)
                        <div class="dk-tk-review">
                            <button type="button" class="dk-btn dk-sm dk-primary" wire:click="move({{ $task->id }}, '{{ TaskStatus::Done->value }}')" @if ($steps[3]['reason'] !== null) disabled title="{{ $steps[3]['reason'] }}" @endif>{{ __('desk.decision_action.approve') }}</button>
                            @php
                                $sendBack = "move({$task->id}, '".TaskStatus::InProgress->value."'";
                            @endphp
                            <x-desk.note-action :call="$sendBack" :label="__('desk.tasks_send_back')" :heading="__('desk.tasks_send_back_named', ['name' => $task->title])" :placeholder="__('tasks.workflow.send_back_note')" required />
                        </div>
                    @endif
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
