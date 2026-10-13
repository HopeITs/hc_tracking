<?php

use App\Livewire\Concerns\IndicatorLibrary\ListsLibraryEntries;
use App\Livewire\Concerns\IndicatorLibrary\ManagesLibraryEntryForm;
use App\Models\LibraryIndicator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.indicator_library')] class extends Component {
    use ListsLibraryEntries;
    use ManagesLibraryEntryForm;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $level = '';

    #[Url(except: '')]
    public string $measure = '';

    #[Url(except: '')]
    public string $collector = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $english = '';

    /** The entry whose details are open; one at a time. */
    public ?int $expandedEntryId = null;

    /**
     * `?entry=<id>` (a notification link) opens that entry on its page. Read
     * once here rather than bound with #[Url], so closing it and refreshing
     * does not reopen it.
     */
    public function mount(): void
    {
        Gate::authorize('viewAny', LibraryIndicator::class);

        $this->resetEntryForm();

        $linked = request()->integer('entry');
        $position = $linked > 0 ? $this->filteredEntries->search(fn (LibraryIndicator $entry): bool => $entry->id === $linked) : false;

        if ($position !== false) {
            $this->expandedEntryId = $linked;
            $this->setPage(intdiv($position, self::PER_PAGE) + 1);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'program', 'level', 'measure', 'collector', 'status', 'english'], true)) {
            $this->resetPage();
        }
    }

    public function toggleEntry(int $libraryIndicatorId): void
    {
        $this->expandedEntryId = $this->expandedEntryId === $libraryIndicatorId ? null : $libraryIndicatorId;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'program', 'level', 'measure', 'collector', 'status', 'english');
        $this->resetPage();
    }
};
?>

@php
    use App\Enums\IndicatorCollector;
    use App\Enums\IndicatorMeasure;

    $filtersAreActive = collect([$search, $program, $level, $measure, $collector, $status, $english])->contains(fn (string $value): bool => $value !== '');
    $paged = $this->pagedEntries;
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="indicator-library-heading"
>
    <x-ui.page-header
        heading-id="indicator-library-heading"
        :title="__('titles.indicator_library')"
        :count="$this->filteredEntries->count()"
    >
        <x-slot:filters>
            <div class="contents" data-library-filters>
                <x-ui.input type="search" icon="magnifying-glass" :label="__('indicator_library.search')" :placeholder="__('indicator_library.search_placeholder')" wire:model.live.debounce.300ms="search" maxlength="100" clearable />

                <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                    <option value="">{{ __('common.all_programs') }}</option>
                    <option value="institutional">{{ __('indicator_library.institutional') }}</option>
                    @foreach ($this->programFilterOptions() as $programId => $programName)
                        <option value="{{ $programId }}">{{ $programName }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('indicator_library.level')" wire:model.live="level" :value="$level">
                    <option value="">{{ __('indicator_library.all_levels') }}</option>
                    @foreach (['G', 'CC', 'OC', 'OP', 'AC'] as $levelKind)
                        <option value="{{ $levelKind }}">{{ __('indicator_library.level_kinds.'.$levelKind) }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('indicator_library.measure')" wire:model.live="measure" :value="$measure">
                    <option value="">{{ __('indicator_library.all_measures') }}</option>
                    @foreach (IndicatorMeasure::cases() as $measureCase)
                        <option value="{{ $measureCase->value }}">{{ $measureCase->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('indicator_library.collected_by')" wire:model.live="collector" :value="$collector">
                    <option value="">{{ __('indicator_library.all_collectors') }}</option>
                    @foreach (IndicatorCollector::cases() as $collectorCase)
                        <option value="{{ $collectorCase->value }}">{{ $collectorCase->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('indicator_library.status')" wire:model.live="status" :value="$status">
                    <option value="">{{ __('indicator_library.all_statuses') }}</option>
                    <option value="active">{{ __('indicator_library.active') }}</option>
                    <option value="inactive">{{ __('indicator_library.inactive') }}</option>
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('indicator_library.english_name')" wire:model.live="english" :value="$english">
                    <option value="">{{ __('indicator_library.any_english_name') }}</option>
                    <option value="missing">{{ __('indicator_library.missing_english') }}</option>
                </x-ui.header-filter-select>
            </div>
        </x-slot:filters>

        @can('create', LibraryIndicator::class)
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-library-entry">
                    {{ __('indicator_library.add_entry') }}
                </x-ui.button>
            </x-slot:actions>
        @endcan
    </x-ui.page-header>

    @if ($paged->isEmpty())
        <x-ui.empty-state
            icon="book-open"
            :heading="$filtersAreActive ? __('indicator_library.no_matching_entries') : __('indicator_library.no_entries_yet')"
            :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('indicator_library.no_entries_yet_body')"
        >
            <x-slot:action>
                @if ($filtersAreActive)
                    <x-ui.button tone="primary" icon="arrow-path" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                @elseif (auth()->user()->can('create', LibraryIndicator::class))
                    <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal">{{ __('indicator_library.add_entry') }}</x-ui.button>
                @endif
            </x-slot:action>
        </x-ui.empty-state>
    @else
        @include('pages.indicator-library._index-entries')

        @if ($paged->hasPages())
            <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#indicator-library-heading" />
        @endif
    @endif

    @include('pages.indicator-library._index-entry-modal')

    @include('pages.indicator-library._index-confirm-modals')
</section>
