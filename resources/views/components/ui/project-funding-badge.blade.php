@props(['project'])

{{-- Where a project stands on its way to funding, as text with an icon (never
     colour alone): awaiting the owner's acknowledgement, awaiting the donor's
     decision, or funded and waiting for MEAL's report template. Needs
     `proposals:id,status` and `ownerAssignment` loaded (fundingState() loads
     them when a page forgot). --}}
@php
    $fundingState = $project->fundingState();
    $fundingBadge = match ($fundingState) {
        'awaiting_owner' => ['icon' => 'clock', 'classes' => 'bg-status-warn-soft text-status-warn'],
        'awaiting_funding' => ['icon' => 'banknotes', 'classes' => 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink'],
        'funded_awaiting_template' => ['icon' => 'document-check', 'classes' => 'bg-status-good-soft text-status-good'],
        default => null,
    };
@endphp

@if ($fundingBadge !== null)
    <span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold whitespace-nowrap', $fundingBadge['classes']]) }} data-test="funding-badge-{{ $fundingState }}">
        <flux:icon :name="$fundingBadge['icon']" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        {{ match ($fundingState) {
            'awaiting_owner' => __('projects.awaiting_owner_acknowledgement'),
            'awaiting_funding' => __('projects.awaiting_funding'),
            default => __('projects.funded_awaiting_template'),
        } }}
    </span>
@endif
