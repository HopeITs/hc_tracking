{{-- "Start from Program Framework" (T32): pick a track, preview its size, confirm. Logic in CopiesProgramFramework. --}}
<x-ui.modal
    wire:model="showCopyModal"
    :title="__('results_framework.start_from_program_framework')"
    :description="__('results_framework.copy_framework_intro')"
    submit="askCopyConfirmation"
    close="closeCopyModal"
>
    @if ($showCopyModal)
        @if ($this->copySources->isEmpty())
            <p class="type-meta text-secondary-medium dark:text-ink-muted" data-test="copy-framework-none">{{ __('results_framework.copy_framework_none') }}</p>
        @else
            <div class="flex flex-col gap-3">
                <x-ui.form-select wire:model.live="copySourceId" :label="__('results_framework.copy_framework_source')" :placeholder="__('results_framework.copy_framework_choose')" required>
                    @foreach ($this->copySources as $source)
                        <option value="{{ $source['id'] }}" data-test="copy-source-option-{{ $source['id'] }}">
                            {{ Str::limit($source['statement'], 70) }} · {{ $source['type']->label() }}@if ($source['program_id'] !== null) · {{ \App\Models\HrProgram::nameOf($source['program_id']) }}@endif
                        </option>
                    @endforeach
                </x-ui.form-select>

                @if ($this->copyPreview() !== null)
                    @php
                        $preview = $this->copyPreview();
                    @endphp
                    <p class="flex items-start gap-2 rounded-lg border border-secondary-soft bg-ground px-3 py-2.5 type-meta text-secondary-dark dark:border-line dark:bg-surface-2 dark:text-ink" data-test="copy-preview">
                        <flux:icon.information-circle variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        <span>{{ __('results_framework.copy_framework_preview', ['results' => $preview['results'], 'indicators' => $preview['indicators']]) }}</span>
                    </p>
                @endif
            </div>
        @endif
    @endif

    <x-slot:actions>
        @if ($this->copySources->isNotEmpty())
            <x-ui.button type="submit" tone="primary" data-test="review-copy-framework">{{ __('results_framework.copy_framework_review') }}</x-ui.button>
        @endif
    </x-slot:actions>
</x-ui.modal>

<x-ui.confirm-modal
    wire:model="showCopyConfirmModal"
    close="cancelCopyConfirmation"
    tone="neutral"
    :title="__('results_framework.copy_framework_confirm_title')"
    :message="__('results_framework.copy_framework_confirm_message')"
    confirm="copyFramework"
    :confirm-label="__('results_framework.copy_framework_confirm')"
    confirm-test="confirm-copy-framework"
/>
