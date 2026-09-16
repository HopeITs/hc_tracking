@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects */
@endphp

@foreach ($projects as $projectRow)
    <x-ui.panel :title="$projectRow->name" :subtitle="$projectRow->city?->name ?? __('common.unknown_city')" :count="$projectRow->milestones->count()" wire:key="milestone-project-{{ $projectRow->id }}">
        <x-slot:toolbar>
            <a href="{{ route('projects.show', $projectRow) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-secondary-soft">{{ __('milestones.open_project') }}</a>
        </x-slot:toolbar>

        <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
            @foreach ($projectRow->milestones as $milestone)
                @include('pages.milestones._tree-node', ['milestone' => $milestone, 'depth' => 0])
            @endforeach
        </div>
    </x-ui.panel>
@endforeach
