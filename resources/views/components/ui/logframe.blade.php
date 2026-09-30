@props(['roots', 'summary'])

@php
    use App\Enums\ResultType;

    $levelMarks = [
        ResultType::Impact->value => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome->value => 'bg-primary ring-primary',
        ResultType::Output->value => 'bg-board ring-secondary-light',
    ];
@endphp

<section {{ $attributes->class('overflow-hidden rounded-md border border-board-line bg-board') }} aria-labelledby="program-results-heading">
    <header class="flex flex-col gap-5 border-b border-board-line bg-board-head px-5 py-5 @4xl/detail:flex-row @4xl/detail:items-end @4xl/detail:justify-between">
        <div class="max-w-xl">
            <h2 id="program-results-heading" class="text-xl font-bold text-balance">{{ __('programs.results_logframe') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.impact_outcome_and_output_statements_nested_as_recorded') }}</p>
        </div>

        <div class="flex flex-col gap-2 @4xl/detail:items-end">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-2" aria-label="{{ __('programs.logframe_results_chain') }}">
                @foreach (ResultType::cases() as $type)
                    <li class="flex items-center gap-2">
                        <span class="flex items-center gap-2 rounded-full border border-board-line bg-board py-1 ps-2 pe-3">
                            <span aria-hidden="true" class="size-2.5 rounded-full ring-2 ring-inset {{ $levelMarks[$type->value] }}"></span>
                            <span class="text-sm font-semibold">{{ $type->label() }}</span>
                            <span class="text-sm font-bold tabular-nums text-secondary-medium dark:text-ink-muted">{{ $summary['levels'][$type->value] }}</span>
                        </span>
                        @unless ($loop->last)
                            <flux:icon.arrow-long-right variant="micro" class="size-4 text-secondary-light rtl:rotate-180" aria-hidden="true" />
                        @endunless
                    </li>
                @endforeach
            </ol>
            @if ($roots->isNotEmpty())
                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-ink-muted">
                    <span class="inline-flex items-center gap-1"><flux:icon.chart-bar variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.logframe_count_indicators', $summary['indicators'], ['count' => $summary['indicators']]) }}</span>
                    <span @class(['inline-flex items-center gap-1', 'text-status-warn' => $summary['unmeasured'] > 0, 'text-status-good' => $summary['unmeasured'] === 0])>
                        <flux:icon :name="$summary['unmeasured'] > 0 ? 'exclamation-triangle' : 'check-circle'" variant="micro" class="size-3.5" aria-hidden="true" />{{ trans_choice('programs.logframe_count_unmeasured', $summary['unmeasured'], ['count' => $summary['unmeasured']]) }}
                    </span>
                </p>
            @endif
        </div>
    </header>

    @if ($roots->isEmpty())
        <x-ui.empty-state icon="chart-bar" :heading="__('common.no_results_yet')" :body="__('programs.logframe_statements_assigned_to_this_program_will_appear_her')" />
    @else
        <div class="space-y-10 bg-ground px-4 py-8 @3xl/detail:px-8 @3xl/detail:py-10">
            @foreach ($roots as $root)
                <div wire:key="logframe-root-{{ $root->id }}">
                    <div class="relative mx-auto max-w-3xl">
                        <x-ui.logframe-node :result="$root" :depth="0" />
                    </div>

                    @if ($root->childrenRecursive->isEmpty())
                        <p class="mx-auto mt-4 max-w-3xl rounded-md border border-dashed border-secondary-light px-4 py-3 text-center text-sm text-secondary-medium dark:text-ink-muted">{{ __('programs.logframe_no_contributing_results') }}</p>
                    @else
                        <div aria-hidden="true" class="mx-auto hidden h-8 w-px bg-secondary-light @3xl/detail:block"></div>

                        <div
                            class="relative"
                            x-data="{
                                atStart: true,
                                atEnd: true,
                                measure() {
                                    const track = this.$refs.track;
                                    const offset = Math.abs(track.scrollLeft);
                                    this.atStart = offset < 4;
                                    this.atEnd = offset + track.clientWidth >= track.scrollWidth - 4;
                                },
                                step(direction) {
                                    const track = this.$refs.track;
                                    const sign = getComputedStyle(track).direction === 'rtl' ? -1 : 1;
                                    track.scrollBy({ left: direction * sign * track.clientWidth * 0.8, behavior: 'smooth' });
                                },
                            }"
                            x-init="$nextTick(() => measure())"
                            x-on:resize.window.debounce.100ms="measure()"
                        >
                        <ol x-ref="track" x-on:scroll.passive="measure()" class="relative mt-4 grid gap-5 ps-7 [scrollbar-width:thin] @3xl/detail:mt-0 @3xl/detail:auto-cols-[minmax(17rem,26rem)] @3xl/detail:grid-flow-col @3xl/detail:justify-center-safe @3xl/detail:overflow-x-auto @3xl/detail:ps-0 @3xl/detail:pb-3">
                            <span aria-hidden="true" class="absolute start-3 -top-4 bottom-8 w-px bg-secondary-light @3xl/detail:hidden"></span>

                            @foreach ($root->childrenRecursive as $branch)
                                <li class="relative @3xl/detail:pt-8" wire:key="logframe-branch-{{ $branch->id }}" style="--logframe-order: {{ $loop->index }}">
                                    <span aria-hidden="true" @class([
                                        'absolute top-0 hidden h-px bg-secondary-light @3xl/detail:block',
                                        '-start-2.5' => ! $loop->first,
                                        'start-1/2' => $loop->first,
                                        '-end-2.5' => ! $loop->last,
                                        'end-1/2' => $loop->last,
                                    ])></span>
                                    <span aria-hidden="true" class="absolute start-1/2 top-0 hidden h-8 w-px bg-secondary-light @3xl/detail:block"></span>
                                    <span aria-hidden="true" class="absolute -start-4 top-7 h-px w-4 bg-secondary-light @3xl/detail:hidden"></span>

                                    <div class="logframe-reveal">
                                        <x-ui.logframe-node :result="$branch" :depth="1" />
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                            @foreach (['start' => -1, 'end' => 1] as $edge => $direction)
                                <button
                                    type="button"
                                    x-cloak
                                    x-show="! {{ $edge === 'start' ? 'atStart' : 'atEnd' }}"
                                    x-transition.opacity
                                    x-on:click="step({{ $direction }})"
                                    aria-label="{{ __($edge === 'start' ? 'programs.logframe_scroll_back' : 'programs.logframe_scroll_forward') }}"
                                    @class([
                                        'absolute top-16 z-10 hidden size-9 cursor-pointer items-center justify-center rounded-full border border-board-line bg-board text-secondary-dark shadow-md hover:border-primary hover:text-primary dark:hover:text-brand-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary @3xl/detail:flex dark:text-ink',
                                        '-start-3' => $edge === 'start',
                                        '-end-3' => $edge === 'end',
                                    ])
                                >
                                    <flux:icon :name="$edge === 'start' ? 'chevron-left' : 'chevron-right'" variant="micro" class="size-4 rtl:rotate-180" aria-hidden="true" />
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
