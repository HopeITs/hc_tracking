<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Needs you: everything late or waiting on the viewer (the dashboard's
 * attention queue), then what falls due in the next seven days, each as a
 * sentence with the button that opens it.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * @return Collection<int, array{key: string, sev: string, title: string, sub: string, href: string, late: bool, week: bool}>
     */
    #[Computed]
    public function items(): Collection
    {
        $weekEnd = CarbonImmutable::today()->addDays(7);

        $attention = $this->attentionItems->map(fn (array $item): array => [
            'key' => $item['key'],
            'sev' => ($item['daysLate'] ?? 0) > 0 ? 'bad' : 'warn',
            'title' => $item['title'],
            'sub' => trim($item['context'].($item['badge'] !== '' ? ' · '.$item['badge'] : '')),
            'href' => $item['href'],
            'late' => ($item['daysLate'] ?? 0) > 0,
            'week' => false,
        ]);

        $soon = $this->upcoming
            ->filter(fn (array $item): bool => $item['date']->lessThanOrEqualTo($weekEnd))
            ->reject(fn (array $item): bool => $attention->contains('key', $item['key']))
            ->map(fn (array $item): array => [
                'key' => $item['key'],
                'sev' => '',
                'title' => $item['title'],
                'sub' => $item['context'].' · '.__('desk.due_on', ['date' => $item['date']->translatedFormat(__('formats.date'))]),
                'href' => $item['href'],
                'late' => false,
                'week' => true,
            ]);

        return $attention->concat($soon)->values();
    }
};
?>

@php
    $all = $this->items;
    $list = match ($filter) {
        'late' => $all->where('late', true)->values(),
        'week' => $all->where('week', true)->values(),
        default => $all,
    };
    $pg = $this->paginate($list, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="needs" :size="$size" :index="$index" :total="$total" :count="$all->count()">
    <x-slot:tools>
        <div class="dk-seg" role="group">
            @foreach (['all' => __('desk.filter_all'), 'late' => __('desk.filter_late'), 'week' => __('desk.filter_week')] as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:tools>

    @if ($pg['total'] > 0)
        <ol class="dk-items">
            @foreach ($pg['slice'] as $item)
                <li wire:key="{{ $item['key'] }}">
                    <span class="dk-sev {{ $item['sev'] !== '' ? 'dk-'.$item['sev'] : '' }}"></span>
                    <div class="dk-im">
                        <p class="dk-it">{{ $item['title'] }}</p>
                        <p class="dk-is">{{ $item['sub'] }}</p>
                    </div>
                    <div class="dk-acts">
                        <a href="{{ $item['href'] }}" wire:navigate class="dk-btn dk-sm {{ $item['late'] ? 'dk-primary' : '' }}">{{ __('desk.open') }}</a>
                    </div>
                </li>
            @endforeach
        </ol>
    @else
        <p class="dk-pempty">{{ __('desk.panel.needs.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
