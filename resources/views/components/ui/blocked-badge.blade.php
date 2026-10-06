@props(['project'])

{{-- "Blocked" where the edit button would be, for a project whose owner
     rejected being put in charge. The top level gets the same badge as a
     button that opens the unblock dialog. Needs ownerAssignment loaded. --}}
@if ($project->isOwnerRejected())
    @php
        $badgeClasses = 'inline-flex h-8 shrink-0 items-center gap-1 rounded-full bg-status-critical-soft px-2.5 text-[11px] font-bold tracking-wide whitespace-nowrap text-status-critical uppercase';
    @endphp

    @can('unblock', $project->ownerAssignment)
        <button
            type="button"
            wire:click="openUnblockModal({{ $project->id }})"
            class="{{ $badgeClasses }} touch-manipulation cursor-pointer transition-colors hover:brightness-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            title="{{ __('projects.unblock_hint') }}"
            aria-label="{{ __('projects.unblock_name', ['name' => $project->name]) }}"
            data-test="blocked-badge-unblock"
        >
            <flux:icon name="lock-closed" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
            {{ __('projects.blocked') }}
        </button>
    @else
        <span class="{{ $badgeClasses }}" title="{{ __('projects.blocked_hint') }}" data-test="blocked-badge">
            <flux:icon name="lock-closed" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
            {{ __('projects.blocked') }}
        </span>
    @endcan
@endif
