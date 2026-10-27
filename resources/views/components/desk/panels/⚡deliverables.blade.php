<?php

use App\Enums\DeliveryState;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\Milestone;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Department deliverables: media and finance work requested by projects
 * that is not done or accepted yet. Finance work shows only to money
 * viewers; the Media Team sees media work only, Finance finance work only.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function rows(): Collection
    {
        $user = auth()->user();
        $kinds = match (true) {
            $user->hasRole('Media Team') => [TaskKind::MediaDeliverable],
            $user->hasRole('Financial') => [TaskKind::Financial],
            default => [TaskKind::MediaDeliverable, TaskKind::Financial],
        };

        if (! $this->canViewFundingPosition()) {
            $kinds = array_values(array_filter($kinds, fn (TaskKind $kind): bool => $kind !== TaskKind::Financial));
        }

        if ($kinds === []) {
            return new Collection;
        }

        return $this->visible(Task::query(), 'tasks')
            ->select(['id', 'slug', 'source_type', 'source_id', 'kind', 'delivery_state', 'title', 'status', 'due_date'])
            ->with(['source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id,name', 'proposal:id,name']])])
            ->whereNotNull('department_id')
            ->whereNull('parent_task_id')
            ->whereIn('kind', $kinds)
            ->where('status', '!=', TaskStatus::Done)
            ->where(fn ($query) => $query->whereNull('delivery_state')->orWhere('delivery_state', '!=', DeliveryState::Accepted))
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * task id => its project or proposal name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parents(): array
    {
        return $this->rows->mapWithKeys(fn (Task $task): array => [$task->id => $this->taskParentName($task)])->all();
    }
};
?>

@php
    $rows = $this->rows;
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="deliverables" register="department-tasks.index" :size="$size" :index="$index" :total="$total" :count="$rows->count()">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $task)
                <li wire:key="dl-{{ $task->id }}">
                    <div>
                        <strong><a href="{{ route('tasks.show', $task) }}" wire:navigate>{{ $task->title }}</a></strong>
                        <span class="dk-is">{{ $task->kind->label() }} · {{ $this->parents[$task->id] }}@if ($task->due_date !== null) · {{ $task->due_date->translatedFormat(__('desk.date_short')) }}@endif</span>
                    </div>
                    @if ($task->delivery_state !== null)
                        <span @class(['dk-pill', 'dk-warn' => $task->delivery_state === DeliveryState::Returned, 'dk-act' => $task->delivery_state === DeliveryState::Delivered])>{{ $task->delivery_state->label() }}</span>
                    @else
                        <span class="dk-pill">{{ $task->status->label() }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.deliverables.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
