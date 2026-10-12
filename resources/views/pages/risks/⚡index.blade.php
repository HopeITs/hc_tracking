<?php

use App\Support\Like;
use App\Models\Project;
use App\Models\Risk;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.risks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: false)]
    public bool $materialisedOnly = false;

    #[Url(except: false)]
    public bool $unmitigated = false;

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

    public string $riskProjectId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function toggleMaterialisedOnly(): void
    {
        $this->materialisedOnly = ! $this->materialisedOnly;
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
        $this->project = '';
        $this->materialisedOnly = false;
        $this->unmitigated = false;
        $this->resetPage();
    }

    /**
     * Risks matching search/project/materialised/mitigation filters. Each
     * filter can be switched off so summary widgets can share this base
     * query while excluding the very filter they let the user toggle.
     */
    protected function filteredQuery(bool $includeProject = true, bool $includeMaterialised = true, bool $includeUnmitigated = true): Builder
    {
        $search = trim($this->search);

        return Risk::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', Like::contains($search))
                        ->orWhere('impact', 'like', Like::contains($search))
                        ->orWhereHas('project', fn ($query) => $query->where('name', 'like', Like::contains($search)));
                });
            })
            ->when($includeProject && $this->project !== '', fn ($query) => $query->where('project_id', (int) $this->project))
            ->when($includeMaterialised && $this->materialisedOnly, fn ($query) => $query->where('happened', true))
            // A risk only needs a solution once it has actually happened — a
            // risk that hasn't happened yet has nothing to mitigate, so this
            // filter implicitly scopes to challenges (happened = true).
            ->when($includeUnmitigated && $this->unmitigated, fn ($query) => $query->where('happened', true)->where(function ($query): void {
                $query->whereNull('solution')->orWhere('solution', '');
            }));
    }

    /** @return LengthAwarePaginator<int, Risk> */
    #[Computed]
    public function risks(): LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id', 'updated_at'])
            ->with(['project:id,name,city_id', 'project.city:id,name_en,name_ar'])
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
     * haven't) per project, for the top 8 projects by total risk count,
     * matching every active filter exactly like the register list below it.
     *
     * @return array{categories: array<int, string>, series: array<int, array{name: string, data: array<int, int>}>}
     */
    #[Computed]
    public function projectRiskChart(): array
    {
        $rows = $this->filteredQuery()
            ->selectRaw('project_id, happened, count(*) as total')
            ->groupBy('project_id', 'happened')
            ->get()
            ->groupBy('project_id');

        $topProjectIds = $rows
            ->sortByDesc(fn (Collection $group): int => (int) $group->sum('total'))
            ->take(8)
            ->keys();

        $projectNames = Project::query()->whereIn('id', $topProjectIds)->pluck('name', 'id');

        $categories = $topProjectIds->map(fn (int $projectId): string => $projectNames[$projectId] ?? '')->values()->all();

        $countFor = fn (int $projectId, bool $happened): int => (int) ($rows->get($projectId)?->first(fn ($row) => (bool) $row->happened === $happened)->total ?? 0);

        return [
            'categories' => $categories,
            'series' => [
                [
                    'name' => __('risks.challenges'),
                    'data' => $topProjectIds->map(fn (int $projectId): int => $countFor($projectId, true))->values()->all(),
                ],
                [
                    'name' => __('risks.potential_risks'),
                    'data' => $topProjectIds->map(fn (int $projectId): int => $countFor($projectId, false))->values()->all(),
                ],
            ],
        ];
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
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
        $risk = Risk::query()->findOrFail($riskId);

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
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->findOrFail($riskId);

        Gate::authorize('update', $risk);

        $this->resetValidation();
        $this->editingRiskId = $risk->id;
        $this->name = $risk->name;
        $this->impact = $risk->impact ?? '';
        $this->solution = $risk->solution ?? '';
        $this->happened = $risk->happened;
        $this->riskProjectId = (string) $risk->project_id;
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
            'riskProjectId' => ['required', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'name.required' => __('risks.enter_a_short_risk_name'),
            'impact.required' => __('risks.describe_what_happens_if_this_risk_materialises'),
            'riskProjectId.required' => __('risks.select_the_project_this_risk_affects'),
            'riskProjectId.exists' => __('risks.the_selected_project_is_no_longer_available'),
        ]);

        $risk = $this->editingRiskId === null
            ? new Risk
            : Risk::query()->findOrFail($this->editingRiskId);

        // Logging a risk on a project, or moving one onto it, needs the right
        // to log risks there.
        if (! $risk->exists || $risk->project_id !== (int) $validated['riskProjectId']) {
            Gate::authorize('create', [Risk::class, (int) $validated['riskProjectId']]);
        }

        $risk->fill([
            'name' => $validated['name'],
            'impact' => $validated['impact'],
            'solution' => $validated['solution'] === '' ? null : $validated['solution'],
            'happened' => $validated['happened'],
            'project_id' => (int) $validated['riskProjectId'],
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
        $this->riskProjectId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $project !== '' || $materialisedOnly || $unmitigated;
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="risk-register-heading">
    <x-ui.page-header
        heading-id="risk-register-heading"
        :title="__('common.risks')"
        :count="$this->risks->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('risks.search_risks')" :placeholder="__('risks.risk_impact_or_project')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                <option value="">{{ __('common.all_projects') }}</option>
                @foreach ($this->projectOptions as $projectOption)
                    <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('create', App\Models\Risk::class)<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-risk-button">
                {{ __('risks.log_risk') }}
            </x-ui.button>@endcan
        </x-slot:actions>

        <x-slot:summary>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="toggleMaterialisedOnly"
                    aria-pressed="{{ $materialisedOnly ? 'true' : 'false' }}"
                    data-test="materialised-toggle"
                    @class([
                        'inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                        'border-primary bg-primary dark:bg-ink dark:text-surface-0 dark:border-ink text-white' => $materialisedOnly,
                        'border-secondary-light text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2' => ! $materialisedOnly,
                    ])
                >
                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                    {{ trans_choice('risks.challenges_count', $this->materialisedCount, ['count' => $this->materialisedCount]) }}
                </button>

                <button
                    type="button"
                    wire:click="toggleUnmitigated"
                    aria-pressed="{{ $unmitigated ? 'true' : 'false' }}"
                    data-test="unmitigated-toggle"
                    @class([
                        'inline-flex shrink-0 items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                        'border-status-warn/30 bg-status-warn-soft text-status-warn' => $unmitigated,
                        'border-secondary-light text-secondary-medium hover:bg-secondary-soft dark:text-ink-muted dark:hover:bg-surface-2' => ! $unmitigated,
                    ])
                >
                    <flux:icon.shield-exclamation variant="micro" class="size-3.5" />
                    {{ trans_choice('risks.challenges_without_solution', $this->unmitigatedCount, ['count' => $this->unmitigatedCount]) }}
                </button>
            </div>
        </x-slot:summary>
    </x-ui.page-header>

    @php $chart = $this->projectRiskChart; $landscape = $this->riskLandscape; @endphp
    @if (count($chart['categories']) > 0)
        <div x-data="{ open: true }" class="overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised">
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
        </div>
    @endif

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
                            <a href="{{ route('projects.show', $risk->project) }}" wire:navigate class="text-xs text-secondary-medium hover:underline dark:text-ink-muted">
                                {{ $risk->project->name }} · {{ $risk->project->city?->name ?? __('common.unknown_city') }}
                            </a>
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

    <x-ui.modal
        wire:model="showRiskModal"
        size="xl"
        :title="$editingRiskId === null ? __('risks.log_risk') : __('risks.edit_risk')"
        submit="saveRisk"
        close="closeRiskModal"
        required-note
    >
        @if ($showRiskModal)
            <x-ui.form-section :title="__('common.section_details')" :fields="['name', 'riskProjectId', 'happened']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
                    <x-ui.form-input wire:model="name" :label="__('risks.risk_name')" maxlength="255" required autofocus class="@2xl/modal:col-span-2" />

                    <x-ui.form-select wire:model="riskProjectId" :label="__('common.project')" :placeholder="__('common.select_a_project')" required>
                        @foreach ($this->projectOptions as $projectOption)
                            <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
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

            <x-ui.form-section :title="__('risks.section_assessment')" :fields="['impact', 'solution']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
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
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('risks.delete_this_risk')"
        :message="__('risks.the_risk_and_its_mitigation_notes_are_removed_nothing_else_i')"
        confirm="deleteRisk"
        :confirm-label="__('risks.delete_risk')"
        confirm-test="confirm-delete-risk"
    />
</section>
