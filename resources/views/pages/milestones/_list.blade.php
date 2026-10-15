@php
    /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Project|\App\Models\Proposal> $projects */
@endphp

@foreach ($projects as $projectRow)
    @php $isProject = $projectRow instanceof \App\Models\Project; @endphp
    <x-ui.panel :title="$projectRow->name" :subtitle="$isProject ? ($projectRow->city?->name ?? __('common.unknown_city')) : __('milestones.proposal_plan')" :count="$projectRow->milestones->count()" wire:key="milestone-{{ $isProject ? 'project' : 'proposal' }}-{{ $projectRow->id }}">
        <x-slot:toolbar>
            <div class="flex shrink-0 items-center gap-1">
                @if ($isProject)
                    @can('create', [App\Models\Milestone::class, $projectRow->id])<x-ui.button tone="ghost" size="sm" icon="plus" class="size-9!" wire:click="openCreateModal(null, null, {{ $projectRow->id }})" data-test="milestone-list-add-{{ $projectRow->id }}" :aria-label="__('milestones.add_milestone_to_project', ['name' => $projectRow->name])" />@endcan
                    <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('projects.show', $projectRow)" wire:navigate :aria-label="__('common.view_name', ['name' => $projectRow->name])" />
                @else
                    @can('view', $projectRow)<x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('proposals.show', $projectRow)" wire:navigate :aria-label="__('common.view_name', ['name' => $projectRow->name])" />@endcan
                @endif
            </div>
        </x-slot:toolbar>

        <div class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($projectRow->milestones as $milestone)
                @include('pages.milestones._tree-node', ['milestone' => $milestone, 'depth' => 0])
            @endforeach
        </div>
    </x-ui.panel>
@endforeach
