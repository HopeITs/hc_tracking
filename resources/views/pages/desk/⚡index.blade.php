<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Desk\ArrangesPanels;
use App\Support\Desk\Panels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Desk: one sentence that says what needs the viewer today, then the
 * panels they arranged (or their role's default) on a twelve-column grid.
 * Every change to the arrangement is saved on users.desk_layout at once.
 */
new #[Layout('layouts::desk'), Title('titles.dashboard')] class extends Component {
    use ArrangesPanels;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * @return list<array{panel: string, size: string}>
     */
    #[Computed]
    public function layout(): array
    {
        return app(Panels::class)->layoutFor(auth()->user());
    }

    /**
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}>
     */
    #[Computed]
    public function library(): array
    {
        return app(Panels::class)->availableTo(auth()->user());
    }

    /**
     * Panels the viewer's role does not open, with the reason, for the Customize sheet.
     *
     * @return array<string, array{id: string, title: string, reason: string}>
     */
    #[Computed]
    public function locked(): array
    {
        return app(Panels::class)->lockedFor(auth()->user());
    }

    /**
     * How many things need the viewer, and how many of those are late, for the greeting.
     *
     * @return array{total: int, late: int}
     */
    #[Computed]
    public function needsCount(): array
    {
        $late = $this->attentionItems->filter(fn (array $item): bool => ($item['daysLate'] ?? 0) > 0)->count();
        $weekEnd = CarbonImmutable::today()->addDays(7);
        $soon = $this->upcoming->filter(fn (array $item): bool => $item['date']->lessThanOrEqualTo($weekEnd))
            ->reject(fn (array $item): bool => $this->attentionItems->contains('key', $item['key']))
            ->count();

        return ['total' => $this->attentionItems->count() + $soon, 'late' => $late];
    }

    /**
     * @param  list<array{panel: string, size: string}>|null  $layout
     */
    protected function storeLayout(?array $layout): void
    {
        $user = auth()->user();
        $user->desk_layout = $layout === null ? null : array_values($layout);
        $user->save();
        unset($this->layout);
    }
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function visible(Builder $query, string $module): Builder
    {
        return $this->canView($module) ? $query : $query->whereRaw('1 = 0');
    }

    protected function canView(string $module): bool
    {
        return auth()->user()?->can("{$module}.view") === true;
    }

    protected function canViewFundingPosition(): bool
    {
        return $this->canView('funding-agreements') && $this->canView('transfers');
    }
};
?>

@php
    $layout = $this->layout;
    $needs = $this->needsCount;
    $n = $needs['total'];
    $placed = array_column($layout, 'panel');
    $more = count(array_diff(array_keys($this->library), $placed));
@endphp

<div data-desk>
    <section class="dk-hello" data-desk-hello>
        <div>
            <p class="dk-eyebrow">{{ now()->translatedFormat(__('formats.date_long')) }} · {{ auth()->user()->name }}</p>
            <h1>{{ rtrim($this->greeting(), '.!') }}{{ __('desk.sentence_end') }} {!! $n === 0 ? e(__('desk.hello_none')) : trans_choice('desk.hello_count', $n, ['count' => $n]) !!}</h1>
            <p class="dk-lede">{{ $n === 0 ? __('desk.lede_done') : ($needs['late'] > 0 ? trans_choice('desk.lede_late', $needs['late'], ['count' => $needs['late']]) : __('desk.lede_none_late')) }}</p>
        </div>
    </section>

    <div class="dk-grid" id="dk-grid" data-desk-grid>
        @foreach ($layout as $i => $entry)
            <livewire:is :component="'desk.panels.'.$entry['panel']" :size="$entry['size']" :index="$i" :total="count($layout)" :wire:key="'panel-'.$entry['panel']" />
        @endforeach
    </div>

    {{-- Outside the dense grid so it can never be backfilled into a gap: always the last card, and only while a panel is left to add. --}}
    @if ($more > 0)
        <div class="dk-grid dk-grid-tail">
            <button type="button" class="dk-addtile" x-on:click="$dispatch('dk-customize-open')" data-desk-add>
                <x-desk.icon name="plus" />
                <span>{{ __('desk.add_panel') }}</span>
                <small>{{ trans_choice('desk.more_available', $more, ['count' => $more]) }}</small>
            </button>
        </div>
    @endif

    @include('pages.desk._customize-sheet')
</div>
