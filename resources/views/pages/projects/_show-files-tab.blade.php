{{--
    The side column's files block (desk.js sideBlocks): a category filter as
    plain words, then each file as one line with its download, edit and
    delete actions. Funding papers are already left out of project() for
    viewers without funding-agreements.view.
--}}
@php
    $canEditFiles = auth()->user()->can('update', $this->project);
@endphp
<div data-test="story-files">
    @if ($this->project->media->isEmpty())
        <p class="dk-small"><strong>{{ __('common.no_files_recorded') }}</strong></p>
        <p class="dk-small dk-muted">{{ __('projects.file_records_for_this_project_will_appear_here') }}</p>
    @else
        @if (count($this->fileCategoryCounts) > 2)
            <div class="dk-fchips" role="group" aria-label="{{ __('projects.file_category') }}">
                @foreach ($this->fileCategoryCounts as $category)
                    @php
                        $isActive = $fileCategoryFilter === $category['value'];
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('fileCategoryFilter', '{{ $category['value'] }}')"
                        wire:key="project-file-filter-{{ $category['value'] }}"
                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                    >{{ $category['label'] }} <span class="dk-num">{{ $category['count'] }}</span></button>
                @endforeach
            </div>
        @endif

        <ul class="dk-plist dk-tight">
            @forelse ($this->filteredProjectFiles as $media)
                <li wire:key="project-file-{{ $media->id }}">
                    <div>
                        <strong dir="auto" title="{{ $media->original_name }}">{{ $media->original_name }}</strong>
                        <span class="dk-is">
                            {{ $media->collection->label() }} · {{ \Illuminate\Support\Str::upper($media->type->value) }}
                            @if ($media->size !== null)
                                · <span class="dk-num">{{ \Illuminate\Support\Number::fileSize($media->size, precision: 1) }}</span>
                            @endif
                            · <time datetime="{{ $media->uploaded_at?->toDateString() }}">{{ $media->uploaded_at?->translatedFormat(__('formats.date')) ?? '—' }}</time>
                            · {{ $media->uploader?->name ?? __('common.not_assigned') }}
                        </span>
                    </div>
                    <span class="dk-pr-r">
                        <a class="dk-iconbtn dk-sm" href="{{ route('projects.files.download', [$this->project, $media]) }}" aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}" title="{{ __('common.download_name', ['name' => $media->original_name]) }}"><x-desk.icon name="download" /></a>
                        @if ($canEditFiles)
                            <button type="button" class="dk-iconbtn dk-sm" wire:click="openEditFileModal({{ $media->id }})" aria-label="{{ __('common.edit_name', ['name' => $media->original_name]) }}" title="{{ __('common.edit_name', ['name' => $media->original_name]) }}"><x-desk.icon name="edit" /></button>
                            <button type="button" class="dk-iconbtn dk-sm dk-danger" wire:click="confirmDeleteFile({{ $media->id }})" aria-label="{{ __('common.delete_name', ['name' => $media->original_name]) }}" title="{{ __('common.delete_name', ['name' => $media->original_name]) }}"><x-desk.icon name="x" /></button>
                        @endif
                    </span>
                </li>
            @empty
                <li class="dk-muted dk-small">{{ __('projects.no_files_in_this_category') }}</li>
            @endforelse
        </ul>
    @endif

    @if ($canEditFiles)
        <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="openCreateFileModal" data-test="add-project-file-button"><x-desk.icon name="plus" />{{ __('projects.add_file') }}</button>
    @endif
</div>
