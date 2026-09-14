@props(['case' => null])

@php
    $tone = match (true) {
        $case === null => 'none',
        $case instanceof \App\Enums\TaskStatus => match ($case) {
            \App\Enums\TaskStatus::Done => 'solid',
            \App\Enums\TaskStatus::InProgress, \App\Enums\TaskStatus::UnderReview => 'solid',
            \App\Enums\TaskStatus::Blocked => 'accent',
            \App\Enums\TaskStatus::Todo => 'outline',
        },
        $case instanceof \App\Enums\MilestoneStatus => match ($case) {
            \App\Enums\MilestoneStatus::Reached => 'solid',
            \App\Enums\MilestoneStatus::Missed, \App\Enums\MilestoneStatus::Cancelled => 'accent',
            \App\Enums\MilestoneStatus::Pending => 'outline',
        },
        $case instanceof \App\Enums\ProposalStatus => match ($case) {
            \App\Enums\ProposalStatus::Approved,
            \App\Enums\ProposalStatus::Active,
            \App\Enums\ProposalStatus::Completed => 'solid',
            \App\Enums\ProposalStatus::Rejected => 'accent',
            default => 'outline',
        },
        $case instanceof \App\Enums\ProjectStatus => match ($case) {
            \App\Enums\ProjectStatus::Active => 'solid',
            default => 'outline',
        },
        $case instanceof \App\Enums\ArStatus => match ($case) {
            \App\Enums\ArStatus::Received => 'solid',
            \App\Enums\ArStatus::Failed => 'accent',
            \App\Enums\ArStatus::Pending => 'outline',
        },
        $case instanceof \App\Enums\TaskPriority => match ($case) {
            \App\Enums\TaskPriority::High => 'accent',
            \App\Enums\TaskPriority::Medium => 'solid',
            \App\Enums\TaskPriority::Low => 'outline',
        },
        $case instanceof \App\Enums\ResultType => match ($case) {
            \App\Enums\ResultType::Impact => 'solid',
            default => 'outline',
        },
        $case instanceof \App\Enums\DonorType => 'outline',
        $case instanceof \App\Enums\RagStatus => match ($case) {
            \App\Enums\RagStatus::Green => 'solid',
            \App\Enums\RagStatus::Amber => 'outline',
            \App\Enums\RagStatus::Red => 'accent',
        },
        $case instanceof \App\Enums\Period => 'outline',
        $case instanceof \App\Enums\ReportStatus => match ($case) {
            \App\Enums\ReportStatus::Approved => 'solid',
            \App\Enums\ReportStatus::Draft, \App\Enums\ReportStatus::Reviewed => 'outline',
        },
        default => 'outline',
    };

    $toneClasses = match ($tone) {
        'accent' => 'border-primary bg-primary text-white',
        'solid' => 'border-secondary-dark bg-secondary-dark text-white dark:border-secondary-medium dark:bg-secondary-medium',
        'outline' => 'border-secondary-light text-secondary-medium dark:text-secondary-soft',
        'none' => '',
    };
@endphp

@if ($case === null)
    <span {{ $attributes->class('text-secondary-light') }} aria-label="{{ __('ui.not_set') }}">—</span>
@else
    <span {{ $attributes->class('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap '.$toneClasses) }}>
        {{ $case->label() }}
    </span>
@endif
