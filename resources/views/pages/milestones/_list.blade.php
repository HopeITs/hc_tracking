@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project> $projects */
@endphp

@foreach ($projects as $projectRow)
    <x-ui.panel :title="$projectRow->name" :subtitle="$projectRow->city?->name ?? __('common.unknown_city')" :count="$projectRow->milestones->count()" wire:key="milestone-project-{{ $projectRow->id }}">
        <x-slot:toolbar>
            <div class="flex shrink-0 items-center gap-1">
                @can('create', [App\Models\Milestone::class, $projectRow->id])<x-ui.button tone="ghost" size="sm" icon="plus" class="size-9!" wire:click="openCreateModal(null, null, {{ $projectRow->id }})" data-test="milestone-list-add-{{ $projectRow->id }}" :aria-label="__('milestones.add_milestone_to_project', ['name' => $projectRow->name])" />@endcan
                <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('projects.show', $projectRow)" wire:navigate :aria-label="__('common.view_name', ['name' => $projectRow->name])" />
            </div>
        </x-slot:toolbar>

        <div class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($projectRow->milestones as $milestone)
                @include('pages.milestones._tree-node', ['milestone' => $milestone, 'depth' => 0])
            @endforeach
        </div>
    </x-ui.panel>
@endforeach
