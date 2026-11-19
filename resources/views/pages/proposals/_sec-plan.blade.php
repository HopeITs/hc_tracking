{{--
    "Team plan" section of proposals/show (stage 3b): Media, Finance and
    Other departments as folds (done/total, a late pill, the tasks, an add
    button), then the plan timeline in its own fold. Writes go through the
    existing ManagesProposalPlan forms (_plan-modals). The timeline is built
    only once its fold is opened, so the chart measures a visible box.
    Included by _show-more.blade.php.
--}}
@use('App\Enums\TaskStatus')
@use('App\Models\Task')
@php
    $planWindow = $this->planWindow;
    $canPlanTasks = $this->canPlanTasks;
    $groups = $this->planGroups;
    $readOnlyReason = $planWindow->denialMessage();
    $today = today();
    $taskStatusClass = [
        TaskStatus::Done->value => 'pp-st-done',
        TaskStatus::InProgress->value => 'pp-st-progress',
        TaskStatus::UnderReview->value => 'pp-st-review',
        TaskStatus::Todo->value => '',
    ];
    $taskGroups = [
        ['key' => 'media', 'kind' => 'media_deliverable', 'title' => __('department_tasks.group_media'), 'add' => __('department_tasks.add_media_deliverable')],
        ['key' => 'finance', 'kind' => 'financial', 'title' => __('department_tasks.group_finance'), 'add' => __('department_tasks.add_financial_task')],
        ['key' => 'other', 'kind' => 'general', 'title' => __('department_tasks.group_other'), 'add' => __('department_tasks.add_department_task')],
    ];
@endphp

<div class="pp-plan" data-tour="proposal-plan">
    @if ($readOnlyReason !== null)
        <p class="pp-small" data-plan-read-only>{{ $readOnlyReason }}</p>
    @elseif (! $canPlanTasks)
        <p class="pp-small" data-plan-read-only>{{ __('department_tasks.plan_read_only_rights') }}</p>
    @endif

    @foreach ($taskGroups as $group)
        @php
            $tasks = $groups[$group['key']];
            $groupDone = $tasks->where('status', TaskStatus::Done)->count();
            $groupLate = $tasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt($today))->count();
        @endphp
        <details class="pp-sub" wire:ignore.self wire:key="plan-group-{{ $group['key'] }}" data-plan-group="{{ $group['key'] }}" data-tour="proposal-plan-{{ $group['key'] }}">
            <summary>
                <span class="pp-grow">{{ $group['title'] }}</span>
                <span class="pp-small tabular-nums">{{ __('proposals.sections.done_short', ['done' => $groupDone, 'total' => $tasks->count()]) }}</span>
                @if ($groupLate > 0)
                    <span class="pp-pill pp-pill-red">{{ trans_choice('proposals.sections.flag_late', $groupLate, ['count' => $groupLate]) }}</span>
                @endif
                <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="pp-sub-b">
                @forelse ($tasks as $task)
                    @include('pages.proposals._sec-task-row', [
                        'task' => $task,
                        'taskStatusClass' => $taskStatusClass,
                        'today' => $today,
                        'editable' => $canPlanTasks && $task->source_type === 'proposal' && $task->department_id !== null,
                    ])
                @empty
                    <span class="pp-small" data-plan-empty="{{ $group['key'] }}">{{ __('proposals.sections.no_tasks') }}</span>
                @endforelse
                @if ($canPlanTasks)
                    <div>
                        <button type="button" class="pp-btn pp-btn-sm" wire:click="openPlanTaskForm(null, '{{ $group['kind'] }}')" data-test="add-plan-task-{{ $group['key'] }}">
                            <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ $group['add'] }}
                        </button>
                    </div>
                @endif
            </div>
        </details>
    @endforeach

    @php
        $undatedCount = $this->planTaskRows->whereNull('due_date')->count();
    @endphp
    @if ($undatedCount > 0)
        <p class="pp-small" data-undated>{{ trans_choice('proposals.count_tasks_carry_no_due_date', $undatedCount, ['count' => $undatedCount]) }}</p>
    @endif

    <details class="pp-sub" wire:ignore.self x-data="{ shown: false }" x-on:toggle="shown = shown || $el.open" data-tour="proposal-plan-gantt" data-plan-timeline>
        <summary>
            <span class="pp-grow">{{ __('proposals.sections.timeline') }}</span>
            <span class="pp-small">{{ __('department_tasks.plan_timeline_subtitle') }}</span>
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            @if ($this->planGanttPayload['rows'] === [])
                <span class="pp-small">{{ __('department_tasks.plan_timeline_empty') }}</span>
            @else
                <template x-if="shown">
                    <div>
                        <x-ui.gantt :payload="$this->planGanttPayload" :scope="'proposal-plan-'.$this->proposalId" height="h-[min(60vh,32rem)]" wire:ignore wire:key="proposal-plan-gantt-{{ $this->proposalId }}-{{ md5(json_encode($this->planGanttPayload['rows'])) }}" />
                    </div>
                </template>
            @endif
        </div>
    </details>
</div>

@include('pages.proposals._plan-modals')
