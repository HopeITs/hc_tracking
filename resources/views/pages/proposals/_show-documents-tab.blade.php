{{--
    The Documents tab of proposals/show: the proposal's own files and the
    funded projects' grant paperwork. Included by ⚡show.blade.php, whose top
    PHP block defines $mutedText, $inkText, $hairline, $amount, $term,
    $chronology and $arrivals.
--}}
            <div class="flex flex-col gap-10" data-documents>
                <section class="min-w-0" aria-labelledby="own-files-heading" data-own-files>
                    <div class="flex flex-wrap items-end justify-between gap-3 border-b pb-3 {{ $hairline }}">
                        <div class="min-w-0">
                            <h2 id="own-files-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.filed_on_this_proposal') }}</h2>
                            <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.concept_note_proposal_agreement_mou_and_anything_else') }}</p>
                        </div>
                        @can('update', $this->proposal)
                        <x-ui.button tone="primary" size="sm" icon="arrow-up-tray" class="min-h-10 touch-manipulation" wire:click="openCreateFileModal" data-test="upload-proposal-file">{{ __('proposals.upload_file') }}</x-ui.button>
                        @endcan
                    </div>

                    @if ($this->proposalFiles->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_files_filed_on_this_proposal_yet') }}</p>
                    @else
                        <ul class="divide-y {{ $hairline }}">
                            @foreach ($this->proposalFiles as $media)
                                @include('pages.proposals._file-row', ['media' => $media, 'proposal' => $this->proposal, 'downloadUrl' => $this->documentDownloadUrl($media), 'owner' => null, 'editable' => auth()->user()->can('update', $this->proposal), 'inkText' => $inkText, 'mutedText' => $mutedText])
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="min-w-0" aria-labelledby="project-files-heading" data-project-files>
                    <div class="border-b pb-3 {{ $hairline }}">
                        <h2 id="project-files-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.from_funded_projects') }}</h2>
                        <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.grant_paperwork_filed_on_the_projects_this_proposal_funds') }}</p>
                    </div>

                    @if ($this->projectFiles->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_grant_paperwork_on_funded_projects') }}</p>
                    @else
                        <ul class="divide-y {{ $hairline }}">
                            @foreach ($this->projectFiles as $media)
                                @include('pages.proposals._file-row', ['media' => $media, 'proposal' => $this->proposal, 'downloadUrl' => $this->documentDownloadUrl($media), 'owner' => $media->mediable, 'editable' => false, 'inkText' => $inkText, 'mutedText' => $mutedText])
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
