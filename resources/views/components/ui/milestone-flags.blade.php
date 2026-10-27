@props(['milestone'])

@php
    /** @var \App\Models\Milestone $milestone */
@endphp

@if ($milestone->isMissed())
    <span {{ $attributes->class('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap border-status-warn/30 bg-status-warn-soft text-status-warn') }}>
        {{ __('milestones.missed') }}
    </span>
@elseif ($milestone->isOverdue())
    <span {{ $attributes->class('inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold whitespace-nowrap border-status-critical bg-status-critical-soft text-status-critical') }}>
        {{ __('milestones.overdue') }}
    </span>
@endif
