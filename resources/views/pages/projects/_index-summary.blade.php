{{--
    The quick filters of projects/index, one calm line each: the lifecycle
    states (also the status filter), the four "needs attention" lenses, and the
    filters set behind the Filters button as removable chips. Included by
    ⚡index.blade.php, whose top PHP block defines $statusCounts, $triageCounts,
    $lensTiles, $activeFilterChips and $activeFilterLabels.
--}}
@use('App\Enums\ProjectStatus')
<div class="dk-pjq">
    <div class="dk-pjq-row" role="group" aria-label="{{ __('projects.lifecycle') }}">
        <button type="button" class="dk-chip" wire:click="$set('status', '')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}" data-test="lifecycle-all">
            {{ __('desk.project_filter.all') }} <span class="dk-num">{{ array_sum($statusCounts) }}</span>
        </button>

        @foreach (ProjectStatus::live() as $case)
            @php
                $segmentCount = $statusCounts[$case->value] ?? 0;
            @endphp

            {{-- An empty state stays out of the way unless it is the one selected. --}}
            @continue($segmentCount === 0 && $status !== $case->value)

            <button
                type="button"
                class="dk-chip"
                wire:click="toggleStatus('{{ $case->value }}')"
                aria-pressed="{{ $status === $case->value ? 'true' : 'false' }}"
                data-test="lifecycle-{{ $case->value }}"
                wire:key="lifecycle-{{ $case->value }}"
            >
                <span class="sr-only">{{ trans_choice('projects.lifecycle_segment', $segmentCount, ['status' => $case->label(), 'count' => $segmentCount]) }}</span>
                <span aria-hidden="true">{{ $case->label() }}</span> <span class="dk-num" aria-hidden="true">{{ $segmentCount }}</span>
            </button>
        @endforeach
    </div>

    @if (array_sum($triageCounts) > 0 || $lens !== '')
        <div class="dk-pjq-row" role="group" aria-labelledby="portfolio-triage-heading">
            <span id="portfolio-triage-heading" class="dk-pjq-k">{{ __('projects.group_attention') }}</span>

            @foreach ($lensTiles as $lensValue => $lensTile)
                @php
                    $lensCount = $triageCounts[$lensValue] ?? 0;
                @endphp

                @continue($lensCount === 0 && $lens !== $lensValue)

                <button
                    type="button"
                    class="dk-chip dk-pjq-lens"
                    wire:click="toggleLens('{{ $lensValue }}')"
                    aria-pressed="{{ $lens === $lensValue ? 'true' : 'false' }}"
                    title="{{ $lensTile['hint'] }}"
                    data-test="lens-{{ $lensValue }}"
                    wire:key="tile-lens-{{ $lensValue }}"
                >
                    {{ $lensTile['label'] }} <span class="dk-num">{{ $lensCount }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($activeFilterChips !== [])
        <div class="dk-pjq-row" data-test="active-filter-chips">
            @foreach ($activeFilterChips as $filterKey => $filterValue)
                <button type="button" class="dk-chip dk-pjq-set" wire:click="$set('{{ $filterKey }}', '')" wire:key="active-filter-{{ $filterKey }}" aria-label="{{ __('projects.remove_filter', ['filter' => $activeFilterLabels[$filterKey].': '.$filterValue]) }}">
                    <span class="dk-pjq-k">{{ $activeFilterLabels[$filterKey] }}</span> {{ $filterValue }} <x-desk.icon name="x" />
                </button>
            @endforeach
        </div>
    @endif
</div>
