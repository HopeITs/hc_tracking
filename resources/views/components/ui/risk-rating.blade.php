@props(['kind', 'value' => null])

{{-- A risk's likelihood or severity as a word (never colour alone): "Likelihood: High". Tones follow the shared StatusTone vocabulary. --}}
@php
    use App\Enums\StatusTone;

    $tone = match ($value) {
        3 => StatusTone::Critical,
        2 => StatusTone::Warn,
        1 => StatusTone::Neutral,
        default => StatusTone::Neutral,
    };
    $word = match ($value) {
        3 => __('risks.rating_high'),
        2 => __('risks.rating_medium'),
        1 => __('risks.rating_low'),
        default => __('risks.not_rated'),
    };
    $name = match ($kind) {
        'severity' => __('risks.severity'),
        default => __('risks.likelihood'),
    };
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap '.$tone->badgeClasses()) }} data-tone="{{ $tone->value }}">
    {{ $name }}: {{ $word }}
</span>
