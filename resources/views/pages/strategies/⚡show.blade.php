<?php

use App\Livewire\Concerns\Strategies\DeletesStrategy;
use App\Livewire\Concerns\Strategies\LinksStrategyPrograms;
use App\Livewire\Concerns\Strategies\ManagesStrategicPillars;
use App\Livewire\Concerns\Strategies\ManagesStrategyForm;
use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\StrategicPlan;
use App\Models\Strategy;
use App\Support\StrategyAttention;
use App\Support\StrategyRollup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.strategy')] class extends Component {
    use DeletesStrategy;
    use LinksStrategyPrograms;
    use ManagesStrategicPillars;
    use ManagesStrategyForm;

    #[Locked]
    public int $strategyId;

    public function mount(Strategy $strategy): void
    {
        $this->strategyId = $strategy->id;
    }

    /**
     * After a delete there is no strategy left to show: go back to the list.
     */
    protected function strategyDeleted(): void
    {
        $this->redirectRoute('strategies.index', navigate: true);
    }

    #[Computed]
    public function strategy(): Strategy
    {
        return Strategy::query()
            ->select(['id', 'slug', 'name', 'start_year', 'end_year', 'updated_at'])
            ->withCount(['programs', 'pillars'])
            ->findOrFail($this->strategyId);
    }

    /**
     * Each program's strategic plan status, keyed by program id; a program
     * with no plan written yet is absent. One query for the whole page.
     *
     * @return array<int, \App\Enums\StrategicPlanStatus>
     */
    #[Computed]
    public function strategicPlanStatuses(): array
    {
        return StrategicPlan::query()
            ->where('strategy_id', $this->strategyId)
            ->get(['program_id', 'status'])
            ->mapWithKeys(fn (StrategicPlan $plan): array => [$plan->program_id => $plan->status])
            ->all();
    }

    /**
     * Where the strategy stands against today's year.
     *
     * @return 'active'|'upcoming'|'completed'
     */
    #[Computed]
    public function status(): string
    {
        $year = now()->year;

        return match (true) {
            $year < $this->strategy->start_year => 'upcoming',
            $year > $this->strategy->end_year => 'completed',
            default => 'active',
        };
    }

    /** @return Builder<Program> */
    protected function programsQuery(): Builder
    {
        return Program::query()
            ->select(['id', 'strategy_id', 'strategic_pillar_id', 'manager_id'])
            ->where('strategy_id', $this->strategyId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount(['projects']);
    }

    /**
     * Every program under this strategy, unpaginated: every figure, the
     * program rows under the pillars and their status read from here.
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function allPrograms(): Collection
    {
        return $this->programsQuery()->get();
    }

    /**
     * What the strategy adds up to (targets on pace, funding, high risks,
     * late work), as on the strategies list.
     *
     * @return array{
     *     results: array{on: int, total: int, tone: 'good'|'warn'|'critical'|null, by_program: array<int, array{on: int, total: int}>}|null,
     *     funding: array{lines: list<array{currency: string, requested: float, agreed: float, percent: int|null}>, percent: int|null, tone: 'good'|'warn'|'critical'|null}|null,
     *     risks: int|null,
     *     late: array{milestones: int|null, reports: int|null, total: int}|null
     * }
     */
    #[Computed]
    public function rollup(): array
    {
        return StrategyRollup::for(auth()->user(), [$this->strategyId])[$this->strategyId];
    }

    /**
     * Every (program, year) pair inside the strategy's span whose annual plan
     * has at least one plan indicator, as a "program:year" => true lookup. An
     * empty AnnualPlan shell does not count. One query for the whole page.
     *
     * @return array<string, true>
     */
    #[Computed]
    public function plannedYears(): array
    {
        $strategy = $this->strategy;
        $programIds = $this->allPrograms->pluck('id');

        if ($programIds->isEmpty()) {
            return [];
        }

        return AnnualPlan::query()
            ->whereIn('program_id', $programIds)
            ->whereBetween('year', [$strategy->start_year, $strategy->end_year])
            ->whereHas('planIndicators')
            ->get(['program_id', 'year'])
            ->mapWithKeys(fn (AnnualPlan $plan) => [$plan->program_id.':'.$plan->year => true])
            ->all();
    }

    /**
     * How many programs have a plan for this year, read from plannedYears().
     * Null when the strategy is not running this year or the viewer may not
     * see annual plans (as StrategyRollup::planReadiness() on the list).
     *
     * @return array{planned: int, programs: int, missing: int}|null
     */
    #[Computed]
    public function currentYearPlans(): ?array
    {
        if ($this->status !== 'active' || ! auth()->user()->can('annual-plans.view')) {
            return null;
        }

        $year = now()->year;
        $programs = $this->allPrograms->count();
        $planned = $this->allPrograms->filter(fn (Program $program): bool => isset($this->plannedYears[$program->id.':'.$year]))->count();

        return ['planned' => $planned, 'programs' => $programs, 'missing' => $programs - $planned];
    }

    /**
     * Each program's pace on its rated targets (StrategyRollup::programPace()),
     * keyed by program id. Empty when the viewer may not see results.
     *
     * @return array<int, 'on_pace'|'behind'|null>
     */
    #[Computed]
    public function programPaces(): array
    {
        $results = $this->rollup['results'];

        if ($results === null) {
            return [];
        }

        return $this->allPrograms
            ->mapWithKeys(fn (Program $program): array => [$program->id => StrategyRollup::programPace($results['by_program'][$program->id] ?? null)])
            ->all();
    }

    /**
     * The one status each program row shows, first match wins: no plan for
     * this year (running strategies only), falling behind, on pace, nothing
     * rated yet. Null when the viewer may not see results and nothing about
     * plans applies.
     *
     * @return array<int, 'no_plan'|'behind'|'on_pace'|'no_progress'|null>
     */
    #[Computed]
    public function programStates(): array
    {
        $year = now()->year;
        $checksPlans = $this->currentYearPlans !== null;
        $seesResults = $this->rollup['results'] !== null;

        return $this->allPrograms
            ->mapWithKeys(fn (Program $program): array => [$program->id => match (true) {
                $checksPlans && ! isset($this->plannedYears[$program->id.':'.$year]) => 'no_plan',
                ! $seesResults => null,
                default => $this->programPaces[$program->id] ?? 'no_progress',
            }])
            ->all();
    }

    /**
     * The "Needs attention" lines: the four the list shows, then the programs
     * falling behind.
     *
     * @return list<array{text: \Illuminate\Support\HtmlString, label: string, href: string, tone: 'critical'|'warn'}>
     */
    #[Computed]
    public function attention(): array
    {
        $behind = $this->allPrograms
            ->filter(fn (Program $program): bool => ($this->programPaces[$program->id] ?? null) === 'behind')
            ->map(fn (Program $program): string => $program->name)
            ->sort(fn (string $first, string $second): int => strcasecmp($first, $second))
            ->values();

        return [
            ...StrategyAttention::items($this->strategyId, $this->rollup, $this->currentYearPlans, now()->year),
            ...StrategyAttention::programsBehind($this->strategyId, array_values($behind->all())),
        ];
    }
};
?>

