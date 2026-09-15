<?php

use App\Models\Strategy;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.strategies')] class extends Component {
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showStrategyModal = false;
    public ?int $editingStrategyId = null;
    public bool $showDeleteModal = false;
    public ?int $deletingStrategyId = null;
    public string $name = '';
    public string $startYear = '';
    public string $endYear = '';

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
    }

    /**
     * Whether the current user may create, edit or delete strategies.
     */
    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('create', Strategy::class);
    }

    /**
     * Whether this strategy may be deleted.
     *
     * programs.strategy_id RESTRICTs: a strategy carrying any programs stays.
     */
    public function canDelete(int $strategyId): bool
    {
        $strategy = Strategy::query()->select(['id'])->withCount('programs')->find($strategyId);

        return $strategy !== null && $strategy->programs_count === 0;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Strategy::class);

        $this->resetStrategyForm();
        $this->showStrategyModal = true;
    }

    public function openEditModal(int $strategyId): void
    {
        $strategy = Strategy::query()->select(['id', 'name', 'start_year', 'end_year'])->findOrFail($strategyId);

        Gate::authorize('update', $strategy);

        $this->resetValidation();
        $this->editingStrategyId = $strategy->id;
        $this->name = $strategy->name;
        $this->startYear = (string) $strategy->start_year;
        $this->endYear = (string) $strategy->end_year;
        $this->showStrategyModal = true;
    }

    public function saveStrategy(): void
    {
        $strategy = $this->editingStrategyId === null
            ? new Strategy
            : Strategy::query()->findOrFail($this->editingStrategyId);

        Gate::authorize($strategy->exists ? 'update' : 'create', $strategy->exists ? $strategy : Strategy::class);

        $this->name = trim($this->name);

        // Deliberately no unique rule: strategies.name is a plain non-unique
        // VARCHAR by standing decision.
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'startYear' => ['required', 'integer', 'min:1900', 'max:2100'],
            'endYear' => ['required', 'integer', 'min:1900', 'max:2100', 'gte:startYear'],
        ], [
            'name.required' => __('strategies.enter_a_strategy_name'),
            'endYear.gte' => __('strategies.end_year_must_not_be_before_the_start_year'),
        ]);

        $strategy->fill([
            'name' => $validated['name'],
            'start_year' => (int) $validated['startYear'],
            'end_year' => (int) $validated['endYear'],
        ])->save();

        $wasRecentlyCreated = $strategy->wasRecentlyCreated;

        $this->showStrategyModal = false;
        $this->resetStrategyForm();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('strategies.strategy_created') : __('strategies.strategy_updated'),
        );
    }

    public function confirmDelete(int $strategyId): void
    {
        $this->deletingStrategyId = $strategyId;
        $this->showDeleteModal = true;
    }

    public function deleteStrategy(): void
    {
        if ($this->deletingStrategyId === null) {
            return;
        }

        $strategy = Strategy::query()->findOrFail($this->deletingStrategyId);

        Gate::authorize('delete', $strategy);

        if (! $this->canDelete($this->deletingStrategyId)) {
            $this->showDeleteModal = false;
            $this->deletingStrategyId = null;

            Flux::toast(
                variant: 'danger',
                text: __('strategies.this_strategy_has_linked_programs_and_cannot_be_deleted'),
            );

            return;
        }

        $strategy->delete();

        $this->showDeleteModal = false;
        $this->deletingStrategyId = null;

        Flux::toast(variant: 'success', text: __('strategies.strategy_deleted'));
    }

    public function closeStrategyModal(): void
    {
        $this->showStrategyModal = false;
        $this->resetStrategyForm();
    }

    protected function resetStrategyForm(): void
    {
        $this->resetValidation();
        $this->editingStrategyId = null;
        $this->name = '';
        $this->startYear = '';
        $this->endYear = '';
    }

    /**
     * @return array{
     *     strategies: Collection<int, array{id: int, name: string, start_year: int, end_year: int, duration_years: int, programs_count: int, status: string}>,
     *     total: int,
     *     has_records: bool,
     *     status_counts: array{active: int, upcoming: int, completed: int},
     *     current_year: int,
     *     first_year: int|null,
     *     last_year: int|null,
     *     filters_active: bool
     * }
     */
    #[Computed]
    public function portfolio(): array
    {
        $currentYear = now()->year;
        $search = trim($this->search);

        $searchFiltered = Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year'])
            ->withCount('programs')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(static function (Strategy $strategy) use ($currentYear): array {
                $status = match (true) {
                    $currentYear < $strategy->start_year => 'upcoming',
                    $currentYear > $strategy->end_year => 'completed',
                    default => 'active',
                };

                return [
                    'id' => $strategy->id,
                    'name' => $strategy->name,
                    'start_year' => $strategy->start_year,
                    'end_year' => $strategy->end_year,
                    'duration_years' => $strategy->end_year - $strategy->start_year + 1,
                    'programs_count' => $strategy->programs_count,
                    'status' => $status,
                ];
            });

        $statusCounts = [
            'active' => $searchFiltered->where('status', 'active')->count(),
            'upcoming' => $searchFiltered->where('status', 'upcoming')->count(),
            'completed' => $searchFiltered->where('status', 'completed')->count(),
        ];

        $filtered = $this->status !== ''
            ? $searchFiltered->where('status', $this->status)
            : $searchFiltered;

        $orderedStrategies = $filtered->where('status', 'active')->sortByDesc('start_year')
            ->concat($filtered->where('status', 'upcoming')->sortBy('start_year'))
            ->concat($filtered->where('status', 'completed')->sortByDesc('end_year'))
            ->values();

        return [
            'strategies' => $orderedStrategies,
            'total' => $filtered->count(),
            'has_records' => $searchFiltered->isNotEmpty(),
            'status_counts' => $statusCounts,
            'current_year' => $currentYear,
            'first_year' => $filtered->isEmpty() ? null : (int) $filtered->min('start_year'),
            'last_year' => $filtered->isEmpty() ? null : (int) $filtered->max('end_year'),
            'filters_active' => $search !== '' || $this->status !== '',
        ];
    }
};
?>

