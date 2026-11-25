{{--
    "Documents" section of proposals/show (stage 3b): this proposal's files
    as a grid of tiles (click opens the existing preview; edit and remove
    use the existing dialogs) with an upload tile, then the funded projects'
    grant paperwork, one fold per project. Payroll proposals show only their
    own files. Included by _show-more.blade.php.
--}}
@php
    $mayUpdate = auth()->user()?->can('update', $this->proposal) === true;
    $projectPaperwork = $this->projectFiles->groupBy('mediable_id');
@endphp

@unless ($this->proposal->isPayroll())
    <div class="pp-lbl">{{ __('proposals.sections.own_files') }}</div>
    @if ($this->proposalFiles->isEmpty())
        <span class="pp-small">{{ __('proposals.no_files_filed_on_this_proposal_yet') }}</span>
    @endif
@endunless

<div class="pp-files" data-own-files>
    @foreach ($this->proposalFiles as $media)
        @php
            $isStored = $media->isStored();
        @endphp
        <div class="pp-file" wire:key="sec-file-{{ $media->id }}" data-file="{{ $media->id }}">
            @if ($isStored)
                <button type="button" class="pp-file-open" wire:click="openPreview({{ $media->id }})" aria-label="{{ __('reports.preview_name', ['name' => $media->original_name]) }}" data-test="preview-file-{{ $media->id }}">
                    <span @class(['pp-ext', 'pp-ext-pdf' => $media->type === \App\Enums\FileType::Pdf])>{{ \Illuminate\Support\Str::upper($media->type->value) }}</span>
                    <span class="pp-file-nm" title="{{ $media->original_name }}">{{ $media->original_name }}</span>
                    <span class="pp-small">{{ $media->collection->label() }}</span>
                </button>
            @else
                <div class="pp-file-open">
                    <span class="pp-ext">{{ \Illuminate\Support\Str::upper($media->type->value) }}</span>
                    <span class="pp-file-nm" title="{{ $media->original_name }}">{{ $media->original_name }}</span>
                    <span class="pp-small">{{ $media->collection->label() }} · {{ __('reports.reference_only') }}</span>
                </div>
            @endif
            <span class="pp-file-acts">
                @if ($isStored)
                    <a href="{{ $this->documentDownloadUrl($media) }}" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}" data-test="download-file-{{ $media->id }}"><flux:icon.arrow-down-tray variant="micro" class="size-4" aria-hidden="true" /></a>
                @endif
                @if ($mayUpdate)
                    <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm" wire:click="openEditFileModal({{ $media->id }})" aria-label="{{ __('common.edit_name', ['name' => $media->original_name]) }}" data-test="edit-file-{{ $media->id }}"><flux:icon.pencil-square variant="micro" class="size-4" aria-hidden="true" /></button>
                    <button type="button" class="pp-btn pp-btn-ghost pp-btn-sm" wire:click="confirmDeleteFile({{ $media->id }})" aria-label="{{ __('common.delete_name', ['name' => $media->original_name]) }}" data-test="delete-file-{{ $media->id }}"><flux:icon.trash variant="micro" class="size-4" aria-hidden="true" /></button>
                @endif
            </span>
        </div>
    @endforeach
    @if ($mayUpdate)
        <button type="button" class="pp-file pp-file-add" wire:click="openCreateFileModal" data-test="upload-proposal-file">
            <flux:icon.plus variant="micro" class="size-5" aria-hidden="true" />
            <span>{{ __('proposals.upload_file') }}</span>
        </button>
    @endif
</div>

@unless ($this->proposal->isPayroll())
    <div class="pp-lbl pp-lbl-gap">{{ __('proposals.sections.project_paperwork') }}</div>
    <div data-project-files class="pp-plan">
        @forelse ($projectPaperwork as $projectFiles)
            @php
                $owner = $projectFiles->first()->mediable;
            @endphp
            <details class="pp-sub" wire:ignore.self wire:key="sec-paperwork-{{ $owner->id }}">
                <summary>
                    <span class="pp-grow" dir="auto">{{ $owner->headline() }}</span>
                    <span class="pp-small">{{ trans_choice('proposals.sections.n_files', $projectFiles->count(), ['count' => $projectFiles->count()]) }}</span>
                    <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </summary>
                <div class="pp-sub-b">
                    @foreach ($projectFiles as $media)
                        <div class="pp-lrow" wire:key="sec-project-file-{{ $media->id }}" data-file="{{ $media->id }}">
                            <span class="pp-lrow-s">
                                @if ($media->isStored())
                                    <button type="button" class="pp-file-link" wire:click="openPreview({{ $media->id }})" title="{{ $media->original_name }}"><b>{{ $media->original_name }}</b></button>
                                @else
                                    <b>{{ $media->original_name }}</b>
                                @endif
                                <span class="pp-small">{{ $media->collection->label() }}</span>
                            </span>
                            <span class="pp-lrow-r">
                                @if ($media->isStored())
                                    <a href="{{ $this->documentDownloadUrl($media) }}" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="{{ __('common.download_name', ['name' => $media->original_name]) }}" data-test="download-file-{{ $media->id }}"><flux:icon.arrow-down-tray variant="micro" class="size-4" aria-hidden="true" /></a>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            </details>
        @empty
            <div class="pp-empty">{{ __('proposals.no_grant_paperwork_on_funded_projects') }}</div>
        @endforelse
    </div>
@endunless
