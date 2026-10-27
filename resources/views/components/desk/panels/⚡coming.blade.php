<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Livewire\Concerns\Desk\ReadsDeskAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Coming up: everything with a date from three days ago to six weeks
 * ahead, as a list or as a month with a picked day.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDeskAgenda;

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
        $this->day = '';
    }

    public function pickDay(string $day): void
    {
        $this->day = $this->day === $day ? '' : CarbonImmutable::parse($day)->toDateString();
    }

    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, href: string}>
     */
    #[Computed]
    public function events(): Collection
    {
        $today = CarbonImmutable::today();

        return $this->agendaBetween($today->subDays(3), $today->addDays(45));
    }

    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, href: string}>
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
};
?>

@php
    $today = CarbonImmutable::today();
    $events = $this->events;
@endphp

<x-desk.panel :facets="[]" id="coming" register="calendar.index" :size="$size" :index="$index" :total="$total" :count="$events->count()">
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
            $byDay = $this->monthEvents->groupBy(fn (array $event): string => $event['date']->toDateString());
            $lead = ($month->dayOfWeekIso - 1);
            $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        @endphp
        <div class="dk-mm-h">
            <button type="button" class="dk-iconbtn dk-sm" wire:click="shiftMonth(-1)" aria-label="{{ __('desk.previous_month') }}"><x-desk.icon name="back" class="dk-flip" /></button>
            <strong>{{ $month->translatedFormat('F Y') }}</strong>
            <button type="button" class="dk-iconbtn dk-sm" wire:click="shiftMonth(1)" aria-label="{{ __('desk.next_month') }}"><x-desk.icon name="chev" class="dk-flip" /></button>
        </div>
        <div class="dk-mm">
            @for ($i = 0; $i < 7; $i++)
                <span class="dk-wd">{{ mb_substr($weekStart->addDays($i)->translatedFormat('l'), 0, 1) }}</span>
            @endfor
            @for ($i = 0; $i < $lead; $i++)
                <span></span>
            @endfor
            @for ($d = 1; $d <= $month->daysInMonth; $d++)
                @php
                    $date = $month->setDay($d)->toDateString();
                    $n = $byDay->get($date)?->count() ?? 0;
                @endphp
                <button type="button" wire:key="md-{{ $date }}" wire:click="pickDay('{{ $date }}')" @class(['dk-md', 'dk-today' => $date === $today->toDateString(), 'dk-on' => $day === $date])>{{ $d }}@if ($n > 0)<i>{{ $n > 1 ? $n : '' }}</i>@endif</button>
            @endfor
        </div>
        @if ($day !== '')
            <ul class="dk-plist dk-tight">
                @forelse ($byDay->get($day, collect()) as $event)
                    <li wire:key="{{ $event['key'] }}"><div><strong><a href="{{ $event['href'] }}" wire:navigate>{{ $event['title'] }}</a></strong><span class="dk-is">{{ __('desk.kind.'.$event['kind']) }} · {{ $event['context'] }}</span></div></li>
                @empty
                    <li><span class="dk-muted dk-small">{{ __('desk.nothing_that_day') }}</span></li>
                @endforelse
            </ul>
        @else
            <p class="dk-small dk-muted">{{ __('desk.pick_a_day') }}</p>
        @endif
    @else
        @php
            $pg = $this->paginate($events, self::PerPage);
        @endphp
        @if ($pg['total'] > 0)
            <ol class="dk-cal">
                @foreach ($pg['slice'] as $event)
                    @php
                        $days = (int) $today->diffInDays($event['date'], false);
                    @endphp
                    <li wire:key="{{ $event['key'] }}" @class(['dk-past' => $days < 0, 'dk-soon' => $days >= 0 && $days <= 7])>
                        <span class="dk-dd"><b>{{ $event['date']->translatedFormat(__('desk.date_short')) }}</b>{{ \App\Support\Desk\RelativeDay::for($event['date']) }}</span>
                        <span class="dk-pin"><i></i></span>
                        <div><p><a href="{{ $event['href'] }}" wire:navigate>{{ $event['title'] }}</a></p><p class="dk-is">{{ __('desk.kind.'.$event['kind']) }} · {{ $event['context'] }}</p></div>
                    </li>
                @endforeach
            </ol>
        @else
            <p class="dk-pempty">{{ __('desk.panel.coming.empty') }}</p>
        @endif
    @endif

    @if ($view === 'list')
        <x-slot:foot>
            <x-desk.pager :pg="$pg" />
        </x-slot:foot>
    @endif
</x-desk.panel>
