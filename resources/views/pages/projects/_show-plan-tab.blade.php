        <x-ui.panel :title="__('gantt.plan')" :subtitle="__('gantt.plan_subtitle')">
            @if ($this->project->status === \App\Enums\ProjectStatus::Draft)
                <x-ui.empty-state icon="calendar-days" :heading="__('projects.plan_after_approval')" data-test="plan-after-approval" />
            @elseif ($this->ganttPayload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_to_plan_body')" />
            @else
                <x-ui.gantt :payload="$this->ganttPayload" :scope="'project-'.$this->projectId" :editable="auth()->user()->can('update', $this->project)" wire:ignore wire:key="project-gantt-{{ $this->projectId }}" />
            @endif
        </x-ui.panel>
