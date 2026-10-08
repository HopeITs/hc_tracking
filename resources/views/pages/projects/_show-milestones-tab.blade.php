        <x-ui.panel :title="__('common.milestones')" :count="$this->project->milestones_count" :subtitle="__('projects.milestones_planned_vs_actual_by_sequence')">
            @if ($this->milestonesFlat->isEmpty())
                <x-ui.empty-state icon="flag" :heading="__('common.no_milestones_yet')" :body="__('projects.milestones_planned_for_this_project_will_appear_here')" />
            @else
                <x-ui.milestone-timeline :milestones="$this->milestonesFlat" :window="$this->milestoneWindow" />
            @endif
        </x-ui.panel>
