<?php

use App\Livewire\Concerns\AnnualPlans\ReadsAnnualPlanSummary;
use App\Livewire\Concerns\Results\FiltersResultsChain;
use App\Models\AnnualPlan;
use App\Models\HrEmployee;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.annual_plan')] class extends Component {
    use FiltersResultsChain;
    use ReadsAnnualPlanSummary;

    #[Locked]
    public int $annualPlanId;

    public bool $showPlanModal = false;

    public bool $confirmingDelete = false;

    public string $planManagerId = '';

    public string $planYear = '';

    public function mount(AnnualPlan $annualPlan): void
    {
        $this->annualPlanId = $annualPlan->id;
    }

    #[Computed]
    public function plan(): AnnualPlan
    {
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year', 'updated_at'])
            ->with([
                'program:id,strategy_id',
                'program.strategy:id,name',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount('planIndicators')
            ->findOrFail($this->annualPlanId);
    }

    /**
     * The fiscal years the form offers.
     *
     * This plan's own year is always among them, so an older plan stays
     * editable without its year silently moving.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function yearFormOptions(): array
    {
        $thisYear = (int) now()->year;

        $years = AnnualPlan::query()
            ->select('year')
            ->distinct()
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();

        $years = [...$years, $thisYear, $thisYear + 1, $this->plan->year];

        if ($this->planYear !== '') {
            $years[] = (int) $this->planYear;
        }

        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }

    /**
     * Load the plan's own values into the edit form.
     *
     * The program is not offered here: moving a plan to another program on its
     * detail page would change what the page is about under the reader. That
     * edit belongs to the matrix, where both programs are on screen.
     */
    public function openEditModal(): void
    {
        $plan = $this->plan;

        Gate::authorize('update', $plan);

        $this->resetValidation();
        $this->planManagerId = (string) $plan->manager_id;
        $this->planYear = (string) $plan->year;
        $this->showPlanModal = true;
    }

    /**
     * Save the manager and fiscal year.
     *
     * The (program_id, year) pair is unique in the database, so moving the plan
     * onto a year the same program already covers is caught here rather than by
     * the driver.
     */
    public function savePlan(): void
    {
        $plan = $this->plan;

        Gate::authorize('update', $plan);

        $validated = $this->validate([
            'planManagerId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'planYear' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
                Rule::unique(AnnualPlan::class, 'year')
                    ->where(fn ($query) => $query->where('program_id', $plan->program_id))
                    ->ignore($plan->id),
            ],
        ], [
            'planManagerId.required' => __('annual_plans.select_the_manager_accountable_for_this_plan'),
            'planManagerId.exists' => __('annual_plans.the_selected_manager_is_no_longer_available'),
            'planYear.required' => __('annual_plans.select_the_fiscal_year_this_plan_covers'),
            'planYear.unique' => __('annual_plans.this_program_already_has_a_plan_for_that_fiscal_year'),
        ]);

        $plan->fill([
            'manager_id' => Gate::allows('reassign', $plan) ? (int) $validated['planManagerId'] : $plan->manager_id,
            'year' => (int) $validated['planYear'],
        ])->save();

        $this->showPlanModal = false;
        $this->resetValidation();

        // The whole page reads through plan(), targets() and the figures built
        // on them, all memoised earlier in this request.
        unset($this->plan, $this->targets, $this->reportStatusCounts, $this->summary, $this->yearFormOptions, $this->chain);

        Flux::toast(variant: 'success', text: __('annual_plans.annual_plan_updated'));
    }

    /**
     * Delete the plan and return to the matrix.
     *
     * Its observer removes the targets committed in the plan, and the reports
     * filed against those targets go with them; the confirmation names that
     * count before this runs.
     */
    public function deletePlan(): void
    {
        Gate::authorize('delete', $this->plan);

        $this->plan->delete();

        Flux::toast(variant: 'success', text: __('annual_plans.annual_plan_deleted'));

        $this->redirectRoute('annual-plans.index', navigate: true);
    }

    public function closePlanModal(): void
    {
        $this->showPlanModal = false;
        $this->resetValidation();
    }
};
?>

@php
    $plan = $this->plan;
    $summary = $this->summary;
    $resultGroups = $this->resultGroups;
    $periodMix = $this->periodMix;
    $linkedProjects = $this->linkedProjects;
    $recentReports = $this->recentReports;

    $reportSegments = [
        ['count' => $summary['reportsApproved'], 'class' => 'bg-status-good', 'label' => \App\Enums\ReportStatus::Approved->label()],
        ['count' => $summary['reportsReviewed'], 'class' => 'bg-status-warn', 'label' => \App\Enums\ReportStatus::Reviewed->label()],
        ['count' => $summary['reportsDraft'], 'class' => 'bg-secondary-medium dark:bg-ink-faint', 'label' => \App\Enums\ReportStatus::Draft->label()],
    ];

    $gaugeRadius = 46;
    $gaugeCircumference = 2 * M_PI * $gaugeRadius;
    $gaugeOffset = $gaugeCircumference * (1 - $summary['reportingShare'] / 100);
