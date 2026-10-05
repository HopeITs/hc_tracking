@props(['project'])

{{-- "Rejected" beside the owner of a project whose owner rejected being put in
     charge. Needs the project's ownerAssignment loaded (the directory does). --}}
@if ($project->isOwnerRejected())
    <span {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-bold tracking-wide whitespace-nowrap text-status-critical uppercase') }} data-test="owner-rejected-badge">
        <flux:icon name="x-circle" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        {{ \App\Enums\AssignmentStatus::Rejected->label() }}
    </span>
@endif
