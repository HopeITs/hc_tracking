@props(['risk', 'fromProgram' => false])

{{--
    One framework risk: a level mark (likelihood × severity), the title in the
    current language, the two ratings as words and the mitigation on a second
    line. Edit / Remove go in the `actions` slot. Used by the results
    framework's program Risks block and the proposal logframe.
--}}
@php
    $score = $risk->heatScore();
    $level = match (true) {
        $score === null => null,
        $score >= 6 => 'high',
        $score >= 3 => 'medium',
        default => 'low',
    };
@endphp

<li {{ $attributes->class('flex items-start gap-3 py-3') }} data-test="risk-row-{{ $risk->id }}">
    <span
        @class([
            'mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-md border text-xs font-bold tabular-nums',
            'border-primary bg-primary text-white' => $level === 'high',
            'border-primary bg-white text-primary dark:bg-surface-1 dark:text-brand-text' => $level === 'medium',
            'border-secondary-soft bg-ground text-secondary-medium dark:border-line dark:text-ink-muted' => $level === 'low' || $level === null,
        ])
        @if ($level !== null) title="{{ __('results_framework.risk_level_'.$level) }}" @endif
        data-level="{{ $level ?? 'none' }}"
    >{{ $score ?? '–' }}</span>

    <div class="min-w-0 flex-1 space-y-1">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $risk->localizedName() }}</span>
            @if ($fromProgram)
                <span class="rounded-md border border-secondary-soft px-1.5 py-0.5 text-xs font-semibold text-secondary-medium dark:border-line dark:text-ink-muted">{{ __('results_framework.risk_from_program') }}</span>
            @endif
        </div>
        <div class="flex flex-wrap gap-1.5">
            {{-- Own badges, not x-ui.risk-rating: this row keeps to red + greys (high red, medium dark grey, low light grey). --}}
            @foreach (['likelihood' => $risk->likelihood, 'severity' => $risk->severity] as $ratingKind => $ratingValue)
                <span
                    @class([
                        'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
                        'border-primary text-primary dark:text-brand-text' => $ratingValue === 3,
                        'border-secondary-medium text-secondary-dark dark:border-ink-muted dark:text-ink' => $ratingValue === 2,
                        'border-secondary-soft text-secondary-medium dark:border-line dark:text-ink-muted' => $ratingValue !== 2 && $ratingValue !== 3,
                    ])
                    data-rating="{{ $ratingKind }}-{{ $ratingValue ?? 'none' }}"
                >{{ __('risks.'.$ratingKind) }}: {{ match ($ratingValue) { 3 => __('risks.rating_high'), 2 => __('risks.rating_medium'), 1 => __('risks.rating_low'), default => __('risks.not_rated') } }}</span>
            @endforeach
        </div>
        @if (filled($risk->solution))
            <p class="type-meta text-secondary-medium dark:text-ink-muted" dir="auto">{{ $risk->solution }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-1">{{ $actions }}</div>
    @endisset
</li>