@php
    $portfolio = $this->portfolio;

    $toneFor = fn (string $status): string => match ($status) {
        'active' => 'good',
        default => 'neutral',
    };

    $statusLabel = fn (string $status): string => match ($status) {
        'active' => __('common.active'),
        'upcoming' => __('strategies.upcoming'),
        default => __('common.completed'),
    };

    $statusChipClasses = fn (string $status): string => match ($status) {
        'active' => 'bg-status-good text-white',
        'upcoming' => 'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white',
    };

    $timelineItems = $portfolio['strategies']->map(fn ($strategy) => [
        'key' => $strategy['id'],
        'label' => $strategy['name'],
        'sublabel' => trans_choice('strategies.count_program', $strategy['programs_count'], ['count' => $strategy['programs_count']]),
        'start' => Carbon::create($strategy['start_year'], 1, 1),
        'end' => Carbon::create($strategy['end_year'], 12, 31),
        'tone' => $toneFor($strategy['status']),
        'href' => route('strategies.show', $strategy['id']),
    ])->all();

    $timelineFrom = $portfolio['first_year'] !== null ? Carbon::create($portfolio['first_year'], 1, 1) : null;
    $timelineTo = $portfolio['last_year'] !== null ? Carbon::create($portfolio['last_year'], 12, 31) : null;
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="strategies-heading"
>
    <x-ui.page-header
        :title="__('common.strategies')"
        :count="$portfolio['total']"
        :description="__('strategies.a_complete_view_of_the_strategic_planning_cycles_that_guide')"
        headingId="strategies-heading"
    >
        <x-slot:filters>
            @if ($this->canManage)
                <x-ui.button tone="primary" icon="plus" class="min-h-11 touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-strategy-button">
                    {{ __('strategies.add_strategy') }}
                </x-ui.button>
            @endif

            <x-ui.input
                type="search"
                icon="magnifying-glass"
                :label="__('strategies.search_strategies')"
                :placeholder="__('strategies.search_by_name')"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <x-ui.select :label="__('strategies.filter_by_status')" wire:model.live="status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                <flux:select.option value="active">{{ __('common.active') }}</flux:select.option>
                <flux:select.option value="upcoming">{{ __('strategies.upcoming') }}</flux:select.option>
                <flux:select.option value="completed">{{ __('common.completed') }}</flux:select.option>
            </x-ui.select>
        </x-slot:filters>

        <x-slot:summary>
            @if ($portfolio['has_records'] && $portfolio['status_counts']['active'] === 0 && ! $portfolio['filters_active'])
                <div class="mt-3 inline-flex w-fit items-center gap-2 rounded-md border border-primary/40 bg-primary/5 px-3 py-2 text-xs font-semibold text-primary" role="status">
                    <flux:icon.clock class="size-4" />
                    {{ __('strategies.no_strategy_currently_covers_year', ['year' => $portfolio['current_year']]) }}
                </div>
            @elseif ($portfolio['status_counts']['active'] > 0 && ! $portfolio['filters_active'])
                <p class="mt-3 type-meta text-secondary-medium dark:text-secondary-soft">
                    {{ trans_choice('strategies.count_current_period', $portfolio['status_counts']['active'], ['count' => $portfolio['status_counts']['active']]) }}
                </p>
            @endif
        </x-slot:summary>
    </x-ui.page-header>

    @if ($portfolio['strategies']->isEmpty())
        <x-ui.empty-state
            icon="flag"
            :heading="$portfolio['filters_active'] ? __('strategies.no_matching_strategic_periods') : __('strategies.no_strategic_periods_yet')"
            :body="$portfolio['filters_active'] ? __('common.change_the_search_or_filters_and_try_again') : __('strategies.strategy_records_will_appear_here_once_planning_cycles_are_d')"
        >
            @if ($portfolio['filters_active'])
                <x-slot:action>
                    <flux:button size="sm" wire:click="resetFilters">{{ __('common.reset_filters') }}</flux:button>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    @else
        <div class="flex flex-1 flex-col gap-5">
            <x-ui.timeline :from="$timelineFrom" :to="$timelineTo" :items="$timelineItems" />

            <x-ui.data-table :caption="__('strategies.strategic_planning_cycles')">
                <x-slot:head>
                    <th scope="col">{{ __('common.strategy') }}</th>
                    <th scope="col">{{ __('common.period') }}</th>
                    <th scope="col">{{ __('strategies.duration') }}</th>
                    <th scope="col">{{ __('common.status') }}</th>
                    <th scope="col" class="text-end!">{{ __('common.programs') }}</th>
                    <th scope="col" class="sr-only">{{ __('common.actions') }}</th>
                </x-slot:head>

                @foreach ($portfolio['strategies'] as $strategy)
                    <tr wire:key="strategy-row-{{ $strategy['id'] }}">
                        <x-ui.data-table.cell :label="__('common.strategy')">
                            <span class="truncate font-semibold text-secondary-dark dark:text-white">{{ $strategy['name'] }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.period')">
                            <bdi class="tabular-nums">{{ $strategy['start_year'] }}–{{ $strategy['end_year'] }}</bdi>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('strategies.duration')">
                            {{ trans_choice('strategies.count_years', $strategy['duration_years'], ['count' => $strategy['duration_years']]) }}
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.status')">
                            <span class="{{ $statusChipClasses($strategy['status']) }} inline-flex shrink-0 items-center rounded-md px-2.5 py-1 text-xs font-semibold">
                                {{ $statusLabel($strategy['status']) }}
                            </span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.programs')" numeric>
                            {{ $strategy['programs_count'] }}
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.actions')">
                            <div class="flex items-center justify-end gap-1 @3xl:justify-start">
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('strategies.show', $strategy['id'])" wire:navigate :aria-label="__('common.view_name', ['name' => $strategy['name']])" />

                                @if ($this->canManage)
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $strategy['id'] }})" :aria-label="__('common.edit_name', ['name' => $strategy['name']])" />

                                    @if ($strategy['programs_count'] === 0)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $strategy['id'] }})" :aria-label="__('common.delete_name', ['name' => $strategy['name']])" />
                                    @else
                                        <flux:tooltip :content="__('strategies.strategies_with_linked_programs_cannot_be_deleted')">
                                            <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                @endif
                            </div>
                        </x-ui.data-table.cell>
                    </tr>
                @endforeach
            </x-ui.data-table>
        </div>
    @endif

    @if ($this->canManage)
        <flux:modal wire:model="showStrategyModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
            <form wire:submit="saveStrategy" class="space-y-5">
                <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                    <flux:heading size="lg">{{ $editingStrategyId === null ? __('strategies.add_strategy') : __('strategies.edit_strategy') }}</flux:heading>
                    @if ($editingStrategyId === null)
                        <flux:subheading>{{ __('strategies.strategy_names_need_not_be_unique') }}</flux:subheading>
                    @endif
                </div>

                <x-ui.input wire:model="name" :label="__('strategies.strategy_name')" type="text" maxlength="255" required autofocus />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input wire:model="startYear" :label="__('strategies.period_start')" type="number" min="1900" max="2100" required />
                    <x-ui.input wire:model="endYear" :label="__('strategies.period_end')" type="number" min="1900" max="2100" required />
                </div>

                <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                    <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeStrategyModal">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-strategy-button">
                        {{ $editingStrategyId === null ? __('strategies.create_strategy') : __('common.save_changes') }}
                    </x-ui.button>
                </div>
            </form>
        </flux:modal>

        <flux:modal wire:model="showDeleteModal" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('strategies.delete_this_strategy') }}</flux:heading>
                <flux:subheading>{{ __('strategies.only_a_strategy_with_no_linked_programs_can_be_deleted') }}</flux:subheading>
                <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                    <x-ui.button type="button" wire:click="$set('showDeleteModal', false)">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button type="button" tone="primary" wire:click="deleteStrategy" data-test="confirm-delete-strategy">{{ __('strategies.delete_strategy') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
