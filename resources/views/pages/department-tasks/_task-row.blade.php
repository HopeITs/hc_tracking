{{--
    One department task on the Department Tasks page. Expects $task (with
    taskAssignees.employee and a money-free source loaded), $proposal (the
    plan it belongs to, or null) and the page's tone strings.
--}}
@php
    $assignees = $task->taskAssignees->pluck('employee')->filter()->values();
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $isOverdue = ! $isDone && $task->due_date !== null && $task->due_date->lt(today());
    $departmentName = \App\Models\HrDepartment::nameOf((int) $task->department_id);
    $isDeliverable = $task->kind === \App\Enums\TaskKind::MediaDeliverable;
    $state = $task->delivery_state;
    $canDeliver = $isDeliverable && in_array($state, [\App\Enums\DeliveryState::Requested, \App\Enums\DeliveryState::Returned], true) && auth()->user()->can('deliver', $task);
    $canReview = $isDeliverable && $state === \App\Enums\DeliveryState::Delivered && auth()->user()->can('review', $task);
@endphp

<li class="flex flex-col gap-3 py-4 @3xl/directory:flex-row @3xl/directory:items-center @3xl/directory:gap-6" wire:key="department-task-{{ $task->id }}" data-department-task="{{ $task->id }}">
    <div class="min-w-0 flex-1">
        <p class="flex min-w-0 flex-wrap items-center gap-2">
            <span @class(['truncate text-sm font-semibold', $mutedText.' line-through decoration-secondary-light' => $isDone, $inkText => ! $isDone]) dir="auto">{{ $task->title }}</span>
            <span class="inline-flex shrink-0 items-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold {{ $mutedText }}">{{ $task->kind->label() }}</span>
            @if ($state !== null)
                <x-ui.enum-badge :case="$state" />
            @endif
        </p>

        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs {{ $mutedText }}">
            @if ($departmentName === '')
                <span class="inline-flex items-center gap-1 font-semibold text-status-warn" data-department-unavailable>
                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />{{ __('department_tasks.department_unavailable') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1" dir="auto"><flux:icon.building-office-2 variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />{{ $departmentName }}</span>
            @endif

            @if ($proposal !== null)
                <span aria-hidden="true">·</span>
                @if ($this->openableProposals[$proposal->id] ?? false)
                    <a href="{{ route('proposals.show', $proposal) }}?tab=plan" wire:navigate class="truncate underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" dir="auto" data-proposal-link>{{ $proposal->name }}</a>
                @else
                    <span class="truncate" dir="auto" data-proposal-name>{{ $proposal->name }}</span>
                @endif
            @endif

            <span aria-hidden="true">·</span>
            @if ($assignees->isEmpty())
                <span @class(['font-semibold text-status-warn' => ! $isDone && $task->due_date !== null && $task->due_date->lte(today()->addDays(7))]) data-unassigned>{{ __('department_tasks.no_person_yet') }}</span>
            @else
                <span dir="auto">{{ $assignees->map->name->implode('، ') }}</span>
            @endif
        </p>

        @if ($state === \App\Enums\DeliveryState::Returned && $task->returned_reason !== null)
            <p class="mt-1.5 text-xs {{ $inkText }}" data-returned-reason>
                <span class="font-semibold">{{ __('department_tasks.returned_because') }}</span> <span dir="auto">{{ $task->returned_reason }}</span>
            </p>
        @endif
    </div>

    <div class="flex shrink-0 flex-wrap items-center gap-2">
        <span class="text-xs tabular-nums">
            @if ($task->due_date === null)
                <span class="{{ $mutedText }}">{{ __('proposals.no_due_date') }}</span>
            @else
                <time datetime="{{ $task->due_date->toDateString() }}" @class(['font-bold text-status-critical' => $isOverdue, $inkText => ! $isOverdue])>{{ $task->due_date->translatedFormat(__('formats.date')) }}</time>
                @if ($isOverdue)
                    <span class="ms-1 text-[10px] font-bold tracking-wide text-status-critical uppercase">{{ __('proposals.overdue') }}</span>
                @endif
            @endif
        </span>

        @if ($canDeliver)
            <x-ui.button size="sm" tone="primary" icon="arrow-up-tray" wire:click="openDelivery({{ $task->id }})" wire:loading.attr="disabled" wire:target="openDelivery({{ $task->id }})" data-test="deliver-{{ $task->id }}">{{ __('department_tasks.deliver') }}</x-ui.button>
        @endif
        @if ($canReview)
            <x-ui.button size="sm" icon="check" wire:click="confirmAccept({{ $task->id }})" data-test="accept-{{ $task->id }}">{{ __('department_tasks.accept') }}</x-ui.button>
            <x-ui.button size="sm" icon="arrow-uturn-left" wire:click="confirmReturn({{ $task->id }})" data-test="return-{{ $task->id }}">{{ __('department_tasks.return') }}</x-ui.button>
        @endif
        <x-ui.button tone="ghost" size="sm" icon="eye" :href="route('tasks.show', $task)" wire:navigate :aria-label="__('common.view_name', ['name' => $task->title])" />
    </div>
</li>
