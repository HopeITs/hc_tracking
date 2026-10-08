{{--
    The lifecycle rail of proposals/show: seven stops, each with the date it
    was reached. Included by ⚡show.blade.php, whose top PHP block defines
    $mutedText, $inkText, $hairline, $amount, $term, $chronology and
    $arrivals.
--}}
    {{-- The lifecycle as one thread: seven stops, each carrying the date it was reached.
         Where the thread scrolls (narrow screens) it opens centred on the current stop;
         the delta is measured from the rendered boxes, so it holds in RTL too. --}}
    <nav
        aria-label="{{ __('proposals.lifecycle') }}"
        class="overflow-x-auto pt-6 pb-1 [scrollbar-width:none] @3xl/detail:overflow-visible"
        x-data
        x-init="$nextTick(() => {
            const stop = $el.querySelector('[aria-current=step]');
            if (stop && $el.scrollWidth > $el.clientWidth) {
                $el.scrollLeft += stop.getBoundingClientRect().left - $el.getBoundingClientRect().left - ($el.clientWidth - stop.offsetWidth) / 2;
            }
        })"
        data-lifecycle
    >
        <ol class="grid min-w-[44rem] grid-cols-7 @3xl/detail:min-w-0">
            @foreach ($this->lifecycle as $index => $stop)
                <li
                    class="relative flex min-w-0 flex-col items-center gap-1.5"
                    wire:key="stop-{{ $index }}-{{ $stop['status']->value }}"
                    @if (in_array($stop['state'], ['current', 'rejected'], true)) aria-current="step" @endif
                >
                    @if (! $loop->first)
                        <span @class([
                            'absolute end-1/2 top-[0.3125rem] h-0.5 w-full -translate-y-1/2',
                            'bg-secondary-dark dark:bg-ink-muted' => in_array($stop['state'], ['past', 'current'], true),
                            'bg-primary dark:bg-status-critical' => $stop['state'] === 'rejected',
                            'bg-secondary-soft dark:bg-line-strong' => $stop['state'] === 'ahead',
                        ]) aria-hidden="true"></span>
                    @endif

                    <span @class([
                        'relative z-10 size-2.5 rounded-full',
                        'bg-primary dark:bg-brand ring-4 ring-primary/20' => in_array($stop['state'], ['current', 'rejected'], true),
                        'bg-secondary-dark dark:bg-ink-muted' => $stop['state'] === 'past',
                        'border-2 border-secondary-soft bg-white dark:border-line-strong dark:bg-surface-1' => $stop['state'] === 'ahead',
                    ]) aria-hidden="true"></span>

                    <span @class([
                        'px-0.5 text-center text-[11px] leading-tight',
                        'font-bold text-status-critical' => in_array($stop['state'], ['current', 'rejected'], true),
                        'font-medium '.$inkText => $stop['state'] === 'past',
                        'font-medium '.$mutedText => $stop['state'] === 'ahead',
                    ])>{{ $stop['label'] }}</span>

                    <span class="min-h-4 text-center text-[10px] tabular-nums {{ $mutedText }}">
                        @if ($stop['date'] !== null)
                            <time datetime="{{ $stop['date']->toDateString() }}">{{ $stop['date']->translatedFormat(__('formats.date_short')) }}</time>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
    </nav>
