@props(['strategy' => null])

{{--
    The removable "Strategy: …" chip a register shows while it is narrowed to
    one strategy (App\Livewire\Concerns\FiltersByStrategy). Nothing renders
    when no strategy filter applies.
--}}
@if ($strategy !== null)
    <button type="button" class="dk-chip" aria-pressed="true" wire:click="clearStrategyFilter" aria-label="{{ __('strategies.remove_strategy_filter', ['name' => $strategy->name]) }}" data-strategy-filter-chip>
        <span>{{ __('strategies.strategy_filter', ['name' => $strategy->name]) }}</span>
        <x-desk.icon name="x" />
    </button>
@endif
