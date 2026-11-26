@props(['task', 'pausedIds' => null, 'full' => false])

{{-- «Paused»: the task's project stands on a center HR has deactivated (D-66,
     W-02). Text with an icon, never colour alone; the due date and status are
     untouched. Pass `paused-ids` (ProposalLocation::pausedIds(), read once per render)
     so a list costs no query per row; `full` spells the consequence out. --}}
@if ($task->isPaused($pausedIds))
    <span {{ $attributes->class('inline-flex items-center gap-1 rounded-full border border-status-warn bg-status-warn-soft px-2 py-0.5 text-[11px] font-bold text-secondary-dark dark:text-ink') }} title="{{ __('tasks.paused_center_inactive') }}" data-test="task-paused-chip">
        <flux:icon name="pause-circle" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
        {{ $full ? __('tasks.paused_center_inactive') : __('tasks.paused') }}
    </span>
@endif
