{{--
    The Status tab of proposals/show (the default tab): the status history
    and the notes. Included by ⚡show.blade.php, whose top PHP block defines
    $mutedText, $inkText, $hairline, $amount, $term, $chronology and
    $arrivals.
--}}
            @include('pages.proposals._show-reviewers')

            {{-- Why the status moved, and whatever was written down about this proposal. --}}
            <div class="mt-10 grid gap-x-10 gap-y-8 @3xl/detail:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <section aria-labelledby="moves-heading" class="min-w-0" data-status-history>
                    <h2 id="moves-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.status_history') }}</h2>
                    <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.the_audit_trail_for_this_proposal_s_status_newest_first') }}</p>

                    @if ($this->statusNotes->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">
                            <span class="font-semibold {{ $inkText }}">{{ __('proposals.no_status_history_yet') }}</span>
                            {{ __('proposals.use_change_status_to_record_the_first_movement_and_why_it_ha') }}
                        </p>
                    @else
                        <ol class="mt-4 flex flex-col">
                            @foreach ($this->statusNotes as $note)
                                <li class="relative flex min-w-0 gap-3 pb-5 last:pb-0" wire:key="status-note-{{ $note->id }}">
                                    <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                        @unless ($loop->last)
                                            <span class="absolute top-3 bottom-[-1.25rem] w-px bg-secondary-soft dark:bg-line-strong"></span>
                                        @endunless
                                        <span @class(['relative z-10 mt-1.5 size-2.5 rounded-full', 'bg-primary dark:bg-brand' => $loop->first, 'bg-secondary-light' => ! $loop->first])></span>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                            <x-ui.enum-badge :case="$note->status" />
                                            <time datetime="{{ $note->date?->toDateString() }}" class="text-xs tabular-nums {{ $mutedText }}">{{ $note->date?->translatedFormat(__('formats.date')) }}</time>
                                        </div>
                                        @if ($note->context !== null)
                                            <p class="mt-1.5 text-sm {{ $inkText }}">{{ $note->context }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                <section aria-labelledby="notes-heading" class="min-w-0" data-notes>
                    <h2 id="notes-heading" class="text-lg font-bold {{ $inkText }}">{{ __('common.notes') }}</h2>
                    @if (filled($this->proposal->notes))
                        <p class="mt-3 max-w-[70ch] text-sm leading-relaxed whitespace-pre-line {{ $inkText }}">{{ $this->proposal->notes }}</p>
                    @else
                        <p class="mt-3 text-sm {{ $mutedText }}">{{ __('proposals.nothing_was_written_down_about_this_proposal') }}</p>
                    @endif
                    <p class="mt-5 text-xs tabular-nums {{ $mutedText }}">{{ __('common.updated_date', ['date' => $this->proposal->updated_at?->translatedFormat(__('formats.date')) ?? '—']) }}</p>
                </section>
            </div>
