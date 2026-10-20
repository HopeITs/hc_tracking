<?php

use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
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
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * Panels that have a component yet; the rest stay out of the grid and
     * the library until they are built.
     */
    private const Built = ['needs'];

    /**
     * @return list<array{panel: string, size: string}>
     */
    #[Computed]
    public function layout(): array
    {
        return array_values(array_filter(
            app(Panels::class)->layoutFor(auth()->user()),
            fn (array $entry): bool => in_array($entry['panel'], self::Built, true),
        ));
    }

    /**
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}>
     */
    #[Computed]
    public function library(): array
    {
        return array_intersect_key(app(Panels::class)->availableTo(auth()->user()), array_flip(self::Built));
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

    public function resizePanel(string $panel, string $size): void
    {
        if (! isset(Panels::Sizes[$size])) {
            return;
        }

        $this->save(array_map(fn (array $entry): array => $entry['panel'] === $panel ? [...$entry, 'size' => $size] : $entry, $this->layout));
    }

    public function movePanel(string $panel, int $position): void
    {
        $layout = $this->layout;
        $from = array_search($panel, array_column($layout, 'panel'), true);

        if ($from === false) {
            return;
        }

        [$entry] = array_splice($layout, $from, 1);
        array_splice($layout, max(0, min($position, count($layout))), 0, [$entry]);
        $this->save($layout);
    }

    public function removePanel(string $panel): void
    {
        $this->save(array_values(array_filter($this->layout, fn (array $entry): bool => $entry['panel'] !== $panel)));
    }

    public function addPanel(string $panel): void
    {
        $library = $this->library;

        if (! isset($library[$panel]) || in_array($panel, array_column($this->layout, 'panel'), true)) {
            return;
        }

        $this->save([...$this->layout, ['panel' => $panel, 'size' => $library[$panel]['size']]]);
    }

    public function resetLayout(): void
    {
        $user = auth()->user();
        $user->desk_layout = null;
        $user->save();
        unset($this->layout);
    }

    /**
     * @param  list<array{panel: string, size: string}>  $layout
     */
    private function save(array $layout): void
    {
        $user = auth()->user();
        $user->desk_layout = array_values($layout);
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
    <section class="dk-hello">
        <div>
            <p class="dk-eyebrow">{{ now()->translatedFormat(__('formats.date_long')) }} · {{ auth()->user()->name }}</p>
            <h1>{{ rtrim($this->greeting(), '.!') }}{{ __('desk.sentence_end') }} {!! $n === 0 ? e(__('desk.hello_none')) : trans_choice('desk.hello_count', $n, ['count' => $n]) !!}</h1>
            <p class="dk-lede">{{ $n === 0 ? __('desk.lede_done') : ($needs['late'] > 0 ? trans_choice('desk.lede_late', $needs['late'], ['count' => $needs['late']]) : __('desk.lede_none_late')) }}</p>
        </div>
        <div class="dk-hello-tools">
            <button type="button" class="dk-btn" x-on:click="$dispatch('dk-customize-open')"><x-desk.icon name="grid" />{{ __('desk.customize') }}</button>
        </div>
    </section>

    <div class="dk-grid" id="dk-grid" data-desk-grid>
        @foreach ($layout as $i => $entry)
            <livewire:is :component="'desk.panels.'.$entry['panel']" :size="$entry['size']" :index="$i" :total="count($layout)" :wire:key="'panel-'.$entry['panel']" />
        @endforeach

        <button type="button" class="dk-addtile" x-on:click="$dispatch('dk-customize-open')">
            <x-desk.icon name="plus" />
            <span>{{ __('desk.add_panel') }}</span>
            <small>{{ trans_choice('desk.more_available', $more, ['count' => $more]) }}</small>
        </button>
    </div>

    @include('pages.desk._customize-sheet')
</div>
