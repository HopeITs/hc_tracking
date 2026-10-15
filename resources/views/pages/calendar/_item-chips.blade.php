@php
    /**
     * @var \Carbon\CarbonImmutable $day
     * @var array<string, mixed> $calendar
     */
    $dateKey = $day->toDateString();
    $dayMilestones = $calendar['milestonesByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayTasks = $calendar['tasksByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayReports = $calendar['reportsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayProposals = $calendar['proposalsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayReviewMeetings = $calendar['reviewMeetingsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayTransfers = $calendar['transfersByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayDonorFollowUps = $calendar['donorFollowUpsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayDonorReports = $calendar['donorReportsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayProjectStarts = $calendar['projectStartsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $dayProjectEnds = $calendar['projectEndsByDay']->get($dateKey, new \Illuminate\Support\Collection);
    $canOpenAgreement = auth()->user()->can('funding-agreements.view');
@endphp

@foreach ($dayMilestones as $milestone)
    <div wire:key="calendar-milestone-{{ $milestone->id }}" class="group flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $markerTone($milestone) }}">
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['milestone'] }}"></span>
        <flux:icon.flag variant="micro" class="size-3 shrink-0" />
        <button type="button" wire:click.stop="openEditModal({{ $milestone->id }})" class="min-w-0 flex-1 cursor-pointer truncate text-start font-medium" data-test="calendar-milestone-{{ $milestone->id }}">
            {{ $milestone->name }}
        </button>
        @if ($milestone->children_count === 0 && $milestone->tasks_count === 0)
            @can('milestones.delete')
            <button type="button" wire:click.stop="confirmDelete({{ $milestone->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $milestone->name]) }}">
                <flux:icon.x-mark variant="micro" class="size-3" />
            </button>
            @endcan
        @endif
    </div>
@endforeach

@foreach ($dayTasks as $task)
    <div wire:key="calendar-task-{{ $task->id }}" data-task-id="{{ $task->id }}" class="group flex cursor-grab items-center gap-1 rounded px-1.5 py-0.5 text-[10px] active:cursor-grabbing {{ $taskTone($task->status) }}">
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['task'] }}"></span>
        <flux:icon.clipboard-document-check variant="micro" class="size-3 shrink-0" />
        <button type="button" wire:click.stop="openEditTaskModal({{ $task->id }})" class="min-w-0 flex-1 cursor-pointer truncate text-start font-medium" data-test="calendar-task-{{ $task->id }}">
            {{ $task->title }}
        </button>
        @can('delete', $task)
            <button type="button" wire:click.stop="confirmDeleteTask({{ $task->id }})" class="hidden shrink-0 cursor-pointer group-hover:block" aria-label="{{ __('common.delete_name', ['name' => $task->title]) }}">
                <flux:icon.x-mark variant="micro" class="size-3" />
            </button>
        @endcan
    </div>
@endforeach

@foreach ($dayReports as $report)
    <a
        href="{{ route('reports.show', $report) }}"
        wire:navigate
        wire:key="calendar-report-{{ $report->id }}"
        x-on:click.stop
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $reportTone($report->status) }}"
        data-test="calendar-report-{{ $report->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['report'] }}"></span>
        <flux:icon.document-text variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">
            {{ $report->planIndicator?->indicator?->name ?? __('calendar.report_deadline') }}
        </span>
    </a>
@endforeach

@foreach ($dayProposals as $proposal)
    <a
        href="{{ route('proposals.show', $proposal) }}"
        wire:navigate
        wire:key="calendar-proposal-{{ $proposal->id }}"
        x-on:click.stop
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $proposalTone($proposal) }}"
        data-test="calendar-proposal-{{ $proposal->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['proposal'] }}"></span>
        <flux:icon.paper-airplane variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">{{ $proposal->name }}</span>
    </a>
@endforeach

@foreach ($dayReviewMeetings as $meeting)
    <a
        href="{{ route('proposals.show', $meeting->proposal) }}"
        wire:navigate
        wire:key="calendar-review-meeting-{{ $meeting->id }}"
        x-on:click.stop
        class="flex items-center gap-1 rounded bg-board-head px-1.5 py-0.5 text-[10px] text-secondary-dark dark:bg-surface-2 dark:text-ink"
        title="{{ __('calendar.review_meeting') }} · {{ $meeting->proposal->name }}"
        data-test="calendar-review-meeting-{{ $meeting->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['proposal'] }}"></span>
        <flux:icon.users variant="micro" class="size-3 shrink-0" />
        <span class="shrink-0 tabular-nums" dir="ltr">{{ $meeting->meeting_at->format('H:i') }}</span>
        <span class="min-w-0 flex-1 truncate text-start font-medium"><span class="sr-only">{{ __('calendar.review_meeting') }}: </span>{{ $meeting->proposal->name }}</span>
    </a>
@endforeach

@foreach ($dayTransfers as $transfer)
    <a
        @if ($canOpenAgreement) href="{{ route('funding-agreements.show', $transfer->donorProposal) }}" wire:navigate @endif
        wire:key="calendar-transfer-{{ $transfer->id }}"
        x-on:click.stop
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $transferTone($transfer->ar_status) }}"
        data-test="calendar-transfer-{{ $transfer->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['transfer'] }}"></span>
        <flux:icon.banknotes variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">
            {{ $transfer->donorProposal?->donor?->name }} · {{ number_format((float) $transfer->payment_amount, 2) }} {{ $transfer->amount_transfered_currency->value }}
        </span>
    </a>
@endforeach

@foreach ($dayDonorFollowUps as $donor)
    <a
        href="{{ route('donors.show', $donor) }}"
        wire:navigate
        wire:key="calendar-donor-followup-{{ $donor->id }}"
        x-on:click.stop
        title="{{ $donor->next_step }}"
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $donorFollowUpTone($donor) }}"
        data-test="calendar-donor-followup-{{ $donor->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['donor'] }}"></span>
        <flux:icon.phone variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">{{ $donor->name }}</span>
    </a>
@endforeach

@foreach ($dayDonorReports as $donorReport)
    <a
        href="{{ route('funding-agreements.show', $donorReport->donorProposal) }}"
        wire:navigate
        wire:key="calendar-donor-report-{{ $donorReport->id }}"
        x-on:click.stop
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $donorReportTone($donorReport) }}"
        data-test="calendar-donor-report-{{ $donorReport->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['donor'] }}"></span>
        <flux:icon.document-check variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">{{ $donorReport->title }}</span>
    </a>
@endforeach

@foreach ($dayProjectStarts as $project)
    <a
        href="{{ route('projects.show', $project) }}"
        wire:navigate
        wire:key="calendar-project-start-{{ $project->id }}"
        x-on:click.stop
        title="{{ __('calendar.project_starts') }}"
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $projectTone }}"
        data-test="calendar-project-start-{{ $project->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['project'] }}"></span>
        <flux:icon.rocket-launch variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">{{ $project->name }}</span>
    </a>
@endforeach

@foreach ($dayProjectEnds as $project)
    <a
        href="{{ route('projects.show', $project) }}"
        wire:navigate
        wire:key="calendar-project-end-{{ $project->id }}"
        x-on:click.stop
        title="{{ __('calendar.project_ends') }}"
        class="flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] {{ $projectTone }}"
        data-test="calendar-project-end-{{ $project->id }}"
    >
        <span class="size-1.5 shrink-0 rounded-full {{ $typeDotClasses['project'] }}"></span>
        <flux:icon.check-circle variant="micro" class="size-3 shrink-0" />
        <span class="min-w-0 flex-1 truncate text-start font-medium">{{ $project->name }}</span>
    </a>
@endforeach
