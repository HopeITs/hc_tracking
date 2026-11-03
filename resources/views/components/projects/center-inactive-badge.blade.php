@props(['project' => null, 'inactive' => null])

{{-- "Center inactive" on a project whose physical HR center is no longer
     active (D-66, W-02). Text with an icon, never colour alone. Pass the
     project (its center is read from the in-memory HR lookup, so a list of
     projects costs no query per row; when HR cannot be read nothing is shown),
     or an already resolved `inactive` flag for rows that are plain arrays.
     Renders nothing for an active center, a hosted project or no center. --}}
@php
    $showBadge = $inactive;

    if ($showBadge === null && $project !== null && $project->center_id !== null) {
        try {
            $center = $project->center();
        } catch (\Illuminate\Database\QueryException) {
            $center = null;
        }

        $showBadge = $center !== null && ! $center->is_active;
    }
@endphp

@if ($showBadge === true)
    <span {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-bold whitespace-nowrap text-status-critical dark:bg-surface-2') }} data-test="center-inactive-badge">
        <flux:icon name="exclamation-triangle" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        <span>{{ __('projects.center_inactive') }}</span>
    </span>
@endif
