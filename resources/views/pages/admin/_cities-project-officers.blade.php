{{--
    Project officers (D-12a): a project may have its own MEAL officer, who
    may come from another governorate; otherwise it inherits its
    governorate's. Lists the projects matching the search.

    @var \Illuminate\Support\Collection $placeOfficers
    @var bool $canManage
--}}

<x-ui.panel :title="__('cities.meal_place_officers')" :subtitle="__('cities.meal_place_hint')">
    <x-slot:toolbar>
        <div class="w-full @xl/directory:w-64">
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('cities.meal_search_places')"
                :placeholder="__('cities.meal_search_places')"
                wire:model.live.debounce.300ms="projectSearch"
                clearable
            />
        </div>
    </x-slot:toolbar>

    @if ($placeOfficers->isEmpty())
        <p class="px-4 py-6 text-sm text-secondary-medium dark:text-ink-muted">{{ __('cities.meal_no_places') }}</p>
    @else
        <ul class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($placeOfficers as $row)
                <li wire:key="project-officer-{{ $row['id'] }}" class="grid gap-3 px-4 py-3 @xl/directory:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] @xl/directory:items-center">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-secondary-dark dark:text-ink">{{ $row['name'] }}</p>
                        <p class="type-meta flex items-center gap-1 truncate text-secondary-medium dark:text-ink-muted">
                            <flux:icon.map-pin variant="micro" class="size-3.5 shrink-0" />
                            {{ $row['city'] ?? '—' }}
                        </p>
                    </div>

                    @include('pages.admin._cities-meal-officer', [
                        'officer' => $row['officer'],
                        'inherited' => $row['inherited'],
                        'canManage' => $canManage,
                        'scope' => 'place',
                        'targetId' => $row['id'],
                    ])
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.panel>
