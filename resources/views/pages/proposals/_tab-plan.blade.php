{{--
    The Plan tab of proposals/show (T31): the proposal's milestones, its
    Media, Finance and other department tasks, and a read-only Gantt of the
    whole plan. It took over the old Tasks tab. Writes go through the
    ManagesProposalPlan forms (_plan-modals). Included by ⚡show.blade.php,
    whose top PHP block defines $mutedText, $inkText and $hairline.
--}}
@use('App\Enums\TaskStatus')
@use('App\Models\Task')
@php
    $planWindow = $this->planWindow;
    $canPlanMilestones = $this->canPlanMilestones;
    $canPlanTasks = $this->canPlanTasks;
    $milestoneRows = $this->planMilestones;
    $groups = $this->planGroups;
    $allTasks = $this->planTaskRows;
    $hasFinalReport = collect($milestoneRows)->contains(fn (array $row): bool => (bool) $row['milestone']->is_final_report);
    $readOnlyReason = $planWindow->denialMessage();
    $taskTotal = $allTasks->count();
    $statusCounts = collect(TaskStatus::cases())->map(fn (TaskStatus $status): array => [
        'status' => $status,
        'count' => $allTasks->where('status', $status)->count(),
    ]);
    $overdueCount = $allTasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today()))->count();
    $unassignedCount = $allTasks->filter(fn (Task $task): bool => $task->taskAssignees->isEmpty())->count();
    $undatedCount = $allTasks->whereNull('due_date')->count();
    $statusTone = [
        TaskStatus::Done->value => 'bg-secondary-dark dark:bg-ink',
        TaskStatus::UnderReview->value => 'bg-secondary-medium dark:bg-ink-muted',
        TaskStatus::InProgress->value => 'bg-secondary-light',
        TaskStatus::Todo->value => 'bg-secondary-soft dark:bg-line-strong',
    ];
    $taskGroups = [
        ['key' => 'media', 'kind' => 'media_deliverable', 'icon' => 'camera', 'title' => __('department_tasks.group_media'), 'add' => __('department_tasks.add_media_deliverable'), 'empty' => __('department_tasks.group_empty_media')],
        ['key' => 'finance', 'kind' => 'financial', 'icon' => 'banknotes', 'title' => __('department_tasks.group_finance'), 'add' => __('department_tasks.add_financial_task'), 'empty' => __('department_tasks.group_empty_finance')],
        ['key' => 'other', 'kind' => 'general', 'icon' => 'clipboard-document-list', 'title' => __('department_tasks.group_other'), 'add' => __('department_tasks.add_department_task'), 'empty' => __('department_tasks.group_empty_other')],
    ];
@endphp

