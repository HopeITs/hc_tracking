@php
    use App\Enums\TaskPriority;
    use App\Enums\TaskStatus;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Collection;

    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Task> $paginator */
    $paginator = $this->tasks;
    $today = CarbonImmutable::today();

    $groupLabel = function (string $key, Collection $groupTasks) use ($group): string {
        return match ($group) {
            'status' => TaskStatus::tryFrom($key)?->label() ?? $key,
            'priority' => TaskPriority::tryFrom($key)?->label() ?? $key,
            'assignee' => $key === '' ? __('common.not_assigned') : $groupTasks->first()->taskAssignees->pluck('employee')->filter()->map->name->implode(', '),
            'milestone' => $key === '' ? __('tasks.no_milestone') : $groupTasks->first()->sourceLabel(),
            default => '',
        };
    };

    $groupColor = function (string $key) use ($group): string {
        if ($group === 'status') {
            return match (TaskStatus::tryFrom($key)) {
                TaskStatus::Todo => 'bg-secondary-light',
                TaskStatus::InProgress => 'bg-status-active',
                TaskStatus::UnderReview => 'bg-status-warn',
                TaskStatus::Done => 'bg-status-good',
                default => 'bg-secondary-light',
            };
        }

        if ($group === 'priority') {
            return match (TaskPriority::tryFrom($key)) {
                TaskPriority::High => 'bg-primary',
                TaskPriority::Medium => 'bg-status-warn',
                TaskPriority::Low => 'bg-secondary-light',
                default => 'bg-secondary-light',
            };
        }

        return 'bg-secondary-light';
    };

    $priorityIconClasses = fn (TaskPriority $case): string => match ($case) {
        TaskPriority::High => 'text-primary',
        TaskPriority::Medium => 'text-status-warn',
        TaskPriority::Low => 'text-secondary-light',
    };

    $groups = match ($group) {
        'status' => $paginator->getCollection()->groupBy(fn ($task) => $task->status->value)->sortKeys(),
        'priority' => $paginator->getCollection()->groupBy(fn ($task) => $task->priority->value),
        'assignee' => $paginator->getCollection()->groupBy(fn ($task) => $task->taskAssignees->pluck('employee_id')->sort()->implode(',')),
        'milestone' => $paginator->getCollection()->groupBy(fn ($task) => $task->source_type === 'milestone' ? (string) $task->source_id : ''),
        default => new Collection(['' => $paginator->getCollection()]),
    };
@endphp

<div class="relative">
    {{-- Skeleton, shown only while a filter/sort/group change is in flight. --}}
    <div wire:loading wire:target="search,status,priority,assignee,sourceType,sourceId,sort,group" class="overflow-hidden rounded-xl border border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <div class="animate-pulse divide-y divide-secondary-soft p-3.5 motion-reduce:animate-none dark:divide-secondary-medium">
            @for ($i = 0; $i < 6; $i++)
                <div class="flex items-center gap-3 py-2.5">
                    <div class="size-5 shrink-0 rounded-full bg-secondary-soft dark:bg-secondary-medium"></div>
                    <div class="h-3 flex-1 rounded bg-secondary-soft dark:bg-secondary-medium"></div>
                    <div class="h-3 w-16 shrink-0 rounded bg-secondary-soft dark:bg-secondary-medium"></div>
                    <div class="h-3 w-12 shrink-0 rounded bg-secondary-soft dark:bg-secondary-medium"></div>
                    <div class="h-6 w-24 shrink-0 rounded-full bg-secondary-soft dark:bg-secondary-medium"></div>
                </div>
            @endfor
        </div>
    </div>

    <div wire:loading.remove wire:target="search,status,priority,assignee,sourceType,sourceId,sort,group" class="flex flex-col gap-4">
        <div class="overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:border-secondary-medium dark:bg-secondary-dark dark:shadow-none">
            <div class="border-b border-secondary-soft px-4 py-2.5 dark:border-secondary-medium">
                <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ trans_choice('tasks.count_matching_task', $paginator->total(), ['count' => $paginator->total()]) }}</p>
            </div>

            @foreach ($groups as $groupKey => $groupTasks)
                @continue($groupTasks->isEmpty())
                @php $groupKeyString = (string) $groupKey; @endphp

                @if ($group === 'none')
                    @include('pages.tasks._list-group', ['groupTasks' => $groupTasks, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'groupKeyString' => $groupKeyString])
                @else
                    <x-ui.group-header
                        :label="$groupLabel($groupKeyString, $groupTasks)"
                        :count="$groupTasks->count()"
                        :color-class="$groupColor($groupKeyString)"
                        :storage-key="'task-list-group-'.$group.'-'.$groupKeyString"
                        wire:key="task-group-{{ $group }}-{{ $groupKeyString }}"
                    >
                        @include('pages.tasks._list-group', ['groupTasks' => $groupTasks, 'today' => $today, 'priorityIconClasses' => $priorityIconClasses, 'groupKeyString' => $groupKeyString])
                    </x-ui.group-header>
                @endif
            @endforeach

            <div class="border-t border-secondary-soft px-4 py-3 dark:border-secondary-medium">
                <flux:pagination :paginator="$paginator" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#task-directory-heading" />
            </div>
        </div>
    </div>

    @include('pages.tasks._assignee-popover')
</div>
