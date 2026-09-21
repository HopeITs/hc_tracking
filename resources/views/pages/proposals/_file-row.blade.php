{{--
    One document on the proposal page's documents tab, laid out like a report's supporting file.
    $owner is the funded project a read-only file belongs to, or null for a file filed on the proposal itself.
--}}
@php
    $isStored = $media->isStored();
    $fileIcon = match ($media->type) {
        \App\Enums\FileType::Xlsx => 'table-cells',
        \App\Enums\FileType::Jpg, \App\Enums\FileType::Png => 'photo',
        default => 'document-text',
    };
@endphp
<li class="flex min-w-0 flex-wrap items-center gap-3 py-3" wire:key="file-{{ $media->mediable_type }}-{{ $media->id }}" data-file="{{ $media->id }}">
    @if ($isStored && in_array($media->type, [\App\Enums\FileType::Jpg, \App\Enums\FileType::Png], true))
        <button type="button" wire:click="openPreview({{ $media->id }})" class="size-10 shrink-0 touch-manipulation overflow-hidden rounded-xl border border-secondary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:border-secondary-medium" aria-label="{{ __('reports.preview_name', ['name' => $media->original_name]) }}">
            <img src="{{ route('proposals.files.preview', [$proposalId, $media]) }}" alt="" loading="lazy" class="size-full object-cover" />
        </button>
    @else
        <span class="inline-flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border border-secondary-soft bg-board-head dark:border-secondary-medium dark:bg-secondary-medium/30" aria-hidden="true">
            <flux:icon :name="$fileIcon" variant="micro" class="size-3.5 {{ $mutedText }}" />
            <span class="text-[10px] font-bold {{ $inkText }}">{{ \Illuminate\Support\Str::upper($media->type->value) }}</span>
        </span>
    @endif

    <div class="min-w-0 flex-1">
        @if ($isStored)
            <button type="button" wire:click="openPreview({{ $media->id }})" class="block max-w-full touch-manipulation truncate text-start text-sm font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}" title="{{ $media->original_name }}" data-test="preview-file-name">{{ $media->original_name }}</button>
        @else
            <p class="truncate text-sm font-semibold {{ $inkText }}" title="{{ $media->original_name }}">{{ $media->original_name }}</p>
        @endif
        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
            <span class="font-semibold">{{ $media->collection->label() }}</span>
            @if ($owner !== null)
                <span aria-hidden="true">·</span>
                <a href="{{ route('projects.show', $owner) }}" wire:navigate class="underline-offset-4 hover:underline">{{ $owner->name }}</a>
            @endif
            @if ($media->size !== null)
                <span aria-hidden="true">·</span>
                <span>{{ \Illuminate\Support\Number::fileSize($media->size, precision: 1) }}</span>
            @endif
            <span aria-hidden="true">·</span>
            <time datetime="{{ $media->uploaded_at?->toDateString() }}">{{ $media->uploaded_at?->translatedFormat(__('formats.date')) ?? '—' }}</time>
            @if ($media->uploader !== null)
                <span aria-hidden="true">·</span>
                <span class="truncate">{{ $media->uploader->name }}</span>
            @endif
            @unless ($isStored)
                <span class="rounded-md border border-secondary-light px-1.5 text-[11px] font-semibold">{{ __('reports.reference_only') }}</span>
            @endunless
        </p>
    </div>

    <div class="flex shrink-0 items-center gap-0.5">
        @if ($isStored)
            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9! touch-manipulation" wire:click="openPreview({{ $media->id }})" :aria-label="__('reports.preview_name', ['name' => $media->original_name])" data-test="preview-file-{{ $media->id }}" />
            <a
                href="{{ $downloadUrl }}"
                class="inline-flex size-9 touch-manipulation items-center justify-center rounded-lg transition-colors hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:hover:bg-secondary-medium {{ $mutedText }}"
                aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}"
                data-test="download-file-{{ $media->id }}"
            >
                <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
            </a>
        @endif
        @if ($editable)
            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openEditFileModal({{ $media->id }})" :aria-label="__('common.edit_name', ['name' => $media->original_name])" data-test="edit-file-{{ $media->id }}" />
            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9! touch-manipulation text-status-critical! hover:bg-status-critical-soft!" wire:click="confirmDeleteFile({{ $media->id }})" :aria-label="__('common.delete_name', ['name' => $media->original_name])" data-test="delete-file-{{ $media->id }}" />
        @endif
    </div>
</li>
