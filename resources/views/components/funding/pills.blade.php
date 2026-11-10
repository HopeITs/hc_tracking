@props(['label', 'pills' => [], 'on' => null, 'watch' => [], 'keep' => false])

@php
    /*
     * One value per row. Each pill names the property it writes (`model`) and
     * its `value`; a `toggle` pill clears the property when chosen again, and
     * `also` sets companion properties with it (a sort and its direction).
     * The dialog holds these as a draft until "Show results"
     * (resources/js/finance-filters.js). A pill marked `default` is the row's
     * resting choice ("All", "All years"): the row only counts as on when
     * some other pill is chosen. A null entry is skipped.
     */
    $pills = array_values(array_filter($pills));
    $on ??= collect($pills)->contains(fn (array $pill): bool => ($pill['active'] ?? false) && ! ($pill['default'] ?? false));

    $pairs = collect($pills)
        ->reject(fn (array $pill): bool => (bool) ($pill['default'] ?? false))
        ->map(fn (array $pill): array => [$pill['model'], (string) $pill['value']])
        ->values()
        ->all();
    $models = collect($pills)->flatMap(fn (array $pill): array => [$pill['model'], ...array_keys($pill['also'] ?? [])])->unique()->values()->all();
    $defaults = collect($pills)->filter(fn (array $pill): bool => (bool) ($pill['default'] ?? false))->mapWithKeys(fn (array $pill): array => [$pill['model'] => (string) $pill['value']])->all();
@endphp

<x-funding.filter-row :label="$label" :on="$on" :pairs="$watch === [] ? $pairs : []" :watch="$watch" :models="$models" :defaults="$defaults" :keep="$keep" {{ $attributes }}>
    @foreach ($pills as $pill)
        @php
            $isActive = (bool) ($pill['active'] ?? false);
            $tone = $pill['tone'] ?? null;
            $model = Illuminate\Support\Js::from($pill['model']);
            $value = Illuminate\Support\Js::from((string) $pill['value']);
        @endphp
        @php
            /*
             * A pill that would return nothing stays in place, so the row still
             * reads as the whole list of choices, but it cannot be pressed: a
             * click on it would only cost an empty page and a trip back. The
             * one already chosen stays pressable, so it can be cleared.
             */
            $isUnavailable = ($pill['disabled'] ?? false) && ! $isActive;
        @endphp
        <button
            type="button"
            @unless ($isUnavailable)
                x-on:click="pick({{ $model }}, {{ $value }}, {{ ($pill['toggle'] ?? false) ? 'true' : 'false' }}, {{ Illuminate\Support\Js::from((object) ($pill['also'] ?? [])) }})"
            @else
                {{-- Still a tab stop, and still says why, but it does nothing. --}}
                aria-disabled="true"
                title="{{ __('finance_tables.nothing_matches_this_here') }}"
            @endunless
            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
            x-bind:aria-pressed="is({{ $model }}, {{ $value }}) ? 'true' : 'false'"
            @isset($pill['test']) data-test="{{ $pill['test'] }}" @endisset
            @class(['filter-pill group/pill', 'cursor-default opacity-50' => $isUnavailable])
        >
            @if ($tone !== null)
                <span @class(['size-1.5 rounded-full group-aria-pressed/pill:hidden', 'bg-primary dark:bg-status-critical' => $tone === 'critical', 'bg-status-warn' => $tone === 'warn']) aria-hidden="true"></span>
            @endif
            {{ $pill['label'] }}
            @if (array_key_exists('count', $pill))
                <span class="tabular-nums opacity-60">{{ $pill['count'] }}</span>
            @endif
        </button>
    @endforeach

    {{ $slot }}
</x-funding.filter-row>
