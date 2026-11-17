{{-- The subtask list on ⚡show.blade.php: shown on top-level tasks that have subtasks. Subtasks are added from the ⋯ menu in the header. --}}
@use('App\Enums\TaskStatus')
@php
    $doneSubtaskCount = $this->subtasks->where('status', TaskStatus::Done)->count();
@endphp
<x-ui.panel :clip="false" class="p-4" data-test="task-subtasks-panel">
    <h2 class="mb-1 flex items-center gap-2 text-base font-bold">
        {{ __('tasks.subtasks') }}
        <span class="text-sm font-medium tabular-nums text-secondary-medium dark:text-ink-muted">{{ $doneSubtaskCount }}/{{ $this->subtasks->count() }}</span>
    </h2>

    <ul class="divide-y divide-secondary-soft dark:divide-line">
        @foreach ($this->subtasks as $subtask)
            @php
                $subtaskAssignees = $subtask->taskAssignees->pluck('employee')->filter();
                $isSubtaskDone = $subtask->status === TaskStatus::Done;
            @endphp
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5" wire:key="subtask-{{ $subtask->id }}">
                <span @class(['grid size-4 shrink-0 place-items-center rounded border', 'border-primary bg-primary text-white' => $isSubtaskDone, 'border-secondary-light dark:border-line-strong' => ! $isSubtaskDone]) aria-hidden="true">
                    @if ($isSubtaskDone)
                        <flux:icon name="check" variant="micro" class="size-3" />
                    @endif
                </span>
                <a href="{{ route('tasks.show', $subtask) }}" wire:navigate @class(['min-w-0 flex-1 truncate text-sm font-semibold hover:underline', 'text-secondary-medium line-through dark:text-ink-muted' => $isSubtaskDone])>
                    {{ $subtask->title }}
                </a>
                <span class="flex shrink-0 items-center gap-3 text-xs text-secondary-medium dark:text-ink-muted">
                    <span class="max-w-40 truncate">{{ $subtaskAssignees->isEmpty() ? __('common.not_assigned') : $subtaskAssignees->map->name->implode(', ') }}</span>
                    <span class="tabular-nums">{{ $subtask->due_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                    <x-ui.enum-badge :case="$subtask->status" />
                </span>
            </li>
        @endforeach
    </ul>
</x-ui.panel>
