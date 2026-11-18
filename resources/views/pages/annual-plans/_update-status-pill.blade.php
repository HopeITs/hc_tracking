{{-- The status of a monthly update in one word or two (or "Not sent yet" / "Late"). --}}
@php
    $pillStatus = $update?->status;
    [$pillTone, $pillText] = match (true) {
        $pillStatus === null || $pillStatus === \App\Enums\PlanUpdateStatus::Draft => [($late ?? false) ? 'dk-bad' : '', ($late ?? false) ? __('annual_plans.up.late') : __('annual_plans.up.not_sent')],
        $pillStatus === \App\Enums\PlanUpdateStatus::Sent => ['dk-warn', $update->returned_to === 'officer' ? __('annual_plans.up.status_back_to_officer') : __('annual_plans.up.status_with_officer')],
        $pillStatus === \App\Enums\PlanUpdateStatus::OfficerApproved => ['dk-act', __('annual_plans.up.status_with_meal')],
        $pillStatus === \App\Enums\PlanUpdateStatus::Reviewed => ['dk-act', __('annual_plans.up.status_with_pm')],
        $pillStatus === \App\Enums\PlanUpdateStatus::Seen => ['dk-good', __('annual_plans.up.status_complete')],
        $pillStatus === \App\Enums\PlanUpdateStatus::Returned => ['dk-bad', __('annual_plans.up.status_returned')],
    };
@endphp
<span class="dk-pill {{ $pillTone }}" data-update-status="{{ $pillStatus?->value ?? 'none' }}">{{ $pillText }}</span>
