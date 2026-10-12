{{-- The supporting-files panel on ⚡show.blade.php: the file list with preview, download, edit and delete. --}}
@use('App\Enums\FileType')
@use('Illuminate\Support\Str')
    <x-ui.panel :title="__('reports.supporting_files')" :count="$this->files->count()" :subtitle="__('reports.supporting_files_subtitle')">
        @can('update', $report)
            <x-slot:toolbar>
                <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openCreateFileModal" data-test="create-file-button">
                    {{ __('reports.upload_file') }}
                </x-ui.button>
            </x-slot:toolbar>
        @endcan

        @if ($this->files->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('reports.supporting_files_for_this_report_will_appear_here')">
                @can('update', $report)
                    <x-slot:action>
                        <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openCreateFileModal">{{ __('reports.upload_file') }}</x-ui.button>
                    </x-slot:action>
                @endcan
            </x-ui.empty-state>
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($this->files as $file)
                    @php
                        $fileName = $file->displayName();
                        $isStored = $file->isStored();
                        $fileIcon = match ($file->type) {
                            FileType::Xlsx => 'table-cells',
                            FileType::Jpg, FileType::Png => 'photo',
                            default => 'document-text',
                        };
                    @endphp
                    <li class="flex flex-wrap items-center gap-3 p-3.5" wire:key="report-file-{{ $file->id }}">
                        @if ($isStored && in_array($file->type, [FileType::Jpg, FileType::Png], true))
                            <button type="button" wire:click="openPreview({{ $file->id }})" class="size-10 shrink-0 overflow-hidden rounded-xl border border-secondary-soft dark:border-line" aria-label="{{ __('reports.preview_name', ['name' => $fileName]) }}">
                                <img src="{{ route('reports.files.preview', [$report, $file]) }}" alt="" loading="lazy" class="size-full object-cover" data-no-dim />
                            </button>
                        @else
                            <span class="inline-flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl border border-secondary-soft bg-board-head dark:border-line dark:bg-surface-2">
                                <flux:icon :name="$fileIcon" variant="micro" class="size-3.5 text-secondary-medium dark:text-ink-muted" />
                                <span class="text-[10px] font-bold text-secondary-dark dark:text-ink">{{ Str::upper($file->type->value) }}</span>
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            @if ($isStored)
                                <button type="button" wire:click="openPreview({{ $file->id }})" class="block max-w-full truncate text-start text-sm font-medium hover:text-primary dark:hover:text-brand-text hover:underline" title="{{ $fileName }}" data-test="preview-file-name">{{ $fileName }}</button>
                            @else
                                <p class="truncate text-sm font-medium" title="{{ $fileName }}">{{ $fileName }}</p>
                            @endif
                            <p class="flex flex-wrap items-center gap-x-1.5 text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                <time datetime="{{ $file->date?->toDateString() }}">{{ $file->date?->translatedFormat(__('formats.date')) }}</time>
                                @if ($file->size !== null)
                                    <span aria-hidden="true">&middot;</span>
                                    <span>{{ \Illuminate\Support\Number::fileSize($file->size, precision: 1) }}</span>
                                @endif
                                @if ($file->uploader !== null)
                                    <span aria-hidden="true">&middot;</span>
                                    <span class="truncate">{{ $file->uploader->name }}</span>
                                @endif
                                @unless ($isStored)
                                    <span class="rounded-md border border-secondary-light px-1.5 text-[11px] font-semibold">{{ __('reports.reference_only') }}</span>
                                @endunless
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            @if ($isStored)
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" wire:click="openPreview({{ $file->id }})" :aria-label="__('reports.preview_name', ['name' => $fileName])" data-test="preview-file-button" />
                                <a
                                    href="{{ route('reports.files.download', [$report, $file]) }}"
                                    class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium transition-colors hover:bg-secondary-soft hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-surface-2 dark:hover:text-ink"
                                    aria-label="{{ __('common.download_name', ['name' => $fileName]) }}"
                                    data-test="download-file-link"
                                >
                                    <flux:icon name="arrow-down-tray" variant="micro" class="size-4" />
                                </a>
                            @endif

                            @can('update', $report)
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditFileModal({{ $file->id }})" :aria-label="__('common.edit_name', ['name' => $fileName])" data-test="edit-file-button" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8! text-primary! dark:text-brand-text! hover:bg-status-critical-soft!" wire:click="confirmDeleteFile({{ $file->id }})" :aria-label="__('common.delete_name', ['name' => $fileName])" data-test="delete-file-button" />
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>