@php
    $strategy = $this->strategy;
    $status = $this->status;
    $rollup = $this->rollup;
    $currentYear = now()->year;
    $spanYears = $strategy->end_year - $strategy->start_year + 1;
    $verdict = $rollup['results'] === null ? null : \App\Support\StrategyRollup::verdict($rollup['results']['on'], $rollup['results']['total'], $rollup['results']['unrated']);
    $monthYear = fn (int $year, int $month): string => '<bdi>'.e(\Illuminate\Support\Carbon::create($year, $month)->translatedFormat(__('formats.month_year'))).'</bdi>';
    $deleteBlocker = match (true) {
        $strategy->programs_count > 0 => __('strategies.cannot_delete_has_programs'),
        $strategy->pillars_count > 0 => __('strategies.cannot_delete_has_pillars'),
        default => null,
    };
    $showsAttention = $rollup['late'] !== null || $rollup['risks'] !== null || $rollup['results'] !== null || $this->currentYearPlans !== null;
@endphp

<div class="dk-story dk-strat" data-test="strategy-page">
    <div>
        <a class="dk-back" href="{{ \App\Support\BackUrl::for(route('strategies.index')) }}" wire:navigate data-story-back><x-desk.icon name="back" class="dk-flip" />{{ __('common.strategies') }}</a>

        <nav class="dk-crumb" aria-label="{{ __('ui.breadcrumb') }}">
            <a href="{{ route('strategies.index') }}" wire:navigate>{{ __('common.strategies') }}</a>
            ›
            <span aria-current="page">{{ $strategy->name }}</span>
        </nav>

        <div class="dk-story-h dk-strat-h">
            <div class="min-w-0">
                <p class="dk-eyebrow">{{ __('common.strategy') }}</p>
                <h1 class="page-title">{{ $strategy->name }}</h1>
                <p class="dk-strat-meta">
                    <span @class(['dk-pill', 'dk-good' => $status === 'active', 'dk-act' => $status === 'upcoming', 'dk-mute' => $status === 'completed']) data-test="strategy-status">{{ match ($status) {
                        'active' => __('strategies.status_active'),
                        'upcoming' => __('strategies.upcoming'),
                        default => __('strategies.status_completed'),
                    } }}</span>
                    <span>
                        @if ($status === 'active')
                            {!! __('strategies.year_of_total_span', [
                                'current' => '<bdi>'.($currentYear - $strategy->start_year + 1).'</bdi>',
                                'total' => '<bdi>'.$spanYears.'</bdi>',
                                'start' => $monthYear($strategy->start_year, 1),
                                'end' => $monthYear($strategy->end_year, 12),
                            ]) !!}
                        @else
                            {!! __('strategies.span_dates', ['start' => $monthYear($strategy->start_year, 1), 'end' => $monthYear($strategy->end_year, 12)]) !!}
                        @endif
                    </span>
                </p>
            </div>

            @if ($this->canEditStrategies || $this->canLinkPrograms || $this->canDeleteStrategies)
                <div class="dk-strat-acts">
                    @if ($this->canEditStrategies)
                        <button type="button" class="dk-btn" wire:click="openEditModal({{ $strategy->id }})" data-test="edit-strategy-button"><x-desk.icon name="edit" />{{ __('strategies.edit_details') }}</button>
                    @endif

                    @if ($this->canLinkPrograms || $this->canDeleteStrategies)
                        <flux:dropdown position="bottom" align="end">
                            <button type="button" class="dk-btn group" aria-label="{{ __('strategies.more_actions') }}" data-test="strategy-more-actions">
                                <span aria-hidden="true">⋯</span><span class="sr-only group-focus-visible:not-sr-only">{{ __('strategies.more_actions') }}</span>
                            </button>

                            <flux:menu>
                                @if ($this->canLinkPrograms)
                                    <flux:menu.item wire:click="openProgramsModal({{ $strategy->id }})" data-test="strategy-link-programs">{{ __('strategies.link_programs') }}</flux:menu.item>
                                @endif

                                @if ($this->canDeleteStrategies)
                                    @if ($deleteBlocker === null)
                                        <flux:menu.item variant="danger" wire:click="confirmDelete({{ $strategy->id }})" data-test="delete-strategy-button">{{ __('strategies.delete_strategy') }}</flux:menu.item>
                                    @else
                                        <flux:menu.item variant="danger" disabled aria-describedby="delete-strategy-reason" data-test="delete-strategy-button">{{ __('strategies.delete_strategy') }}</flux:menu.item>
                                        <p id="delete-strategy-reason" class="max-w-64 px-2 pb-1.5 text-xs text-secondary-medium dark:text-ink-muted" data-test="delete-strategy-reason">{{ $deleteBlocker }}</p>
                                    @endif
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @php
        $notSetUp = $this->allPrograms->isEmpty();
    @endphp

    <section class="dk-strat-sum" aria-label="{{ __('strategies.strategy_summary') }}">
        @if ($notSetUp)
            {{-- Nothing linked: one line instead of a year line, zeros and "Nothing needs your attention". --}}
            <x-strategies.not-set-up :strategy-id="$strategy->id" :can-link="$this->canLinkPrograms" />
        @else
            <x-strategies.year-line :start="$strategy->start_year" :end="$strategy->end_year" :current="$currentYear" :tone="$verdict['tone'] ?? null" />

            <x-strategies.verdict :results="$rollup['results']" />

            @include('pages.strategies._show-figures')

            @if ($showsAttention)
                <x-strategies.attention-list
                    :items="$this->attention"
                    :heading="$this->attention === [] ? __('strategies.needs_attention') : __('strategies.needs_attention_count', ['count' => count($this->attention)])"
                />
            @endif
        @endif
    </section>

    {{-- An empty strategy with no pillars has nothing to build for a viewer; an editor still sees "Add pillar". --}}
    @unless ($notSetUp && $this->pillarGroups['pillars'] === [] && ! auth()->user()->can('strategies.update'))
        @include('pages.strategies._show-pillars')
    @endunless

    @include('pages.strategies._strategy-form-modal')
    @include('pages.strategies._programs-modal')
    @include('pages.strategies._delete-modal')
</div>
