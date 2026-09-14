<?php

use App\Models\HrCity;
use App\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.city_directory')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Cities come from the HR database and are read-only here; every city is
     * listed, active or not, exactly as HR holds it.
     *
     * @return LengthAwarePaginator<int, HrCity>
     */
    #[Computed]
    public function cities(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return HrCity::query()
            ->select(['id', 'name_en', 'name_ar', 'is_active'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name_en', 'like', '%'.$search.'%')
                ->orWhere('name_ar', 'like', '%'.$search.'%')))
            ->orderBy(HrCity::nameColumn())
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Project counts for the cities on this page, keyed by HR city id.
     *
     * Projects live in a different database from cities, so the count cannot
     * be a withCount() subquery.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function projectCounts(): Collection
    {
        return Project::query()
            ->whereIn('city_id', $this->cities->pluck('id'))
            ->selectRaw('city_id, count(*) as aggregate')
            ->groupBy('city_id')
            ->pluck('aggregate', 'city_id')
            ->map(fn ($count): int => (int) $count);
    }
};
?>

@php
    $controlClasses = 'border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!';
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
@endphp

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-cell]]:border-secondary-soft! [&_[data-flux-cell]]:text-secondary-medium! [&_[data-flux-column]]:border-secondary-soft! [&_[data-flux-column]]:text-secondary-dark! [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! [&_[data-flux-subheading]]:text-secondary-medium! dark:[&_[data-flux-cell]]:border-secondary-medium! dark:[&_[data-flux-cell]]:text-secondary-soft! dark:[&_[data-flux-column]]:border-secondary-medium! dark:[&_[data-flux-column]]:text-white! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white! dark:[&_[data-flux-subheading]]:text-secondary-soft!"
    aria-labelledby="city-directory-heading"
>
    <div class="flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @3xl/directory:flex-row @3xl/directory:items-end">
        <div class="min-w-40 @3xl/directory:me-auto">
            <div class="flex items-center gap-2">
                <flux:heading id="city-directory-heading" size="lg">{{ __('common.cities') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $this->cities->total() }}
                </span>
            </div>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('cities.cities_are_managed_in_the_hr_system_and_shown_here_read_only') }}</p>
        </div>

        <div class="w-full @3xl/directory:max-w-[24rem]">
            <flux:input
                type="search"
                icon="magnifying-glass"
                :label="__('cities.search_cities')"
                :placeholder="__('cities.city_name')"
                :class="$controlClasses"
                wire:model.live.debounce.300ms="search"
                clearable
            />
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft bg-white px-3.5 py-2 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                {{ trans_choice('cities.count_matching_cities', $this->cities->total(), ['count' => $this->cities->total()]) }}
            </p>

            <div class="flex items-center gap-2">
                <span wire:loading.delay.flex wire:target="search,clearSearch" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                    <flux:icon.arrow-path class="size-3.5 animate-spin" />
                    {{ __('common.updating') }}
                </span>

                @if ($search !== '')
                    <flux:button
                        size="sm"
                        variant="ghost"
                        class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!"
                        wire:click="clearSearch"
                    >
                        {{ __('cities.clear_search') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($this->cities->isEmpty())
            <div class="flex min-h-56 flex-col items-center justify-center gap-3 px-5 py-10 text-center">
                <div class="flex size-11 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                    <flux:icon.map-pin class="size-5" />
                </div>
                <div class="space-y-1">
                    <flux:heading size="base">{{ __('cities.no_matching_cities') }}</flux:heading>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('common.change_the_search_and_try_again') }}</p>
                </div>
                <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearSearch">
                    {{ __('cities.clear_search') }}
                </flux:button>
            </div>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium @3xl/directory:hidden">
                @foreach ($this->cities as $city)
                    @php($projectsCount = $this->projectCounts->get($city->id, 0))
                    <article class="flex flex-col gap-2 p-3.5" wire:key="mobile-city-{{ $city->id }}">
                        <div class="flex min-w-0 items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                    <flux:icon.map-pin class="size-4.5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $city->name }}</p>
                                    <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft" dir="auto">{{ $city->secondary_name }}</p>
                                </div>
                            </div>

                            <span class="inline-flex shrink-0 rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                {{ trans_choice('common.count_project', $projectsCount, ['count' => $projectsCount]) }}
                            </span>
                        </div>

                        <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                            {{ $city->is_active ? __('common.active') : __('cities.inactive') }}
                        </p>
                    </article>
                @endforeach
            </div>

            <div class="hidden @3xl/directory:block">
                <flux:table class="table-fixed [&_[data-flux-cell]]:px-3.5! [&_[data-flux-cell]]:py-2! [&_[data-flux-column]]:px-3.5! [&_[data-flux-column]]:py-2.5! [&_tbody_tr]:transition-colors [&_tbody_tr]:hover:bg-secondary-soft dark:[&_tbody_tr]:hover:bg-secondary-medium">
                    <flux:table.columns class="bg-secondary-soft text-xs font-semibold dark:bg-secondary-medium">
                        <flux:table.column class="w-[36%]">{{ __('common.city') }}</flux:table.column>
                        <flux:table.column class="w-[32%]">{{ \App\Enums\Locale::current() === \App\Enums\Locale::Arabic ? __('cities.english_name') : __('cities.arabic_name') }}</flux:table.column>
                        <flux:table.column class="w-[16%]" align="center">{{ __('common.projects') }}</flux:table.column>
                        <flux:table.column class="w-[16%]" align="center">{{ __('common.status') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->cities as $city)
                            <flux:table.row :key="$city->id">
                                <flux:table.cell variant="strong" class="text-secondary-dark! dark:text-white!">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                            <flux:icon.map-pin class="size-4" />
                                        </div>
                                        <p class="truncate font-semibold">{{ $city->name }}</p>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <p class="truncate" dir="auto">{{ $city->secondary_name }}</p>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                        {{ $this->projectCounts->get($city->id, 0) }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    {{ $city->is_active ? __('common.active') : __('cities.inactive') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">
                <flux:pagination
                    :paginator="$this->cities"
                    class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
                    scroll-to="#city-directory-heading"
                />
            </div>
        @endif
    </div>
</section>
