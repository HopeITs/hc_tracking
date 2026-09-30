@props([
    'label',
    'id' => null,
    'required' => false,
    'help' => null,
    'accept' => null,
    'types' => null,
    'maxSize' => null,
])

@php
    /*
     * A drop-or-browse file field. The real <input type="file" wire:model>
     * sits inside the drop zone's label, so clicking, the keyboard and screen
     * readers all use the native picker; a dropped file is handed to that same
     * input. Livewire's upload events drive the progress row, and they bubble
     * up to x-ui.modal, which keeps the submit actions disabled until the
     * upload has landed.
     */
    /* Livewire's wire() macro answers false, not null, when there is no binding. */
    $model = (string) $attributes->wire('model')->value();

    if ($model === '') {
        throw new InvalidArgumentException('The x-ui.form-file component requires a wire:model binding.');
    }

    $livewire = $__env->shared('__livewire');
    $file = $livewire !== null ? data_get($livewire, $model) : null;
    $fileName = $file instanceof Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? $file->getClientOriginalName() : null;

    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $errors->first($model);
    $allowed = collect([$types, $maxSize !== null ? __('common.up_to_size', ['size' => $maxSize]) : null])->filter()->implode(' · ');
    $prompt = str_replace(
        ['__DROP__', '__BROWSE__'],
        [
            '<span class="font-semibold">'.e(__('common.drop_a_file_here')).'</span>',
            '<span class="form-file-browse">'.e(__('common.browse')).'</span>',
        ],
        e(__('common.drop_or_browse', ['drop' => '__DROP__', 'browse' => '__BROWSE__'])),
    );
    $describedBy = collect([
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')" data-form-file="{{ $model }}">
    <div
        class="form-file"
        @if ($error !== '') data-invalid @endif
        x-data="formFile(@js($fileName), @js($model), @js(__('common.uploaded_of_total', ['loaded' => '__LOADED__', 'total' => '__TOTAL__'])))"
        x-on:livewire-upload-start="started()"
        x-on:livewire-upload-progress="progressed($event.detail)"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="cleared()"
        x-on:livewire-upload-cancel="cleared()"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="dropped($event)"
        x-bind:data-dragging="dragging ? 'true' : null"
    >
        <label class="form-file-zone" x-show="name === null" @if ($fileName !== null) style="display: none" @endif>
            <input
                id="{{ $id }}"
                type="file"
                class="sr-only"
                x-ref="input"
                x-on:change="picked($event)"
                {{ $attributes->whereStartsWith('wire:model') }}
                @if ($accept !== null) accept="{{ $accept }}" @endif
                @if ($required) aria-required="true" @endif
                @if ($error !== '') aria-invalid="true" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            />

            <span class="form-file-icon" aria-hidden="true">
                <flux:icon.cloud-arrow-up class="size-5" />
            </span>

            <span class="min-w-0 text-sm">
                <span class="block">{!! $prompt !!}</span>

                @if ($allowed !== '')
                    <span class="form-help block">{{ $allowed }}</span>
                @endif
            </span>
        </label>

        <div class="form-file-chosen" x-show="name !== null" @if ($fileName === null) style="display: none" @endif>
            <span class="form-file-doc" aria-hidden="true">
                <flux:icon.document class="size-5" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold" x-text="name">{{ $fileName }}</p>

                <div class="mt-1.5 flex items-center gap-2" x-show="uploading" style="display: none">
                    <span class="form-file-track"><span class="form-file-bar" x-bind:style="`width: ${progress}%`"></span></span>
                    <span class="text-xs tabular-nums text-secondary-medium dark:text-ink-muted" x-text="sizeLabel"></span>
                </div>
            </div>

            <button type="button" class="form-file-remove" x-on:click="remove()" aria-label="{{ __('common.remove_file') }}">
                <flux:icon.x-mark class="size-4" />
            </button>
        </div>
    </div>
</x-ui.form-field>
