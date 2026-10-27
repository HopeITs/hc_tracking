{{--
    The attention list of proposals/show: every warning, each opening the
    tab that holds its evidence. Included by ⚡show.blade.php, whose top PHP
    block defines $mutedText, $inkText, $hairline, $amount, $term,
    $chronology and $arrivals.
--}}
    {{-- What needs attention now: every problem on the grant, each opening the tab that holds its evidence. --}}
    @if ($this->warnings !== [])
        <section class="mt-7 border-s-2 border-primary ps-4" aria-labelledby="warnings-heading" x-data="{ expanded: false }" data-warnings>
            <h2 id="warnings-heading" class="type-label {{ $inkText }}">{{ trans_choice('proposals.count_things_need_attention', count($this->warnings), ['count' => count($this->warnings)]) }}</h2>
            <ul class="mt-2 flex flex-col gap-1.5">
                @foreach ($this->warnings as $warning)
                    <li
                        wire:key="warning-{{ $warning['key'] }}"
                        data-warning="{{ $warning['key'] }}"
                        @if ($loop->index >= 3) x-show="expanded" x-cloak @endif
                    >
                        <button type="button" wire:click="$set('tab', '{{ $warning['tab'] }}')" aria-controls="tab-panel" class="group flex min-h-8 w-full touch-manipulation items-start gap-2.5 text-start text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium">
                            <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-primary dark:bg-status-critical' => $warning['critical'], 'border-2 border-status-warn' => ! $warning['critical']]) aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 underline-offset-4 group-hover:underline {{ $warning['critical'] ? 'font-semibold '.$inkText : $inkText }}">{{ $warning['text'] }}</span>
                            <flux:icon.arrow-right variant="micro" class="mt-0.5 size-4 shrink-0 {{ $mutedText }} rtl:rotate-180" aria-hidden="true" />
                        </button>
                    </li>
                @endforeach
            </ul>
            @if (count($this->warnings) > 3)
                <button type="button" class="mt-2 min-h-8 touch-manipulation text-xs font-semibold underline-offset-4 hover:underline {{ $mutedText }}" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded" data-toggle-warnings>
                    <span x-show="! expanded">{{ __('proposals.show_all_count', ['count' => count($this->warnings)]) }}</span>
                    <span x-show="expanded" x-cloak>{{ __('proposals.show_fewer') }}</span>
                </button>
            @endif
        </section>
    @endif
