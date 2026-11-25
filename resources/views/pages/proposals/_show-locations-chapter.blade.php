{{--
    The Locations chapter of the Projects tab on proposals/show (Y-01): where the
    proposal runs, one card per location row with its city, program, responsible,
    centers and one status word. The status is text with an icon, never colour
    alone. A city-wide row (D-88) shows "Field work" in the centers cell and is
    Complete. Reads $this->locationRows; included by _show-projects-tab.blade.php,
    whose host defines $mutedText and $inkText.
--}}
<section class="mb-8" aria-labelledby="proposal-locations-title" data-test="locations-chapter">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="proposal-locations-title" class="type-label {{ $mutedText }}">{{ __('proposals.locations_chapter_title') }}</h3>

        @if ($this->canFillCenters)
            <x-ui.button type="button" tone="secondary" size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openCenterFill" data-test="add-centers">{{ __('proposals.add_centers') }}</x-ui.button>
        @endif
    </div>

    @if ($this->locationRows === [])
        <p class="mt-2 text-sm {{ $mutedText }}" data-test="locations-chapter-empty">{{ __('proposals.locations_chapter_empty') }}</p>
    @else
        <ol class="mt-3 grid gap-3">
            @foreach ($this->locationRows as $locationRow)
                @php
                    $statusLabel = __('proposals.location_status_'.$locationRow['status']);
                @endphp
                <li class="grid gap-x-4 gap-y-2 rounded-lg border border-secondary-soft p-3 @2xl/detail:grid-cols-[1fr_1fr_1fr_1.2fr_auto] dark:border-line" wire:key="location-row-{{ $locationRow['id'] }}" data-location-row="{{ $locationRow['id'] }}" data-location-status="{{ $locationRow['status'] }}">
                    <div class="min-w-0">
                        <p class="text-xs {{ $mutedText }}">{{ __('proposals.location_city') }}</p>
                        <p class="text-sm font-semibold {{ $inkText }}" dir="auto">{{ $locationRow['city'] }}</p>
                    </div>

                    <div class="min-w-0">
                        <p class="text-xs {{ $mutedText }}">{{ __('proposals.location_program') }}</p>
                        @if ($locationRow['program'] !== null)
                            <p class="text-sm {{ $inkText }}" dir="auto">{{ $locationRow['program'] }}</p>
                        @else
                            <p class="text-sm {{ $mutedText }}">—</p>
                        @endif
                    </div>

                    <div class="min-w-0">
                        <p class="text-xs {{ $mutedText }}">{{ __('proposals.location_responsible') }}</p>
                        <p class="text-sm {{ $locationRow['responsible'] === null ? $mutedText : $inkText }}" dir="auto">{{ $locationRow['responsible'] ?? '—' }}</p>
                    </div>

                    <div class="min-w-0" data-location-centers>
                        <p class="text-xs {{ $mutedText }}">{{ __('proposals.location_centers') }}</p>
                        @if ($locationRow['fieldWork'])
                            <p class="text-sm {{ $inkText }}">{{ __('proposals.location_field_work') }}</p>
                        @elseif ($locationRow['centers'] !== [])
                            <p class="text-sm {{ $inkText }}" dir="auto">{{ implode('، ', $locationRow['centers']) }}</p>
                        @else
                            <p class="text-sm {{ $mutedText }}">—</p>
                        @endif
                    </div>

                    <div class="flex items-start @2xl/detail:justify-end">
                        <span @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $locationRow['status'] === 'complete',
                            'bg-status-warn-soft text-status-warn' => $locationRow['status'] !== 'complete',
                        ]) data-location-status-word>
                            <flux:icon :name="$locationRow['status'] === 'complete' ? 'check-circle' : ($locationRow['status'] === 'waiting_program' ? 'clock' : 'exclamation-triangle')" variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ $statusLabel }}
                        </span>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
