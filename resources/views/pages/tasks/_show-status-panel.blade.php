{{-- The status bar under the header on ⚡show.blade.php. Editors and assignees move the task in place (TaskWorkflow decides which moves are allowed): Done is dimmed for anyone who does not approve the task, and an approver returning an Under review task (the header's Return button, or a To do / In progress pill) writes the reason first. Everyone else reads the phase. --}}
@use('App\Enums\TaskStatus')
<div class="flex flex-col items-start gap-2" aria-label="{{ __('tasks.show.status') }}">
    @if ($canUpdateTaskStatus)
        <div class="inline-flex max-w-full flex-wrap gap-1 rounded-full border border-panel-border bg-white p-1 shadow-card dark:bg-surface-1" role="radiogroup" aria-label="{{ __('tasks.show.status') }}" data-test="task-edit-form">
            @foreach (TaskStatus::cases() as $statusCase)
                @php
                    $isStatusSelected = $taskStatus === $statusCase->value;
                    $isStatusLocked = ! $isStatusSelected && $this->task->status === TaskStatus::Done
                        || ($statusCase === TaskStatus::Done && ! $isStatusSelected && ! $approvesTask);
                @endphp
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $isStatusSelected ? 'true' : 'false' }}"
                    @if ($isStatusLocked) disabled @if ($statusCase === TaskStatus::Done) title="{{ __('tasks.show.done_creator_only') }}" @endif @else wire:click="changeStatus('{{ $statusCase->value }}')" @endif
                    wire:loading.attr="disabled"
                    wire:target="changeStatus"
                    @class([
                        'inline-flex h-8 touch-manipulation items-center justify-center gap-1.5 rounded-full px-3.5 text-xs font-semibold whitespace-nowrap transition-colors',
                        'bg-primary text-white shadow-sm' => $isStatusSelected,
                        'cursor-pointer text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink' => ! $isStatusSelected && ! $isStatusLocked,
                        'cursor-not-allowed text-secondary-medium opacity-40 dark:text-ink-muted' => $isStatusLocked,
                    ])
                    data-test="task-edit-status-{{ $statusCase->value }}"
                >
                    {{ $statusCase->label() }}
                </button>
            @endforeach
        </div>
        <flux:error name="taskStatus" />

        @if ($this->reviewNoteNeeded())
            @php $returnTo = TaskStatus::from($taskStatus); @endphp
            <div class="grid w-full max-w-xl gap-2 rounded-panel border border-primary/40 bg-white p-3 dark:bg-surface-1" x-init="$nextTick(() => $el.querySelector('textarea')?.focus())" data-test="task-send-back-form">
                <p class="text-sm font-bold text-secondary-dark dark:text-ink">{{ __('tasks.show.return_title', ['status' => $returnTo->label()]) }}</p>
                <flux:textarea wire:model="reviewNote" :label="__('tasks.show.return_reason')" :placeholder="__('tasks.show.return_reason_placeholder')" rows="2" maxlength="200" required data-test="task-review-note" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" tone="ghost" size="sm" wire:click="cancelStatusChange" data-test="task-cancel-send-back-button">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="button" tone="primary" size="sm" wire:click="sendBack" wire:loading.attr="disabled" wire:target="sendBack" data-test="task-send-back-button">{{ __('tasks.show.return_confirm', ['status' => $returnTo->label()]) }}</x-ui.button>
                </div>
            </div>
        @endif
    @else
        <div class="flex items-center gap-2 text-sm">
            <span class="text-secondary-medium dark:text-ink-muted">{{ __('tasks.show.status') }}</span>
            <x-ui.enum-badge :case="$this->task->status" />
        </div>
    @endif
</div>
