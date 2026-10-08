        <x-ui.panel :title="__('gantt.plan')" :subtitle="__('gantt.plan_subtitle')">
            @if ($this->ganttPayload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_to_plan_body')" />
            @else
                <x-ui.gantt :payload="$this->ganttPayload" :scope="'project-'.$this->projectId" :editable="auth()->user()->can('update', $this->project)" wire:ignore wire:key="project-gantt-{{ $this->projectId }}" />
            @endif
        </x-ui.panel>
