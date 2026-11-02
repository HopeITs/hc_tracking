@props(['case' => null])

{{-- One tinted badge per StatusTone (W-28). A status enum declares its tone
     through HasStatusTone; enums that are not statuses (ResultType, DonorType,
     Period, IndicatorMeasure, ...) fall back to the neutral tone. --}}
@php
    $tone = $case instanceof \App\Enums\Concerns\HasStatusTone ? $case->tone() : \App\Enums\StatusTone::Neutral;
@endphp

@if ($case === null)
    <span {{ $attributes->class('text-secondary-light') }} aria-label="{{ __('ui.not_set') }}">—</span>
@else
    <span {{ $attributes->class('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap '.$tone->badgeClasses()) }} data-tone="{{ $tone->value }}">
        {{ $case->label() }}
    </span>
@endif
