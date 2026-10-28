<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Livewire\Concerns\Desk\ReadsDeskAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Coming up — My agenda: the one card about WHEN. Every dated item that
 * involves the viewer (ReadsDeskAgenda) from three days ago to six weeks
 * ahead, grouped Earlier / Today / Tomorrow / This week / Later, under a
 * fourteen-day load strip (a bar per day, height = items; a busy day is
 * marked). Picking a day in the strip narrows the list to it. The Month
 * view is a seven-column calendar whose days open a popover with their
 * items, so the card never changes size. Weeks start on Monday.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDeskAgenda;

    /**
     * A day with this many items or more is marked busy in the strip.
     */
    public const int BusyDay = 3;

    public string $view = 'list';

    public string $month = '';

    public string $day = '';

    public function setView(string $view): void
    {
        $this->view = $view === 'month' ? 'month' : 'list';
        $this->page = 0;
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->shownMonth()->addMonths($by > 0 ? 1 : -1)->toDateString();
    }

    public function pickDay(string $day): void
    {
        $picked = CarbonImmutable::parse($day)->toDateString();
        $this->day = $this->day === $picked ? '' : $picked;
        $this->page = 0;
    }

    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string}>
     */
    #[Computed]
    public function events(): Collection
    {
        $today = CarbonImmutable::today();

        return $this->agendaBetween($today->subDays(3), $today->addDays(45));
    }

    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string}>
     */
    #[Computed]
    public function monthEvents(): Collection
    {
        $month = $this->shownMonth();

        return $this->agendaBetween($month, $month->endOfMonth());
    }

    public function shownMonth(): CarbonImmutable
    {
        return $this->month === '' ? CarbonImmutable::today()->startOfMonth() : CarbonImmutable::parse($this->month)->startOfMonth();
    }

    /**
     * Which heading an item sits under.
     */
    public function groupOf(CarbonImmutable $date): string
    {
        $today = CarbonImmutable::today();

        return match (true) {
            $date->lessThan($today) => 'earlier',
            $date->isSameDay($today) => 'today',
            $date->isSameDay($today->addDay()) => 'tomorrow',
            $date->lessThanOrEqualTo($today->endOfWeek()) => 'week',
            default => 'later',
        };
    }

    /**
     * The items the chosen filters keep, without paging them.
     *
     * @param  Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string}>  $items
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string}>
     */
    public function filtered(Collection $items): Collection
    {
        return $this->matchingFacets($items);
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return array_filter([
            'type' => __('desk.kind.'.$row['kind']),
            'project' => $row['project'],
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
};
?>

@php
    $today = CarbonImmutable::today();
    $events = $this->events;
    $pg = $this->paginate($this->day === '' ? $events : $events->filter(fn (array $event): bool => $event['date']->toDateString() === $this->day)->values(), self::PerPage);
    $shown = $this->filtered($events);
    $load = $shown->countBy(fn (array $event): string => $event['date']->toDateString());
    $peak = max(1, (int) $load->max());
    $tones = ['milestone' => 'dk-k-ms', 'task' => 'dk-k-task', 'monthly-report' => 'dk-k-rep', 'report' => 'dk-k-rep', 'transfer' => 'dk-k-money', 'donor-report' => 'dk-k-donor'];
@endphp

<x-desk.panel :facets="$pg['facets']" id="coming" register="calendar.index" :size="$size" :index="$index" :total="$total" :count="$events->count()">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach (['list' => __('desk.view_list'), 'month' => __('desk.view_month')] as $key => $label)
                <button type="button" wire:click="setView('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($view === 'month')
        @php
            $month = $this->shownMonth();
            $byDay = $this->filtered($this->monthEvents)->groupBy(fn (array $event): string => $event['date']->toDateString());
            $lead = $month->dayOfWeekIso - 1;
            $weeks = (int) ceil(($lead + $month->daysInMonth) / 7);
            $trail = $weeks * 7 - $lead - $month->daysInMonth;
            $weekdays = collect(range(0, 6))->map(fn (int $offset): string => CarbonImmutable::parse('2026-10-12')->addDays($offset)->translatedFormat('D'));
        @endphp
        <div class="dk-mm-h">
            <button type="button" class="dk-iconbtn dk-sm" wire:click="shiftMonth(-1)" aria-label="{{ __('desk.previous_month') }}"><x-desk.icon name="back" class="dk-flip" /></button>
            <strong>{{ $month->translatedFormat('F Y') }}</strong>
            <button type="button" class="dk-iconbtn dk-sm" wire:click="shiftMonth(1)" aria-label="{{ __('desk.next_month') }}"><x-desk.icon name="chev" class="dk-flip" /></button>
        </div>
        <div class="dk-mcal" style="--weeks:{{ $weeks }}" x-data="{ open: '', top: 0, left: 0, pick(day, el) { if (this.open === day) { this.open = ''; return } const r = el.getBoundingClientRect(); const w = Math.min(280, window.innerWidth - 24); this.top = Math.min(r.bottom + 6, window.innerHeight - 240); this.left = Math.max(12, Math.min(r.left + r.width / 2 - w / 2, window.innerWidth - w - 12)); this.open = day } }" x-on:keydown.escape.window="open = ''">
            @foreach ($weekdays as $weekday)
                <span class="dk-wd">{{ $weekday }}</span>
            @endforeach
            @for ($i = $lead; $i > 0; $i--)
                <span class="dk-mday dk-mday-off" aria-hidden="true"><span class="dk-num">{{ $month->subDays($i)->day }}</span></span>
            @endfor
            @for ($d = 1; $d <= $month->daysInMonth; $d++)
                @php
                    $date = $month->setDay($d)->toDateString();
                    $items = $byDay->get($date, collect());
                @endphp
                <button type="button" wire:key="md-{{ $date }}" @class(['dk-mday', 'dk-today' => $date === $today->toDateString(), 'dk-has' => $items->isNotEmpty(), 'dk-past' => $date < $today->toDateString()]) x-bind:class="open === '{{ $date }}' && 'dk-on'" x-on:click="pick('{{ $date }}', $el)" aria-label="{{ CarbonImmutable::parse($date)->translatedFormat(__('formats.date')) }}: {{ trans_choice('desk.agenda_items', $items->count(), ['count' => $items->count()]) }}">
                    <span class="dk-num">{{ $d }}</span>
                    <span class="dk-mdots" aria-hidden="true">
                        @foreach ($items->take(3) as $item)<i class="{{ $tones[$item['kind']] ?? '' }}"></i>@endforeach
                        @if ($items->count() > 3)<b class="dk-num">+{{ $items->count() - 3 }}</b>@endif
                    </span>
                </button>
            @endfor
            @for ($i = 1; $i <= $trail; $i++)
                <span class="dk-mday dk-mday-off" aria-hidden="true"><span class="dk-num">{{ $i }}</span></span>
            @endfor
            <template x-teleport="body">
                <div class="dk-scope dk-daypop" role="dialog" x-show="open !== ''" x-cloak x-transition.opacity x-on:click.outside="if (! $event.target.closest('.dk-mday')) open = ''" x-bind:style="`top:${top}px;left:${left}px`">
                    @foreach ($byDay as $date => $items)
                        <div x-show="open === '{{ $date }}'">
                            <p class="dk-poke-h">{{ CarbonImmutable::parse($date)->translatedFormat(__('formats.date')) }}</p>
                            <ul>
                                @foreach ($items as $item)
                                    <li><i class="dk-kdot {{ $tones[$item['kind']] ?? '' }}"></i><a href="{{ $item['href'] }}" wire:navigate>{{ $item['title'] }}</a><span class="dk-is">{{ __('desk.kind.'.$item['kind']) }} · {{ $item['context'] }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                    <p class="dk-is" x-show="! @js($byDay->keys()->all()).includes(open)">{{ __('desk.nothing_that_day') }}</p>
                </div>
            </template>
        </div>
    @else
        <div class="dk-load" role="group" aria-label="{{ __('desk.agenda_load') }}">
            @for ($i = 0; $i < 14; $i++)
                @php
                    $date = $today->addDays($i);
                    $iso = $date->toDateString();
                    $n = (int) ($load[$iso] ?? 0);
                @endphp
                <button type="button" wire:key="ld-{{ $iso }}" wire:click="pickDay('{{ $iso }}')" @class(['dk-ld', 'dk-today' => $i === 0, 'dk-busy' => $n >= self::BusyDay, 'dk-on' => $day === $iso]) aria-pressed="{{ $day === $iso ? 'true' : 'false' }}" title="{{ $date->translatedFormat(__('formats.date')) }}: {{ trans_choice('desk.agenda_items', $n, ['count' => $n]) }}">
                    <span class="dk-ld-bar"><b style="height:{{ $n === 0 ? 0 : max(18, (int) round($n * 100 / $peak)) }}%"></b></span>
                    <span class="dk-ld-d dk-num">{{ $date->format('j') }}</span>
                </button>
            @endfor
        </div>
        @if ($day !== '')
            <p class="dk-ld-pick dk-is">{{ CarbonImmutable::parse($day)->translatedFormat(__('formats.date')) }} · <button type="button" class="dk-link" wire:click="pickDay('{{ $day }}')">{{ __('desk.agenda_all_days') }}</button></p>
        @endif
        @if ($pg['total'] > 0)
            <ol class="dk-agenda" {{ $this->fitAttributes($pg) }}>
                @php
                    $group = null;
                @endphp
                @foreach ($pg['slice'] as $event)
                    @php
                        $now = $this->groupOf($event['date']);
                    @endphp
                    @if ($now !== $group)
                        <li class="dk-ag-h" data-fit-head wire:key="agh-{{ $now }}-{{ $pg['page'] }}">{{ __('desk.agenda_group.'.$now) }}</li>
                        @php
                            $group = $now;
                        @endphp
                    @endif
                    <li wire:key="{{ $event['key'] }}" @class(['dk-ag', 'dk-past' => $now === 'earlier'])>
                        <span class="dk-ag-d dk-num">{{ $event['date']->translatedFormat(__('desk.date_short')) }}</span>
                        <i class="dk-kdot {{ $tones[$event['kind']] ?? '' }}" title="{{ __('desk.kind.'.$event['kind']) }}"></i>
                        <span class="dk-ag-m"><a href="{{ $event['href'] }}" wire:navigate title="{{ $event['title'] }}">{{ $event['title'] }}</a><span class="dk-is" title="{{ $event['context'] }}">{{ __('desk.kind.'.$event['kind']) }} · {{ $event['context'] }}</span></span>
                    </li>
                @endforeach
            </ol>
        @else
            <p class="dk-pempty">{{ $day === '' ? __('desk.panel.coming.empty') : __('desk.nothing_that_day') }}</p>
        @endif
    @endif

    @if ($view === 'list')
        <x-slot:foot>
            <x-desk.pager :pg="$pg" />
        </x-slot:foot>
    @endif
</x-desk.panel>
