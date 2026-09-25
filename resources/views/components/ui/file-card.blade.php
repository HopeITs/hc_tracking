@props(['media', 'project', 'editable' => true])

@php
    $icon = match ($media->type) {
        \App\Enums\FileType::Xlsx => 'table-cells',
        \App\Enums\FileType::Jpg, \App\Enums\FileType::Png => 'photo',
        default => 'document-text',
    };

    $categoryTone = match ($media->collection) {
        \App\Enums\MediaCollection::Proposal => 'border-primary/25 bg-status-critical-soft text-primary',
        \App\Enums\MediaCollection::Agreement => 'border-funding/25 bg-funding-soft text-funding',
        \App\Enums\MediaCollection::Mou => 'border-status-good/25 bg-status-good-soft text-status-good',
        \App\Enums\MediaCollection::Evidence => 'border-status-warn/25 bg-status-warn-soft text-status-warn',
        \App\Enums\MediaCollection::Photo => 'border-secondary-dark/20 bg-secondary-dark text-white dark:border-white/10 dark:bg-secondary-medium',
        \App\Enums\MediaCollection::Other => 'border-secondary-light text-secondary-medium dark:text-secondary-soft',
    };
@endphp

<article {{ $attributes->class('group flex flex-col gap-3 rounded-2xl border border-secondary-soft bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] transition-all hover:-translate-y-0.5 hover:shadow-[0_2px_4px_rgba(16,24,40,0.06),0_4px_8px_rgba(16,24,40,0.08)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none') }}>
    <div class="flex items-start gap-3">
        <div class="flex size-12 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border border-secondary-soft bg-board-head transition-transform group-hover:scale-105 dark:border-secondary-medium dark:bg-secondary-medium/30">
            <flux:icon :name="$icon" variant="micro" class="size-4 text-secondary-medium dark:text-secondary-soft" />
            <span class="text-[10px] font-bold text-secondary-dark dark:text-white">{{ \Illuminate\Support\Str::upper($media->type->value) }}</span>
        </div>

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white" title="{{ $media->original_name }}">{{ $media->original_name }}</p>
            <span class="mt-1.5 inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap {{ $categoryTone }}">
                {{ $media->collection->label() }}
            </span>
        </div>
    </div>

    <div class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-secondary-soft pt-3 dark:border-secondary-medium">
        <div class="flex min-w-0 items-center gap-1.5">
            @if ($media->uploader === null)
                <span class="text-xs text-secondary-medium dark:text-secondary-soft">{{ __('common.not_assigned') }}</span>
            @else
                <flux:avatar size="xs" :name="$media->uploader->name" :initials="$media->uploader->initials()" />
                <span class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $media->uploader->name }}</span>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
            @if ($media->size !== null)
                <span class="tabular-nums">{{ \Illuminate\Support\Number::fileSize($media->size, precision: 1) }}</span>
                <span aria-hidden="true">&middot;</span>
            @endif
            <time datetime="{{ $media->uploaded_at?->toDateString() }}">{{ $media->uploaded_at?->translatedFormat(__('formats.date')) ?? '—' }}</time>
        </div>
    </div>

    <div class="flex items-center justify-end gap-1 border-t border-secondary-soft pt-2 dark:border-secondary-medium">
        <a
            href="{{ route('projects.files.download', [$project, $media]) }}"
            class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-secondary-medium dark:hover:text-white"
            aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}"
        >
            <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
        </a>
        @if ($editable)
            <x-ui.button
                tone="ghost"
                size="sm"
                icon="pencil-square"
                class="size-8!"
                wire:click="openEditFileModal({{ $media->id }})"
                :aria-label="__('common.edit_name', ['name' => $media->original_name])"
            />
            <x-ui.button
                tone="ghost"
                size="sm"
                icon="trash"
                class="size-8! text-primary! hover:bg-status-critical-soft!"
                wire:click="confirmDeleteFile({{ $media->id }})"
                :aria-label="__('common.delete_name', ['name' => $media->original_name])"
            />
        @endif
    </div>
</article>
