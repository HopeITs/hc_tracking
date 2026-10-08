{{--
    The in-page file viewer modal shared by the proposal, report and task pages (PreviewsFiles).
    The page passes the open file, its display name, the modal subtitle (type, size, position...),
    the preview and download URLs and how many files it steps through. compact = false gives the
    larger 36px touch targets proposals uses; marker adds the data-preview attribute proposals uses.
--}}
@props([
    'file' => null,
    'name' => '',
    'description' => null,
    'previewUrl' => null,
    'downloadUrl' => null,
    'count' => 0,
    'compact' => true,
    'marker' => false,
])

    <x-ui.modal
        wire:model="showPreviewModal"
        size="xl"
        :title="$name"
        :description="$description"
        close="closePreview"
        :cancel-label="__('common.close')"
        flush
    >
        @if ($file !== null)
            <x-slot:headerActions>
                @if ($count > 1)
                    <x-ui.button tone="ghost" size="sm" icon="chevron-left" class="{{ $compact ? 'size-8!' : 'size-9! touch-manipulation' }} rtl:[&_svg]:-scale-x-100" wire:click="previewAdjacent(-1)" :aria-label="__('reports.previous_file')" />
                    <x-ui.button tone="ghost" size="sm" icon="chevron-right" class="{{ $compact ? 'size-8!' : 'size-9! touch-manipulation' }} rtl:[&_svg]:-scale-x-100" wire:click="previewAdjacent(1)" :aria-label="__('reports.next_file')" />
                @endif
                @if ($file->isPreviewable())
                    <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="inline-flex {{ $compact ? 'size-8' : 'size-9' }} items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink" aria-label="{{ __('reports.open_in_new_tab') }}">
                        <flux:icon name="arrow-top-right-on-square" variant="micro" class="size-4" />
                    </a>
                @endif
                <a href="{{ $downloadUrl }}" class="inline-flex {{ $compact ? 'size-8' : 'size-9' }} items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink" aria-label="{{ __('common.download_name', ['name' => $name]) }}">
                    <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                </a>
            </x-slot:headerActions>

            <div
                class="bg-board-head dark:bg-surface-2"
                wire:key="preview-{{ $file->id }}"
                x-data
                x-on:keydown.left.window="$wire.previewAdjacent(document.dir === 'rtl' ? 1 : -1)"
                x-on:keydown.right.window="$wire.previewAdjacent(document.dir === 'rtl' ? -1 : 1)"{{ $marker ? "\n                data-preview" : '' }}
            >
                @if (! $file->isStored())
                    <x-ui.empty-state icon="eye-slash" :heading="__('reports.preview_not_available')" :body="__('reports.this_record_has_no_stored_file')" />
                @elseif ($file->type === \App\Enums\FileType::Pdf)
                    <iframe src="{{ $previewUrl }}#view=FitH" title="{{ $name }}" class="h-[75dvh] w-full border-0 bg-white" data-test="pdf-preview"></iframe>
                @elseif ($file->isPreviewable())
                    <div class="flex min-h-[50dvh] items-center justify-center p-4">
                        <img src="{{ $previewUrl }}" alt="{{ $name }}" class="max-h-[75dvh] max-w-full rounded-lg object-contain shadow-sm" data-test="image-preview" data-no-dim />
                    </div>
                @else
                    {{-- Word/Excel: rendered client-side by resources/js/file-preview.js from the authenticated download route. --}}
                    <div
                        wire:ignore
                        x-data="officePreview(@js(['url' => $downloadUrl, 'type' => $file->type->value]))"
                        class="flex h-[75dvh] flex-col"
                        data-test="office-preview"
                    >
                        <div x-show="sheets.length > 1" class="flex shrink-0 gap-1 overflow-x-auto border-b border-secondary-soft bg-white px-3 py-2 dark:border-line dark:bg-surface-1">
                            <template x-for="(sheetName, index) in sheets" :key="index">
                                <button
                                    type="button"
                                    x-on:click="showSheet(index)"
                                    x-text="sheetName"
                                    class="shrink-0 rounded-md px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
                                    :class="index === activeSheet ? 'bg-primary dark:bg-ink dark:text-surface-0 text-white' : 'text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2'"
                                ></button>
                            </template>
                        </div>

                        <div x-show="status === 'loading'" class="flex flex-1 items-center justify-center gap-2 text-sm text-secondary-medium dark:text-ink-muted">
                            <flux:icon.loading class="size-5" />
                            {{ __('reports.loading_preview') }}
                        </div>

                        <div x-show="status === 'error'" x-cloak class="flex-1">
                            <x-ui.empty-state icon="exclamation-triangle" :heading="__('reports.preview_not_available')" :body="__('reports.this_file_could_not_be_previewed')">
                                <x-slot:action>
                                    <a href="{{ $downloadUrl }}" class="inline-flex items-center gap-1.5 rounded-lg bg-primary dark:bg-brand dark:hover:bg-brand-hover px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
                                        <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                                        {{ __('common.download_name', ['name' => $name]) }}
                                    </a>
                                </x-slot:action>
                            </x-ui.empty-state>
                        </div>

                        <div x-show="status === 'ready'" x-cloak x-ref="canvas" class="min-h-0 flex-1 overflow-auto"></div>

                        <p x-show="truncated" x-cloak class="shrink-0 border-t border-secondary-soft bg-white px-3 py-2 text-xs text-secondary-medium dark:border-line dark:bg-surface-1 dark:text-ink-muted">{{ __('reports.sheet_preview_truncated', ['rows' => 500]) }}</p>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.modal>