@endphp

<x-desk.record-sheet register="pages::annual-plans.index" :close-url="route('annual-plans.index')" :title="__('common.fy_year_program', ['year' => $plan->year, 'program' => $plan->program->name])" :subtitle="$plan->program->strategy?->name">
<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.annual_plans'), 'url' => route('annual-plans.index')],
            ['label' => __('common.fy_year', ['year' => $plan->year])],
        ]"
        :title="__('common.fy_year_program', ['year' => $plan->year, 'program' => $plan->program->name])"
        :subtitle="$plan->program->strategy?->name ?? __('common.strategy_not_assigned')"
    >
        <x-slot:meta>
            <a href="{{ route('programs.show', $plan->program) }}" wire:navigate class="font-medium hover:text-secondary-dark hover:underline dark:hover:text-ink">
                {{ $plan->program->name }}
            </a>
            @if ($plan->program->strategy !== null)
                <a href="{{ route('strategies.show', $plan->program->strategy) }}" wire:navigate class="hover:text-secondary-dark hover:underline dark:hover:text-ink">
                    {{ $plan->program->strategy->name }}
                </a>
            @endif
            <span>{{ trans_choice('common.count_target', $summary['targets'], ['count' => $summary['targets']]) }}</span>
            <span>{{ trans_choice('common.count_project', $summary['projects'], ['count' => $summary['projects']]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <x-ui.employee-chip :employee="$plan->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-line" />
                <div class="flex items-center gap-1.5">
                    @can('update', $plan)<x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/detail:size-10!" wire:click="openEditModal" :aria-label="__('annual_plans.edit_this_annual_plan')" data-test="edit-annual-plan-button" />@endcan
                    @can('delete', $plan)<x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/detail:size-10!" wire:click="$set('confirmingDelete', true)" :aria-label="__('annual_plans.delete_this_annual_plan')" data-test="delete-annual-plan-button" />@endcan
                </div>
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    @include('pages.annual-plans._show-cover')

    @include('pages.annual-plans._show-results')

    @include('pages.annual-plans._show-chain')

    {{-- Recent activity is capped to the Connections card's own natural height and scrolls the rest, so neither card stretches to fill space the other doesn't need. --}}
    <div
        class="grid gap-8 @4xl/detail:grid-cols-2 @4xl/detail:items-start"
        x-data="{ matchHeight: null }"
        x-init="
            const target = $refs.connectionsCard;
            const sync = () => { matchHeight = target.offsetHeight; };
            sync();
            new ResizeObserver(sync).observe(target);
        "
    >
        {{-- Connections: the program, strategy, manager and projects this plan ties together. --}}
        <x-ui.panel x-ref="connectionsCard" :title="__('annual_plans.connections')" :subtitle="__('annual_plans.connections_hint')">
            <ul class="divide-y divide-board-line">
                <li class="flex items-center gap-3 p-3.5">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.briefcase variant="micro" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.program') }}</span>
                        <a href="{{ route('programs.show', $plan->program) }}" wire:navigate class="block truncate text-sm font-semibold hover:underline">{{ $plan->program->name }}</a>
                    </span>
                </li>

                <li class="flex items-center gap-3 p-3.5">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.map variant="micro" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.strategy') }}</span>
                        @if ($plan->program->strategy === null)
                            <span class="block truncate text-sm font-semibold text-secondary-light">{{ __('common.strategy_not_assigned') }}</span>
                        @else
                            <a href="{{ route('strategies.show', $plan->program->strategy) }}" wire:navigate class="block truncate text-sm font-semibold hover:underline">{{ $plan->program->strategy->name }}</a>
                        @endif
                    </span>
                </li>

                <li class="flex items-center gap-3 p-3.5">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                        <flux:icon.user variant="micro" class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('annual_plans.assigned_manager') }}</span>
                        <x-ui.employee-chip :employee="$plan->manager" compact />
                    </span>
                </li>

                @if ($linkedProjects->isEmpty())
                    <li class="p-3.5">
                        <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.projects') }}</span>
                        <p class="mt-1 type-meta text-secondary-light">{{ __('annual_plans.no_projects_attributed_yet') }}</p>
                    </li>
                @else
                    @foreach ($linkedProjects as $project)
                        <li class="flex items-center gap-3 p-3.5" wire:key="connection-project-{{ $project->id }}">
                            <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink">
                                <flux:icon.map-pin variant="micro" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                @if ($loop->first)
                                    <span class="block type-meta text-secondary-medium dark:text-ink-muted">{{ __('common.projects') }}</span>
                                @endif
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="block truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <span class="block truncate type-meta text-secondary-medium dark:text-ink-muted">{{ $project->city?->name ?? __('common.unknown_city') }}</span>
                            </span>
                        </li>
                    @endforeach
                @endif
            </ul>
        </x-ui.panel>

        {{-- Recent activity: the latest reports landing against this plan. Height-locked to the Connections card via matchHeight; the list scrolls internally past that point. --}}
        <x-ui.panel
            :title="__('annual_plans.recent_activity')"
            :subtitle="__('annual_plans.recent_activity_hint')"
            class="flex flex-col overflow-hidden"
            x-bind:style="matchHeight ? { height: matchHeight + 'px' } : {}"
        >
            @if ($recentReports->isEmpty())
                <x-ui.empty-state icon="clock" :heading="__('annual_plans.no_activity_yet')" :body="__('annual_plans.reports_submitted_against_this_plan_will_appear_here')" />
            @else
                <ol class="min-h-0 flex-1 divide-y divide-board-line overflow-y-auto">
                    @foreach ($recentReports as $report)
                        @php
                            $indicator = $report->planIndicator->indicator;
                        @endphp
                        <li wire:key="activity-{{ $report->id }}">
                            <a
                                href="{{ route('reports.show', $report) }}" wire:navigate
                                class="flex items-start gap-3 p-3.5 transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-secondary-medium"
                            >
                                <span
                                    aria-hidden="true"
                                    @class([
                                        'mt-1.5 size-2 shrink-0 rounded-full',
                                        'bg-status-good' => $report->status === \App\Enums\ReportStatus::Approved,
                                        'bg-status-warn' => $report->status === \App\Enums\ReportStatus::Reviewed,
                                        'bg-secondary-medium dark:bg-ink-faint' => $report->status === \App\Enums\ReportStatus::Draft,
                                    ])
                                ></span>

                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="truncate text-sm font-semibold">{{ $indicator->name }}</span>
                                        <x-ui.enum-badge :case="$report->status" />
                                    </span>
                                    <span class="mt-0.5 block type-meta text-secondary-medium dark:text-ink-muted">
                                        {{ $report->planIndicator->project?->name ?? __('common.organisation_wide') }}
                                        · <span class="font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ number_format((float) $report->value, 2) }}</span>
                                    </span>
                                    <span class="mt-0.5 block type-meta text-secondary-light">{{ $report->created_at?->translatedFormat(__('formats.date')) }}</span>
                                </span>

                                <flux:icon.chevron-right variant="micro" class="mt-1 size-3.5 shrink-0 text-secondary-light rtl:-scale-x-100" aria-hidden="true" />
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>
    </div>

    <x-ui.modal
        wire:model="showPlanModal"
        size="md"
        :title="__('annual_plans.edit_annual_plan')"
        :description="$plan->program->name"
        submit="savePlan"
        close="closePlanModal"
        required-note
    >
        @if ($showPlanModal)
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <x-ui.form-select wire:model="planYear" :label="__('annual_plans.fiscal_year')" :placeholder="__('annual_plans.select_a_fiscal_year')" required autofocus>
                    @foreach ($this->yearFormOptions as $yearFormOption)
                        <option value="{{ $yearFormOption }}">{{ $yearFormOption }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-select wire:model="planManagerId" :label="__('annual_plans.assigned_manager')" :placeholder="__('annual_plans.select_a_manager')" required employees />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-annual-plan-button">{{ __('common.save_changes') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="confirmingDelete"
        :title="__('annual_plans.delete_this_annual_plan')"
        :message="$summary['targets'] > 0
            ? __('annual_plans.deleting_the_plan_also_removes_its_targets_and_their_reports', [
                'program' => $plan->program->name,
                'year' => $plan->year,
                'count' => $summary['targets'],
            ])
            : __('annual_plans.this_plan_has_no_targets_committed_in_it', [
                'program' => $plan->program->name,
                'year' => $plan->year,
            ])"
        confirm="deletePlan"
        :confirm-label="__('annual_plans.delete_annual_plan')"
        confirm-test="confirm-delete-annual-plan"
    />
</section>
</x-desk.record-sheet>