<div class="flex flex-col gap-8" data-tour="proposal-plan">
    {{-- Who may change the plan now, and the add actions --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-prose text-sm {{ $mutedText }}">
            @if ($readOnlyReason !== null)
                <span class="inline-flex items-center gap-1.5 font-semibold {{ $inkText }}" data-plan-read-only>
                    <flux:icon.lock-closed variant="micro" class="size-4 shrink-0" aria-hidden="true" />{{ $readOnlyReason }}
                </span>
            @elseif (! $canPlanMilestones && ! $canPlanTasks)
                <span class="inline-flex items-center gap-1.5" data-plan-read-only>
                    <flux:icon.eye variant="micro" class="size-4 shrink-0" aria-hidden="true" />{{ __('milestones.plan_read_only_rights') }}
                </span>
            @else
                {{ __('milestones.plan_intro') }}
            @endif
        </p>

        @if ($canPlanMilestones)
            <div class="flex flex-wrap gap-2">
                @unless ($hasFinalReport)
                    <x-ui.button size="sm" icon="document-check" wire:click="openFinalReportForm" data-test="add-final-report-button">{{ __('milestones.add_final_report') }}</x-ui.button>
                @endunless
                <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openPlanMilestoneForm" data-test="add-plan-milestone-button">{{ __('milestones.add_milestone') }}</x-ui.button>
            </div>
        @endif
    </div>

    {{-- Milestones --}}
    <section class="min-w-0" data-plan-group="milestones" data-tour="proposal-plan-milestones">
        <header class="flex items-center justify-between gap-3 border-b pb-3 {{ $hairline }}">
            <h3 class="flex items-center gap-2 text-base font-bold {{ $inkText }}">
                <flux:icon.flag variant="micro" class="size-4 shrink-0 {{ $mutedText }}" aria-hidden="true" />{{ __('common.milestones') }}
                <span class="text-xs font-semibold tabular-nums {{ $mutedText }}">{{ count($milestoneRows) }}</span>
            </h3>
        </header>

        @if ($milestoneRows === [])
            <p class="py-4 text-sm {{ $mutedText }}" data-plan-empty="milestones">{{ __('milestones.plan_no_milestones') }}</p>
        @else
            <ul class="divide-y {{ $hairline }}">
                @foreach ($milestoneRows as $row)
                    @php
                        $milestone = $row['milestone'];
                    @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-3" style="padding-inline-start: {{ $row['depth'] * 1.25 }}rem" wire:key="plan-milestone-{{ $milestone->id }}" data-plan-milestone="{{ $milestone->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="flex min-w-0 items-center gap-2">
                                <a href="{{ route('milestones.show', $milestone) }}" wire:navigate class="truncate text-sm font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium {{ $inkText }}" dir="auto">{{ $milestone->name }}</a>
                                @if ($milestone->is_final_report)
                                    <span class="inline-flex shrink-0 items-center gap-1 rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold {{ $mutedText }}" data-final-report>
                                        <flux:icon.document-check variant="micro" class="size-3.5" aria-hidden="true" />{{ __('milestones.final_report_badge') }}
                                    </span>
                                @endif
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs {{ $mutedText }}">
                                <span class="tabular-nums" dir="ltr">{{ $milestone->planned_start_date?->translatedFormat(__('formats.date')) }}@if ($milestone->planned_end_date !== null && ! $milestone->planned_end_date->equalTo($milestone->planned_start_date)) – {{ $milestone->planned_end_date->translatedFormat(__('formats.date')) }}@endif</span>
                                <span aria-hidden="true">·</span>
                                <span dir="auto">{{ $milestone->project?->name ?? __('milestones.shared_across_centers') }}</span>
                                @if ($milestone->responsibleUser !== null)
                                    <span aria-hidden="true">·</span>
                                    <span dir="auto">{{ $milestone->responsibleUser->name }}</span>
                                @endif
                            </p>
                        </div>
                        <x-ui.enum-badge :case="$milestone->status" />
                        @if ($canPlanMilestones && ! $milestone->is_final_report)
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" wire:click="openPlanMilestoneForm({{ $milestone->id }})" :aria-label="__('common.edit_name', ['name' => $milestone->name])" data-test="edit-plan-milestone-{{ $milestone->id }}" />
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Where the work stands: one bar split by status, then the gaps worth chasing --}}
    @if ($taskTotal > 0)
        <div class="flex flex-col gap-5 border-b pb-6 {{ $hairline }} @3xl/detail:flex-row @3xl/detail:items-end @3xl/detail:justify-between @3xl/detail:gap-10" data-task-overview>
            <div class="min-w-0 flex-1">
                <div class="flex h-2 w-full gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ $statusCounts->map(fn (array $line): string => $line['status']->label().' '.$line['count'])->implode(', ') }}">
                    @foreach ($statusCounts->reverse() as $line)
                        @if ($line['count'] > 0)
                            <span class="money-grow h-full first:rounded-s-full last:rounded-e-full {{ $statusTone[$line['status']->value] }}" style="width: {{ round($line['count'] / $taskTotal * 100, 2) }}%"></span>
                        @endif
                    @endforeach
                </div>
                <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                    @foreach ($statusCounts as $line)
                        <div class="flex items-center gap-2" wire:key="task-status-{{ $line['status']->value }}" data-status-count="{{ $line['status']->value }}">
                            <span class="size-2 rounded-full {{ $statusTone[$line['status']->value] }}" aria-hidden="true"></span>
                            <dt class="text-xs {{ $mutedText }}">{{ $line['status']->label() }}</dt>
                            <dd @class(['text-sm font-bold tabular-nums', $inkText => $line['count'] > 0, $mutedText => $line['count'] === 0])>{{ $line['count'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <dl class="flex shrink-0 gap-x-8">
                @foreach ([
                    ['key' => 'overdue', 'label' => __('proposals.overdue'), 'count' => $overdueCount, 'alarm' => $overdueCount > 0],
                    ['key' => 'unassigned', 'label' => __('common.unassigned'), 'count' => $unassignedCount, 'alarm' => false],
                    ['key' => 'undated', 'label' => __('proposals.no_due_date'), 'count' => $undatedCount, 'alarm' => false],
                ] as $gap)
                    <div class="min-w-0" data-task-gap="{{ $gap['key'] }}">
                        <dt class="type-label {{ $mutedText }}">{{ $gap['label'] }}</dt>
                        <dd @class([
                            'mt-1 text-2xl leading-none font-extrabold tracking-tight tabular-nums',
                            'text-status-critical' => $gap['alarm'],
                            $inkText => ! $gap['alarm'] && $gap['count'] > 0,
                            $mutedText => $gap['count'] === 0,
                        ])>{{ $gap['count'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    {{-- Department tasks: Media, Finance, Other --}}
    @foreach ($taskGroups as $group)
        @php
            $tasks = $groups[$group['key']];
            $groupDone = $tasks->where('status', TaskStatus::Done)->count();
        @endphp
        <section class="min-w-0" wire:key="plan-group-{{ $group['key'] }}" data-plan-group="{{ $group['key'] }}" data-tour="proposal-plan-{{ $group['key'] }}">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 {{ $hairline }}">
                <h3 class="flex items-center gap-2 text-base font-bold {{ $inkText }}">
                    <flux:icon :name="$group['icon']" variant="micro" class="size-4 shrink-0 {{ $mutedText }}" aria-hidden="true" />{{ $group['title'] }}
                    @if ($tasks->isNotEmpty())
                        <span class="text-xs font-semibold tabular-nums {{ $mutedText }}">{{ __('proposals.done_of_total', ['done' => $groupDone, 'total' => $tasks->count()]) }}</span>
                    @endif
                </h3>
                @if ($canPlanTasks)
                    <x-ui.button size="sm" icon="plus" wire:click="openPlanTaskForm(null, '{{ $group['kind'] }}')" data-test="add-plan-task-{{ $group['key'] }}">{{ $group['add'] }}</x-ui.button>
                @endif
            </header>

            @if ($tasks->isEmpty())
                <p class="py-4 text-sm {{ $mutedText }}" data-plan-empty="{{ $group['key'] }}">{{ $group['empty'] }}</p>
            @else
                <ul class="divide-y {{ $hairline }}">
                    @foreach ($tasks as $task)
                        @include('pages.proposals._task-row', [
                            'task' => $task,
                            'inkText' => $inkText,
                            'mutedText' => $mutedText,
                            'planActions' => $canPlanTasks && $task->department_id !== null,
                        ])
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach

    @if ($undatedCount > 0)
        <p class="-mt-4 text-xs {{ $mutedText }}" data-undated>
            {{ trans_choice('proposals.count_tasks_carry_no_due_date', $undatedCount, ['count' => $undatedCount]) }}
        </p>
    @endif

    {{-- The plan on a timeline --}}
    <x-ui.panel :title="__('milestones.plan_timeline')" :subtitle="__('milestones.plan_timeline_subtitle')" data-tour="proposal-plan-gantt">
        @if ($this->planGanttPayload['rows'] === [])
            <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('milestones.plan_timeline_empty')" />
        @else
            <x-ui.gantt :payload="$this->planGanttPayload" :scope="'proposal-plan-'.$this->proposalId" height="h-[min(60vh,32rem)]" wire:ignore wire:key="proposal-plan-gantt-{{ $this->proposalId }}-{{ md5(json_encode($this->planGanttPayload['rows'])) }}" />
        @endif
    </x-ui.panel>
</div>

@include('pages.proposals._plan-modals')
