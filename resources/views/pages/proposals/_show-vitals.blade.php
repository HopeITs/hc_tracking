{{--
    The bench of proposals/show: the four vital readings, each opening its
    tab. Included by ⚡show.blade.php, whose top PHP block defines
    $mutedText, $inkText, $hairline, $amount, $term, $chronology and
    $arrivals.
--}}
    {{-- The bench: four readings of this grant's condition; each opens the tab holding its evidence. --}}
    <div
        @class(['mt-7 grid grid-cols-2 gap-x-6 gap-y-7', '@3xl/detail:grid-cols-4' => count($this->vitals) === 4, '@3xl/detail:grid-cols-3' => count($this->vitals) !== 4])
        role="group"
        aria-label="{{ __('proposals.the_grant_s_vital_signs') }}"
        data-bench
    >
        @foreach ($this->vitals as $reading)
            <button
                type="button"
                wire:click="$set('tab', '{{ $reading['tab'] }}')"
                aria-controls="tab-panel"
                class="logframe-reveal group -mx-2 flex min-w-0 touch-manipulation flex-col px-2 text-start focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                style="--logframe-order: {{ $loop->index }}"
                wire:key="vital-{{ $reading['key'] }}"
                data-vital="{{ $reading['key'] }}"
                data-test="vital-{{ $reading['key'] }}"
            >
                <span class="type-label {{ $mutedText }}">{{ $reading['label'] }}</span>

                <span @class([
                    'mt-2 flex min-w-0 items-baseline gap-0.5 text-4xl leading-none font-extrabold tracking-tight tabular-nums @xl/detail:text-5xl @5xl/detail:text-[3.5rem]',
                    'text-status-critical' => $reading['alarm'],
                    $inkText => ! $reading['alarm'],
                ])>
                    <span class="truncate">{{ $reading['figure'] }}</span>
                    @if ($reading['suffix'] !== null)
                        <span @class(['text-2xl font-bold tracking-normal @xl/detail:text-3xl', 'text-status-critical/70' => $reading['alarm'], $mutedText => ! $reading['alarm']])>{{ $reading['suffix'] }}</span>
                    @endif
                </span>

                <span class="mt-2 line-clamp-2 text-xs {{ $mutedText }}">{{ $reading['caption'] }}</span>

                @foreach ($reading['others'] ?? [] as $other)
                    <span @class([
                        'mt-1 truncate text-xs font-semibold tabular-nums',
                        'text-status-critical' => $other['alarm'],
                        $inkText => ! $other['alarm'],
                    ]) data-test="vital-money-{{ $other['currency'] }}">{{ $other['text'] }}</span>
                @endforeach

                <span class="mt-3 flex h-[3px] w-full gap-px overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" aria-hidden="true">
                    @foreach ($reading['segments'] as $segment)
                        <span class="money-grow h-full first:rounded-s-full {{ $segment['class'] }}" style="width: {{ $segment['width'] }}%"></span>
                    @endforeach
                </span>
            </button>
        @endforeach
    </div>
