{{--
    Project officers (D-12a): a project may have its own MEAL officer, who
    may come from another governorate; otherwise it inherits its
    governorate's. Lists the projects matching the search.

    @var \Illuminate\Support\Collection $projectOfficers
    @var bool $canManage
--}}

<x-ui.panel :title="__('cities.project_officers')" :subtitle="__('cities.project_officer_hint')">
    <x-slot:toolbar>
        <div class="w-full @xl/directory:w-64">
            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('cities.search_projects')"
                :placeholder="__('cities.project_name')"
                wire:model.live.debounce.300ms="projectSearch"
                clearable
            />
        </div>
    </x-slot:toolbar>

    @if ($projectOfficers->isEmpty())
        <p class="px-4 py-6 text-sm text-secondary-medium dark:text-ink-muted">{{ __('cities.no_matching_projects') }}</p>
    @else
        <ul class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($projectOfficers as $row)
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
                        'scope' => 'project',
                        'targetId' => $row['id'],
                    ])
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.panel>
