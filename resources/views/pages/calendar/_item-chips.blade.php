@foreach ($dayMilestones as $milestone)
    <div wire:key="calendar-milestone-{{ $milestone->id }}" class="group flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $markerTone($milestone) }}">
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['milestone'] }}"></span>
        <flux:icon.flag variant="micro" class="size-3 shrink-0" />
        <button type="button" wire:click.stop="openEditModal({{ $milestone->id }})" class="min-w-0 flex-1 cursor-pointer truncate text-start font-medium" data-test="calendar-milestone-{{ $milestone->id }}">
            {{ $milestone->name }}
        </button>
        @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
            <button type="button" wire:click.stop="confirmDelete({{ $milestone->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                <flux:icon.x-mark variant="micro" class="size-3" />
            </button>
        @endif
    </div>
@endforeach

@foreach ($dayTasks as $task)
    <div wire:key="calendar-task-{{ $task->id }}" class="group flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $taskTone($task->status) }}">
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['task'] }}"></span>
        <flux:icon.clipboard-document-check variant="micro" class="size-3 shrink-0" />
        <button type="button" wire:click.stop="openEditTaskModal({{ $task->id }})" class="min-w-0 flex-1 cursor-pointer truncate text-start font-medium" data-test="calendar-task-{{ $task->id }}">
            {{ $task->title }}
        </button>
        <button type="button" wire:click.stop="confirmDeleteTask({{ $task->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $task->title]) }}">
            <flux:icon.x-mark variant="micro" class="size-3" />
        </button>
    </div>
@endforeach

@foreach ($dayReports as $report)
    <a
        href="{{ route('reports.show', $report) }}"
        wire:navigate
        wire:key="calendar-report-{{ $report->id }}"
        x-on:click.stop
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $reportTone($report->status) }}"
        data-test="calendar-report-{{ $report->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['report'] }}"></span>
        <flux:icon.document-text variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">
            {{ $report->planIndicator?->indicator?->name ?? __('calendar.report_deadline') }}
        </span>
    </a>
@endforeach
