<?php

use App\Livewire\Concerns\FiltersByStrategy;
use App\Support\Like;
use App\Support\StrategyRollup;
use App\Models\HrCity;
use App\Models\HrProgram;
use App\Models\ProposalLocation;
use App\Models\Risk;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.risks')] class extends Component {
    use FiltersByStrategy;
    use WithPagination;

    /**
     * What a place's name needs (`ProposalLocation::placeLabels()`), so a page
     * of cards costs one HR query and none per row.
     */
    private const PlaceRelations = ['location:id,proposal_id,project_id,program_id,city_id', 'location.program:id'];

    #[Url(except: '')]
    public string $search = '';

    /** The location row the register is narrowed to (Y-13, D-93). */
    #[Url(except: '')]
    public string $location = '';

    #[Url(except: false)]
    public bool $materialisedOnly = false;

    #[Url(except: false)]
    public bool $unmitigated = false;

    /** Only risks scoring StrategyRollup::HighRiskScore or more on the heat map. */
    #[Url(except: false)]
    public bool $high = false;

    /** The register view: the risk list, or a Board by whether the risk happened. */
    #[Url(except: 'list')]
    public string $view = 'list';

    public bool $showRiskModal = false;

    #[Locked]
    public ?int $editingRiskId = null;

    #[Locked]
    public ?int $deletingRiskId = null;

    public bool $showDeleteModal = false;

    public string $name = '';

    public string $impact = '';

    public string $solution = '';

    public bool $happened = false;

    /** How likely the risk is, 1–3, or empty while unrated. */
    public string $likelihood = '';

    /** How hard it would hit, 1–3, or empty while unrated. */
    public string $severity = '';

    public string $riskLocationId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocation(): void
    {
        $this->resetPage();
    }

    public function toggleMaterialisedOnly(): void
    {
        $this->materialisedOnly = ! $this->materialisedOnly;
        $this->resetPage();
    }

    public function toggleHigh(): void
    {
        $this->high = ! $this->high;
        $this->resetPage();
    }

    public function toggleUnmitigated(): void
    {
        $this->unmitigated = ! $this->unmitigated;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->location = '';
        $this->materialisedOnly = false;
        $this->unmitigated = false;
        $this->high = false;
        $this->clearStrategyFilter();
    }

    /**
     * Risks matching search/place/materialised/mitigation filters. Each
     * filter can be switched off so summary widgets can share this base
     * query while excluding the very filter they let the user toggle.
     *
     * Since Y-13 (D-93) a register risk belongs to a location row, so the
     * place filter, the strategy chip and the search all read the row. The
     * place's city and program names live in HR, so each search word is
     * resolved to ids there first and the register's own query never joins
     * that connection (AGENT.md §5).
     */
    protected function filteredQuery(bool $includeLocation = true, bool $includeMaterialised = true, bool $includeUnmitigated = true): Builder
    {
        $search = trim($this->search);
        $places = $this->searchedPlaceIds;

        return $this->whereLocationInStrategy(Risk::query()->whereNull('risks.program_id'), 'risks.proposal_location_id')
            ->when($search !== '', function ($query) use ($search, $places): void {
                $query->where(Like::everyWord($search, fn ($query, string $word) => $query
                    ->where(Like::word('name', $word))
                    ->orWhere(Like::word('impact', $word))
                    ->orWhereHas('location', fn ($row) => $row
                        ->whereIn('city_id', $places['cities'][$word] ?? [])
                        ->orWhereIn('program_id', $places['programs'][$word] ?? []))));
            })
            ->when($includeLocation && $this->location !== '', fn ($query) => $query->where('proposal_location_id', (int) $this->location))
            ->when($this->high, fn ($query) => $query->whereRaw('likelihood * severity >= ?', [StrategyRollup::HighRiskScore]))
            ->when($includeMaterialised && $this->materialisedOnly, fn ($query) => $query->where('happened', true))
            // A risk only needs a solution once it has actually happened — a
            // risk that hasn't happened yet has nothing to mitigate, so this
            // filter implicitly scopes to challenges (happened = true).
            ->when($includeUnmitigated && $this->unmitigated, fn ($query) => $query->where('happened', true)->where(function ($query): void {
                $query->whereNull('solution')->orWhere('solution', '');
            }));
    }

    /**
     * The HR cities and programs each search word names, resolved on the HR
     * side (the city by a LIKE, the program through the in-memory name
     * lookup) so the register's own query never joins that connection.
     *
     * @return array{cities: array<string, list<int>>, programs: array<string, list<int>>}
     */
    #[Computed]
    public function searchedPlaceIds(): array
    {
        $search = trim($this->search);

        if ($search === '') {
            return ['cities' => [], 'programs' => []];
        }

        $cities = [];
        $programs = [];

        foreach (Like::searchWords($search) as $word) {
            $cities[$word] = HrCity::query()
                ->where(fn ($query) => $query->where(Like::word('name_en', $word))->orWhere(Like::word('name_ar', $word)))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $programs[$word] = HrProgram::idsMatchingName($word);
        }

        return ['cities' => $cities, 'programs' => $programs];
    }

    /** @return LengthAwarePaginator<int, Risk> */
    /**
     * The Board view: potential risks, then the ones that happened, at most
     * 40 cards each (desk.js).
     *
     * @return list<array{key: string, label: string, count: int, items: Illuminate\Support\Collection<int, Risk>}>
     */
    #[Computed]
    public function boardColumns(): array
    {
        $risks = $this->filteredQuery()
            ->select(['id', 'name', 'happened', 'project_id', 'proposal_location_id'])
            ->with([...self::PlaceRelations, 'project:id,name'])
            ->latest('updated_at')
            ->get();

        return collect([false => __('risks.potential_risks'), true => __('risks.challenges')])
            ->map(function (string $label, int $happened) use ($risks): array {
                $inColumn = $risks->where('happened', (bool) $happened)->values();

                return ['key' => $happened === 1 ? 'happened' : 'potential', 'label' => $label, 'count' => $inColumn->count(), 'items' => $inColumn->take(40)];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function risks(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id', 'proposal_location_id', 'updated_at'])
            ->with([...self::PlaceRelations, 'project:id,name'])
            ->orderByDesc('happened')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /** Materialised risks matching every filter except the materialised toggle itself, so the button's own count stays stable. */
    #[Computed]
    public function materialisedCount(): int
    {
        return $this->filteredQuery(includeMaterialised: false)->where('happened', true)->count();
    }

    /** Challenges (happened risks) lacking a solution, matching every filter except the unmitigated toggle itself. A risk that hasn't happened yet has nothing to mitigate, so this is always scoped to happened = true. */
    #[Computed]
    public function unmitigatedCount(): int
    {
        return $this->filteredQuery(includeUnmitigated: false)
            ->where('happened', true)
            ->where(function ($query): void {
                $query->whereNull('solution')->orWhere('solution', '');
            })
            ->count();
    }

    /** Challenges vs potential risks matching every active filter, for the chart's headline totals. */
    #[Computed]
    public function riskLandscape(): array
    {
        return [
            'challenges' => $this->filteredQuery()->where('happened', true)->count(),
            'potential' => $this->filteredQuery()->where('happened', false)->count(),
        ];
    }

    /**
     * Challenges (risks that happened) vs potential risks (risks that
     * haven't) per place, for the top 8 location rows by total risk count,
     * matching every active filter exactly like the register list below it.
     *
     * Two queries whatever the number of bars: the counts, then the rows
     * those bars name.
     *
     * @return array{categories: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>}
     */
    #[Computed]
    public function locationRiskChart(): array
    {
        $counts = $this->filteredQuery()
            ->whereNotNull('proposal_location_id')
            ->selectRaw('proposal_location_id, happened, count(*) as total')
            ->groupBy('proposal_location_id', 'happened')
            ->get()
            ->groupBy('proposal_location_id');

        $topLocationIds = $counts
            ->sortByDesc(fn (Collection $group): int => (int) $group->sum('total'))
            ->take(8)
            ->keys();

        $labels = ProposalLocation::placeLabels(ProposalLocation::query()
            ->select(['id', 'program_id', 'city_id'])
            ->with('program:id')
            ->whereKey($topLocationIds)
            ->get());

        $countFor = fn (int $locationId, bool $happened): int => (int) ($counts->get($locationId)?->first(fn ($row) => (bool) $row->happened === $happened)->total ?? 0);

        return [
            'categories' => $topLocationIds->map(fn (int $locationId): string => $labels[$locationId] ?? '')->values()->all(),
            'series' => [
                [
                    'name' => __('risks.challenges'),
                    'data' => $topLocationIds->map(fn (int $locationId): int => $countFor($locationId, true))->values()->all(),
                ],
                [
                    'name' => __('risks.potential_risks'),
                    'data' => $topLocationIds->map(fn (int $locationId): int => $countFor($locationId, false))->values()->all(),
                ],
            ],
        ];
    }

    /**
     * The place of each risk in the given list, by row id: one HR query for
     * the whole page, and the same wording as the filter and the chart.
     *
     * A paginator is unwrapped first: `collect()` on one reads its array
     * shape (data, total, links), not the risks inside it.
     *
     * @param  iterable<Risk>|LengthAwarePaginator<int, Risk>  $risks
     * @return array<int, string>
     */
    public function placeLabelsFor(iterable|LengthAwarePaginator $risks): array
    {
        $rows = $risks instanceof LengthAwarePaginator ? $risks->getCollection() : collect($risks);

        return ProposalLocation::placeLabels($rows->pluck('location')->filter()->unique('id')->values());
    }

    /**
     * The places a risk can be logged at or filtered to: every location row
     * carrying a project, by its own name (city · program).
     *
     * @return array<int, string> row id => label
     */
    #[Computed]
    public function locationOptions(): array
    {
        $labels = ProposalLocation::placeLabels(ProposalLocation::query()
            ->select(['id', 'program_id', 'city_id'])
            ->with('program:id')
            ->whereNotNull('project_id')
            ->orderBy('id')
            ->get());

        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return $labels;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Risk::class);

        $this->resetRiskForm();
        $this->showRiskModal = true;
    }

    /** Quick-toggle a risk's materialised state directly from its card, without opening the edit modal. */
    public function setHappened(int $riskId, bool $happened): void
    {
        $risk = Risk::query()->whereNull('program_id')->findOrFail($riskId);

        Gate::authorize('update', $risk);

        $risk->update(['happened' => $happened]);

        Flux::toast(
            variant: 'success',
            text: $happened ? __('risks.risk_marked_materialised') : __('risks.risk_marked_unmaterialised'),
        );
    }

    public function openEditModal(int $riskId): void
    {
        $risk = Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'likelihood', 'severity', 'project_id', 'proposal_location_id'])
            ->findOrFail($riskId);

        Gate::authorize('update', $risk);

        $this->resetValidation();
        $this->editingRiskId = $risk->id;
        $this->name = $risk->name;
        $this->impact = $risk->impact ?? '';
        $this->solution = $risk->solution ?? '';
        $this->happened = $risk->happened;
        $this->likelihood = (string) ($risk->likelihood ?? '');
        $this->severity = (string) ($risk->severity ?? '');
        $this->riskLocationId = (string) $risk->proposal_location_id;
        $this->showRiskModal = true;
    }

    public function saveRisk(): void
    {
        if ($this->editingRiskId === null) {
            Gate::authorize('create', Risk::class);
        } else {
            Gate::authorize('update', Risk::query()->findOrFail($this->editingRiskId));
        }

        $this->name = trim($this->name);
        $this->impact = trim($this->impact);
        $this->solution = trim($this->solution);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'impact' => ['required', 'string'],
            'solution' => ['nullable', 'string'],
            'happened' => ['boolean'],
            'likelihood' => ['nullable', 'integer', 'between:1,3'],
            'severity' => ['nullable', 'integer', 'between:1,3'],
            'riskLocationId' => ['required', 'integer', Rule::exists(ProposalLocation::class, 'id')->whereNotNull('project_id')],
        ], [
            'name.required' => __('risks.enter_a_short_risk_name'),
            'impact.required' => __('risks.describe_what_happens_if_this_risk_materialises'),
            'riskLocationId.required' => __('risks.select_the_project_this_risk_affects'),
            'riskLocationId.exists' => __('risks.the_selected_project_is_no_longer_available'),
        ]);

        $risk = $this->editingRiskId === null
            ? new Risk
            : Risk::query()->findOrFail($this->editingRiskId);

        // Y-13 (D-93): the risk belongs to the location row the form named,
        // and carries that row's proposal and — until Y-18 — its project.
        $row = ProposalLocation::query()->select(['id', 'proposal_id', 'project_id'])->findOrFail((int) $validated['riskLocationId']);

        // Logging a risk at a place, or moving one there, needs the right to
        // log risks on that row.
        if (! $risk->exists || $risk->proposal_location_id !== $row->id) {
            Gate::authorize('create', [Risk::class, $row->id]);
        }

        $risk->fill([
            'name' => $validated['name'],
            'impact' => $validated['impact'],
            'solution' => $validated['solution'] === '' ? null : $validated['solution'],
            'happened' => $validated['happened'],
            'likelihood' => $validated['likelihood'] === '' || $validated['likelihood'] === null ? null : (int) $validated['likelihood'],
            'severity' => $validated['severity'] === '' || $validated['severity'] === null ? null : (int) $validated['severity'],
            'proposal_location_id' => $row->id,
            'proposal_id' => $row->proposal_id,
            'project_id' => $row->project_id,
        ])->save();

        $wasRecentlyCreated = $risk->wasRecentlyCreated;

        $this->showRiskModal = false;
        $this->resetRiskForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('risks.risk_logged') : __('risks.risk_updated'),
        );
    }

    public function confirmDelete(int $riskId): void
    {
        Gate::authorize('delete', Risk::query()->findOrFail($riskId));

        $this->deletingRiskId = $riskId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingRiskId = null;
    }

    /**
     * Risks delete freely: no foreign key anywhere points at this table, so
     * removing one cannot take another row with it.
     */
    public function deleteRisk(): void
    {
        if ($this->deletingRiskId === null) {
            return;
        }

        $risk = Risk::query()->findOrFail($this->deletingRiskId);

        Gate::authorize('delete', $risk);

        $risk->delete();

        $this->showDeleteModal = false;
        $this->deletingRiskId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('risks.risk_deleted'));
    }

    public function closeRiskModal(): void
    {
        $this->showRiskModal = false;
        $this->resetRiskForm();
    }

    protected function resetRiskForm(): void
    {
        $this->resetValidation();
        $this->editingRiskId = null;
        $this->name = '';
        $this->impact = '';
        $this->solution = '';
        $this->happened = false;
        $this->likelihood = '';
        $this->severity = '';
        $this->riskLocationId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $location !== '' || $materialisedOnly || $unmitigated || $high || $this->strategyFilter !== null;
@endphp

<x-desk.register
    class="@container/directory"
    heading-id="risk-register-heading"
    :title="__('common.risks')"
    :count="$this->risks->total()"
    :view="$view === 'board' ? 'board' : 'list'"
    :views="[
        'list' => ['icon' => 'table', 'label' => __('desk.register.table')],
        'board' => ['icon' => 'board', 'label' => __('desk.register.board')],
    ]"
