{{--
    One project number. An internal number belongs to a proposal and carries an amber chip while it is
    still a migration placeholder (App\Models\Proposal::isPlaceholderNumber, so the TEMP- prefix lives
    in one place). An external number belongs to a funding agreement and may be empty. The number sits
    in <bdi dir="ltr"> so "HC-2026-001" never flips to "001-2026-HC" on an Arabic page.
--}}
@props([
    'number' => null,
    'kind' => 'internal',
    'label' => false,
])

@php
    $isInternal = $kind === 'internal';
    $isPlaceholder = $isInternal && \App\Models\Proposal::isPlaceholderNumber($number);
@endphp

<span {{ $attributes->class('inline-flex flex-wrap items-center gap-x-1.5 gap-y-1') }} data-project-number="{{ $kind }}">
    @if (filled($number))
        @if ($label)
            <span class="text-secondary-medium dark:text-ink-muted">{{ $isInternal ? __('common.internal_project_number') : __('common.external_project_number') }}</span>
        @endif
        <bdi dir="ltr" class="font-semibold tabular-nums">{{ $number }}</bdi>
        @if ($isPlaceholder)
            <span class="inline-flex items-center rounded-full bg-status-warn-soft px-2 py-0.5 text-[11px] font-semibold text-status-warn" data-placeholder-chip="{{ $number }}">{{ __('common.placeholder_number') }}</span>
        @endif
    @elseif (! $isInternal)
        <span class="text-secondary-medium dark:text-ink-muted">{{ __('common.no_external_project_number') }}</span>
    @endif
</span>
