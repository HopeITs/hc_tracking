<?php

use App\Models\PlanIndicator;
use App\Models\YearlyPlan;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.annual_plan')] class extends Component {
    public int $yearlyPlanId;

    public function mount(YearlyPlan $yearlyPlan): void
    {
        $this->yearlyPlanId = $yearlyPlan->id;
    }

    #[Computed]
    public function plan(): YearlyPlan
    {
        return YearlyPlan::query()
            ->select(['id', 'program_id', 'manager_id', 'year', 'updated_at'])
            ->with([
                'program:id,name,strategy_id',
                'program.strategy:id,name',
                'manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount('planIndicators')
            ->findOrFail($this->yearlyPlanId);
    }

    /**
     * The targets committed in this plan.
     *
     * Filtered on the morph map key 'yearly_plan', never a class name: the
     * column is VARCHAR(50) and the map is enforced application-wide.
     *
     * @return Collection<int, PlanIndicator>
     */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'yearly_plan')
            ->where('source_id', $this->yearlyPlanId)
            ->with([
                'indicator:id,name,result_id,measure',
                'indicator.result:id,statement,type',
                'project:id,name,city_id',
                'project.city:id,name_en,name_ar',
            ])
            ->withCount('reports')
            ->get();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.annual_plans'), 'url' => route('yearly-plans.index')],
            ['label' => __('common.fy_year', ['year' => $this->plan->year])],
        ]"
        :title="__('common.fy_year_program', ['year' => $this->plan->year, 'program' => $this->plan->program->name])"
        :subtitle="$this->plan->program->strategy?->name ?? __('common.strategy_not_assigned')"
    >
        <x-slot:meta>
            @if (Route::has('programs.show'))
                <a href="{{ route('programs.show', $this->plan->program) }}" wire:navigate class="font-medium hover:text-secondary-dark hover:underline dark:hover:text-white">
                    {{ $this->plan->program->name }}
                </a>
            @else
                <span class="font-medium">{{ $this->plan->program->name }}</span>
            @endif
            <span>{{ trans_choice('common.count_target', $this->plan->plan_indicators_count, ['count' => $this->plan->plan_indicators_count]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.employee-chip :employee="$this->plan->manager" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <x-ui.panel :title="__('yearly_plans.committed_targets')" :count="$this->targets->count()" :subtitle="__('yearly_plans.indicator_targets_this_plan_is_accountable_for')">
        <x-slot:toolbar>
            <a href="{{ route('targets.index', ['sourceType' => 'yearly_plan']) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-secondary-soft">
                {{ __('yearly_plans.all_annual_plan_targets') }}
            </a>
        </x-slot:toolbar>

        @if ($this->targets->isEmpty())
            <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('yearly_plans.indicator_targets_committed_in_this_plan_will_appear_here')" />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->targets as $target)
                    <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="plan-target-{{ $target->id }}">
                        <div class="min-w-0 @3xl/detail:me-auto">
                            <p class="truncate text-sm font-semibold">{{ $target->indicator->name }}</p>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $target->indicator->result->statement }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 @3xl/detail:justify-end">
                            <span class="text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($target->project === null)
                                    {{ __('common.organisation_wide') }}
                                @else
                                    {{ $target->project->name }} · {{ $target->project->city?->name ?? __('common.unknown_city') }}
                                @endif
                            </span>
                            <x-ui.enum-badge :case="$target->period" />
                            <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>
                            <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $target->reports_count }}
                            </span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
