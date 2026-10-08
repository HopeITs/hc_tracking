@use('App\Models\PlanIndicator')
        <x-ui.panel :title="__('projects.targets_naming_this_project')" :count="$this->targets->count()" :subtitle="__('projects.targets_linked_by_indicator_result_source_and_period')">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('projects.indicator_targets_that_name_this_project_will_appear_here')" />
            @else
                @php
                    $viaAnnualPlan = $this->targets->where('source_type', 'annual_plan')->count();
                    $viaProposal = $this->targets->count() - $viaAnnualPlan;
                    $reportsFiled = $this->targets->sum('reports_count');
                @endphp
                <div class="grid gap-3 border-b border-secondary-soft p-3.5 @lg/detail:grid-cols-4 dark:border-line">
                    <x-ui.stat-tile :label="__('projects.stat_indicators_covered')" :value="$this->targetedIndicatorCount" icon="chart-bar" />
                    <x-ui.stat-tile :label="__('projects.stat_committed_via_annual_plans')" :value="$viaAnnualPlan" icon="calendar-days" />
                    <x-ui.stat-tile :label="__('projects.stat_committed_via_proposals')" :value="$viaProposal" icon="document-text" />
                    <x-ui.stat-tile :label="__('projects.stat_reports_filed')" :value="$reportsFiled" icon="document-check" :tone="$reportsFiled > 0 ? 'good' : 'warn'" />
                </div>

                <div class="p-3.5 @3xl/detail:p-5">
                    <x-ui.target-lineage :groups="$this->targetsByResult" :format="fn (PlanIndicator $target): string => $this->formatTargetValue($target)" />
                </div>
            @endif
        </x-ui.panel>
