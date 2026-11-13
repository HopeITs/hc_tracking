{{--
    The concept note (W-24, D-62) on the Status tab of proposals/show: the text
    from `proposals.concept_note` and the documents filed under
    MediaCollection::ConceptNote, under the proposal's own title, its donor and
    the date it was created.

    It opens unfolded in every phase (user report 2026-11-12: the folded line
    was hard to open); from the planning phase on its title can fold it away.
    wire:ignore.self keeps the reader's choice across Livewire updates. Included by _show-status-tab.blade.php,
    which inherits $mutedText, $inkText and $hairline from ⚡show.blade.php.

    The text is printed, never rendered: {{ }} escapes it and the paragraphs
    come from splitting on blank lines, so no markup from a donor's note can
    reach the page.
--}}
@php
    $conceptNote = $this->proposal->concept_note;
    $conceptNoteFiles = $this->conceptNoteFiles;
    $isIdeation = $this->proposal->phase === \App\Enums\ProposalPhase::Ideation;
    $hasConceptNote = filled($conceptNote) || $conceptNoteFiles->isNotEmpty();

    /* One paragraph per block of text, blank lines dropped. */
    $conceptNoteParagraphs = filled($conceptNote)
        ? array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $conceptNote) ?: []), fn (string $part): bool => $part !== ''))
        : [];
@endphp

@if ($isIdeation || $hasConceptNote)
    <x-ui.panel
        :title="$isIdeation ? __('proposals.concept_note.title') : null"
        class="mb-8"
        data-concept-note
        x-data="{ open: true }"
        wire:ignore.self
    >
        @unless ($isIdeation)
            {{-- Folded: one line that expands, so a later phase keeps the note within reach. --}}
            <button
                type="button"
                class="flex w-full cursor-pointer items-center gap-2 px-4 py-3 text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                aria-controls="concept-note-body"
                data-concept-note-toggle
            >
                <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 rtl:-scale-x-100 {{ $mutedText }}" x-bind:class="open && 'rotate-90 rtl:rotate-90'" style="transition: transform 150ms" aria-hidden="true" />
                <span class="text-base font-bold {{ $inkText }}">{{ __('proposals.concept_note.title') }}</span>
                <span aria-hidden="true" class="{{ $mutedText }}">·</span>
                <span class="text-sm underline underline-offset-4 {{ $mutedText }}">{{ __('proposals.concept_note.view') }}</span>
            </button>
        @endunless

        <div id="concept-note-body" class="px-4 py-4" @unless ($isIdeation) x-show="open" @endunless>
            <h3 class="text-base font-bold {{ $inkText }}" dir="auto">{{ $this->proposal->name }}</h3>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs {{ $mutedText }}">
                <span dir="auto">{{ __('proposals.concept_note.donor_label') }}: {{ $this->proposal->donor->name }}</span>
                <span aria-hidden="true">·</span>
                <span class="tabular-nums">
                    {{ __('proposals.concept_note.created_label') }}:
                    <time datetime="{{ $this->proposal->created_at?->toDateString() }}">{{ $this->proposal->created_at?->translatedFormat(__('formats.date')) ?? '—' }}</time>
                </span>
            </p>

            @if ($conceptNoteParagraphs !== [])
                {{--
                    dir="auto" sits on each paragraph, not on the wrapper: a note that opens in
                    Arabic and carries an English paragraph would otherwise render that paragraph
                    right to left, with its full stop on the wrong side.
                --}}
                <div class="mt-3 flex max-w-[70ch] flex-col gap-3" data-concept-note-text>
                    @foreach ($conceptNoteParagraphs as $paragraph)
                        <p class="text-sm leading-relaxed whitespace-pre-line {{ $inkText }}" dir="auto">{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endif

            @if ($conceptNoteFiles->isNotEmpty())
                <ul class="mt-2 divide-y {{ $hairline }}" data-concept-note-files>
                    @foreach ($conceptNoteFiles as $media)
                        @include('pages.proposals._file-row', [
                            'media' => $media,
                            'proposal' => $this->proposal,
                            'downloadUrl' => route('proposals.files.download', [$this->proposal, $media]),
                            'owner' => null,
                            'editable' => false,
                            'inkText' => $inkText,
                            'mutedText' => $mutedText,
                        ])
                    @endforeach
                </ul>
            @endif

            @unless ($hasConceptNote)
                <p class="mt-3 text-sm {{ $mutedText }}">{{ __('proposals.concept_note.nothing_recorded') }}</p>
            @endunless
        </div>
    </x-ui.panel>
@endif
