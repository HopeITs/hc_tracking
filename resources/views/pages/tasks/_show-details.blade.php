{{-- The description on ⚡show.blade.php. Shown only when the task has one, or while an editor writes it (opened from the ⋯ menu or by clicking the text). Everyone else only reads it. --}}
<div
    x-data="{ editing: false }"
    x-on:task-edit-details.window="editing = true; $nextTick(() => $refs.titleField?.querySelector('input')?.focus())"
    x-on:task-details-saved.window="editing = false"
    @unless ($hasDescription) x-show="editing" x-cloak @endunless
    data-test="task-description"
>
    <x-ui.panel :clip="false" class="p-4">
        <h2 class="mb-2 text-base font-bold">{{ __('common.description') }}</h2>

        @if ($hasDescription)
            <div x-show="! editing">
                @if ($canEditTask)
                    <button type="button" x-on:click="$dispatch('task-edit-details')" class="-mx-1.5 -my-1 block w-[calc(100%+0.75rem)] cursor-text rounded-md px-1.5 py-1 text-start whitespace-pre-line text-sm text-secondary-dark hover:bg-secondary-soft/60 dark:text-ink dark:hover:bg-surface-2">{{ $this->task->description }}</button>
                @else
                    <p class="whitespace-pre-line text-sm text-secondary-dark dark:text-ink">{{ $this->task->description }}</p>
                @endif
            </div>
        @endif

        @if ($canEditTask)
            <form wire:submit="saveDetails" x-show="editing" x-cloak class="grid gap-4" data-test="task-details-form">
                <div x-ref="titleField">
                    <x-ui.input wire:model="title" :label="__('tasks.task_title')" type="text" maxlength="255" required data-test="task-edit-title-input" />
                </div>

                <flux:textarea wire:model="description" :label="__('common.description')" rows="5" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-field-line! dark:bg-surface-2! dark:text-ink!" />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" tone="ghost" x-on:click="editing = false; $wire.cancelDetails()" data-test="task-cancel-details-button">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" tone="primary" data-test="task-save-details-button">{{ __('common.save_changes') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.panel>
</div>
