@props(['options', 'disabled' => false, 'issues' => []])

{{--
    The "Activity in framework" field of every milestone form (T12): the
    activities the milestone's project may deliver, each named with the
    framework it sits in. Options come from PicksMilestoneActivity; the link
    is written by LinkMilestoneToActivity. `issues` are the translation keys
    of Milestone::activityLinkIssues(), shown as a warning under the field.
--}}
<div class="min-w-0">
    <x-ui.form-select
        {{ $attributes }}
        :label="__('milestones.activity_in_framework')"
        :help="__('milestones.activity_in_framework_help')"
        :search-label="__('milestones.search_activities')"
        :disabled="$disabled"
    >
        <option value="">{{ __('milestones.no_activity') }}</option>
        @foreach ($options as $activityOption)
            <option value="{{ $activityOption->id }}">{{ Str::limit($activityOption->statement, 70) }} · {{ $activityOption->proposal?->name ?? __('milestones.program_framework') }}</option>
        @endforeach
    </x-ui.form-select>

    @foreach ($issues as $issue)
        <p class="mt-1.5 flex items-start gap-1.5 type-meta text-status-warn" data-test="milestone-activity-issue">
            <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ __($issue) }}</span>
        </p>
    @endforeach
</div>