>
    <x-slot:actions>
        @can('create', App\Models\Risk::class)
            <button type="button" class="dk-btn dk-primary" wire:click="openCreateModal" data-test="create-risk-button" data-quick-create><x-desk.icon name="plus" />{{ __('risks.log_risk') }}</button>
        @endcan
    </x-slot:actions>
    <x-slot:search>
        <x-desk.register-search :label="__('risks.search_risks')" :placeholder="__('risks.risk_impact_or_project')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>
    <x-slot:filters>
            <x-desk.strategy-chip :strategy="$this->strategyFilter" />

            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="location" :value="$location">
                <option value="">{{ __('common.all_projects') }}</option>
                @foreach ($this->locationOptions as $locationId => $locationLabel)
                    <option value="{{ $locationId }}">{{ $locationLabel }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <button type="button" class="dk-btn dk-sm" wire:click="toggleMaterialisedOnly" aria-pressed="{{ $materialisedOnly ? 'true' : 'false' }}" data-test="materialised-toggle">
                {{ trans_choice('risks.challenges_count', $this->materialisedCount, ['count' => $this->materialisedCount]) }}
            </button>
            <button type="button" class="dk-btn dk-sm" wire:click="toggleHigh" aria-pressed="{{ $high ? 'true' : 'false' }}" data-test="high-risk-toggle">
                {{ __('risks.high_risks') }}
            </button>
            <button type="button" class="dk-btn dk-sm" wire:click="toggleUnmitigated" aria-pressed="{{ $unmitigated ? 'true' : 'false' }}" data-test="unmitigated-toggle">
                {{ trans_choice('risks.challenges_without_solution', $this->unmitigatedCount, ['count' => $this->unmitigatedCount]) }}
            </button>
        </x-slot:filters>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-secondary-soft bg-ground px-3 py-2.5 type-meta text-secondary-dark dark:border-line dark:bg-surface-2 dark:text-ink" data-test="risk-register-moved-notice">
        <flux:icon.information-circle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
        <span>{{ __('risks.moved_notice') }}</span>
        <a href="{{ route('proposals.index') }}" wire:navigate class="font-semibold underline decoration-secondary-light underline-offset-2 hover:text-primary dark:hover:text-brand-text">{{ __('risks.moved_notice_link') }}</a>
    </p>

    @php $chart = $this->locationRiskChart; $landscape = $this->riskLandscape; @endphp
    @if (count($chart['categories']) > 0)
        <x-ui.panel as="div" x-data="{ open: true }">
            <button
                type="button"
                x-on:click="open = !open"
                :aria-expanded="open.toString()"
                class="flex min-h-12 w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-start"
            >
                <div class="min-w-0">
                    <p class="truncate text-base font-bold text-secondary-dark dark:text-ink">{{ __('risks.risks_by_project') }}</p>
                    <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ __('risks.risks_by_project_hint') }}</p>
                </div>
                <flux:icon.chevron-down variant="micro" class="size-4 shrink-0 text-secondary-medium transition-transform dark:text-ink-muted" x-bind:class="open && 'rotate-180'" />
            </button>

            <div x-show="open" x-collapse class="border-t border-secondary-soft px-2 pt-3 pb-2 dark:border-line">
                <div class="flex flex-wrap items-center gap-4 px-2 pb-2" role="group" aria-label="{{ __('risks.risks_by_project') }}">
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-secondary-dark dark:text-ink">
                        <span class="size-2.5 shrink-0 rounded-full bg-primary dark:bg-status-critical" aria-hidden="true"></span>
                        {{ __('risks.challenges') }} · <span class="tabular-nums">{{ $landscape['challenges'] }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                        <span class="size-2.5 shrink-0 rounded-full bg-secondary-light dark:bg-ink-faint" aria-hidden="true"></span>
                        {{ __('risks.potential_risks') }} · <span class="tabular-nums">{{ $landscape['potential'] }}</span>
                    </span>
                </div>

                <x-ui.chart
                    type="bar"
                    horizontal
                    :label="__('risks.risks_by_project')"
                    :series="$chart['series']"
                    :categories="$chart['categories']"
                    :tones="['critical', 'neutral']"
                    :height="max(count($chart['categories']) * 44, 160)"
                />
            </div>
        </x-ui.panel>
    @endif

    @if ($view === 'board')
        <x-desk.board data-test="risk-board">
            @php $boardPlaces = $this->placeLabelsFor(collect($this->boardColumns)->pluck('items')->flatten()); @endphp
            @foreach ($this->boardColumns as $column)
                <x-desk.board-column :label="$column['label']" :count="$column['count']" :key="$column['key']" wire:key="risk-column-{{ $column['key'] }}">
                    @foreach ($column['items'] as $boardRisk)
                        @can('update', $boardRisk)
                            <x-desk.board-card :click="'openEditModal('.$boardRisk->id.')'" wire:key="risk-card-{{ $boardRisk->id }}" data-test="risk-board-card-{{ $boardRisk->id }}">
                                <strong>{{ $boardRisk->name }}</strong>
                                <span class="dk-is">{{ $boardPlaces[$boardRisk->proposal_location_id] ?? $boardRisk->project?->name }}</span>
                            </x-desk.board-card>
                        @else
                            <x-desk.board-card wire:key="risk-card-{{ $boardRisk->id }}" data-test="risk-board-card-{{ $boardRisk->id }}">
                                <strong>{{ $boardRisk->name }}</strong>
                                <span class="dk-is">{{ $boardPlaces[$boardRisk->proposal_location_id] ?? $boardRisk->project?->name }}</span>
                            </x-desk.board-card>
                        @endcan
                    @endforeach
                </x-desk.board-column>
            @endforeach
        </x-desk.board>
    @else
    <x-ui.panel :subtitle="trans_choice('risks.count_matching_risk', $this->risks->total(), ['count' => $this->risks->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->risks->isEmpty())
            <x-ui.empty-state
                icon="exclamation-triangle"
                :heading="$filtersAreActive ? __('risks.no_matching_risks') : __('common.no_risks_logged')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('risks.log_the_first_risk_against_a_project')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        @can('create', App\Models\Risk::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('risks.log_risk') }}</x-ui.button>@endcan
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="grid grid-cols-1 gap-3 p-3.5 @4xl:grid-cols-2">
                @php $places = $this->placeLabelsFor($this->risks); @endphp
                @foreach ($this->risks as $risk)
                    <article class="flex flex-col gap-2 rounded-lg border border-secondary-soft p-3.5 dark:border-line" wire:key="risk-{{ $risk->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div
                                class="inline-flex shrink-0 overflow-hidden rounded-full border border-secondary-light dark:border-field-line"
                                role="radiogroup"
                                aria-label="{{ __('common.state') }}"
                                data-test="happened-toggle-{{ $risk->id }}"
                            >
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $risk->happened ? 'false' : 'true' }}"
                                    wire:click="setHappened({{ $risk->id }}, false)"
                                    @disabled(! auth()->user()->can('update', $risk))
                                    @class([
                                        'inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold transition-colors',
                                        'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink' => ! $risk->happened,
                                        'text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2' => $risk->happened,
                                    ])
                                >
                                    <span @class([
                                        'size-2.5 shrink-0 rounded-full border',
                                        'border-secondary-dark bg-secondary-dark dark:border-ink-muted dark:bg-ink-muted' => ! $risk->happened,
                                        'border-secondary-light dark:border-ink-faint' => $risk->happened,
                                    ]) aria-hidden="true"></span>
                                    {{ __('risks.potential_risk') }}
                                </button>
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $risk->happened ? 'true' : 'false' }}"
                                    wire:click="setHappened({{ $risk->id }}, true)"
                                    @disabled(! auth()->user()->can('update', $risk))
                                    @class([
                                        'inline-flex items-center gap-1.5 border-s border-secondary-light px-2.5 py-1 text-xs font-semibold transition-colors dark:border-line',
                                        'bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical text-white' => $risk->happened,
                                        'text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2' => ! $risk->happened,
                                    ])
                                >
                                    <span @class([
                                        'size-2.5 shrink-0 rounded-full border',
                                        'border-white bg-white' => $risk->happened,
                                        'border-secondary-light dark:border-ink-faint' => ! $risk->happened,
                                    ]) aria-hidden="true"></span>
                                    {{ __('common.materialised') }}
                                </button>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @can('update', $risk)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="openEditModal({{ $risk->id }})" :aria-label="__('common.edit_name', ['name' => $risk->name])" />@endcan
                                @can('delete', $risk)<x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="confirmDelete({{ $risk->id }})" :aria-label="__('common.delete_name', ['name' => $risk->name])" />@endcan
                            </div>
                        </div>

                        <div class="min-w-0">
                            <p class="type-heading truncate text-secondary-dark dark:text-ink">{{ $risk->name }}</p>
                            {{-- Y-13 (D-93): the risk belongs to a place — one row of a
                                 proposal. The link still opens the project page, which lists
                                 the place today and becomes the row's own page in Y-16. --}}
                            @if (isset($places[$risk->proposal_location_id]))
                                <a href="{{ route('projects.show', $risk->project_id) }}" wire:navigate class="text-xs text-secondary-medium hover:underline dark:text-ink-muted" dir="auto" data-test="risk-place-{{ $risk->id }}">
                                    {{ $places[$risk->proposal_location_id] }}
                                </a>
                            @else
                                <span class="text-xs text-secondary-medium dark:text-ink-muted" dir="auto" data-test="risk-place-{{ $risk->id }}">
                                    {{ $risk->project?->name ?? __('common.unknown_city') }}
                                </span>
                            @endif
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <p class="text-xs text-secondary-medium dark:text-ink-muted">
                                <span class="font-semibold text-secondary-dark dark:text-ink">{{ __('common.impact') }}:</span>
                                {{ $risk->impact ?? '—' }}
                            </p>

                            {{-- A risk only needs a mitigation once it has actually happened; a
                                 potential risk that hasn't occurred yet has nothing to mitigate. --}}
                            @if ($risk->happened)
                                @if ($risk->solution !== null && $risk->solution !== '')
                                    <p class="text-xs text-secondary-medium dark:text-ink-muted">
                                        <span class="font-semibold text-secondary-dark dark:text-ink">{{ __('common.mitigation') }}:</span>
                                        {{ $risk->solution }}
                                    </p>
                                @else
                                    <button
                                        type="button"
                                        wire:click="openEditModal({{ $risk->id }})"
                                        @disabled(! auth()->user()->can('update', $risk))
                                        class="inline-flex w-fit items-center gap-1 rounded-md border border-status-warn/30 bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn hover:opacity-80"
                                    >
                                        <flux:icon.exclamation-circle variant="micro" class="size-3.5" />
                                        {{ __('risks.no_mitigation_recorded') }}
                                    </button>
                                @endif
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->risks" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#risk-register-heading" />
        </x-slot:footer>
    </x-ui.panel>
    @endif

    <x-ui.modal
        wire:model="showRiskModal"
        size="xl"
        :title="$editingRiskId === null ? __('risks.log_risk') : __('risks.edit_risk')"
        submit="saveRisk"
        close="closeRiskModal"
        required-note
    >
        @if ($showRiskModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'riskLocationId', 'happened']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="name" :label="__('risks.risk_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />

                    <x-ui.form-select wire:model="riskLocationId" :label="__('common.project')" :placeholder="__('common.select_a_project')" required>
                        @foreach ($this->locationOptions as $locationId => $locationLabel)
                            <option value="{{ $locationId }}">{{ $locationLabel }}</option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-segmented
                        wire:model="happened"
                        :label="__('common.state')"
                        :options="[
                            ['value' => '0', 'label' => __('risks.potential_risk'), 'dot' => 'border border-secondary-light bg-transparent'],
                            ['value' => '1', 'label' => __('common.materialised'), 'dot' => 'bg-primary dark:bg-status-critical', 'test' => 'form-materialised-option'],
                        ]"
                    />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('risks.section_assessment')" :fields="['likelihood', 'severity', 'impact', 'solution']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    @foreach (['likelihood' => 'risks.likelihood', 'severity' => 'risks.severity'] as $ratingField => $ratingLabel)
                        <x-ui.form-segmented
                            wire:model="{{ $ratingField }}"
                            :label="__($ratingLabel)"
                            :options="[
                                ['value' => '', 'label' => __('risks.not_rated')],
                                ['value' => '1', 'label' => __('risks.rating_low'), 'test' => 'form-'.$ratingField.'-1'],
                                ['value' => '2', 'label' => __('risks.rating_medium'), 'test' => 'form-'.$ratingField.'-2'],
                                ['value' => '3', 'label' => __('risks.rating_high'), 'test' => 'form-'.$ratingField.'-3'],
                            ]"
                        />
                    @endforeach
                    <x-ui.form-textarea wire:model="impact" :label="__('common.impact')" required />
                    <x-ui.form-textarea wire:model="solution" :label="__('common.mitigation')" />
                </div>
            </x-ui.form-section>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-risk-button">
                {{ $editingRiskId === null ? __('risks.log_risk') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        permanent
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('risks.delete_this_risk')"
        :message="__('risks.the_risk_and_its_mitigation_notes_are_removed_nothing_else_i')"
        confirm="deleteRisk"
        :confirm-label="__('risks.delete_risk')"
        confirm-test="confirm-delete-risk"
    />
</x-desk.register>
