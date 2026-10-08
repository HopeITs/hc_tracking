@use('App\Enums\AssignmentStatus')
    @if ($showAssignmentBanner)
        @php
            $assignmentTone = match ($ownerAssignment->status) {
                AssignmentStatus::Rejected => 'border-status-critical bg-status-critical-soft',
                AssignmentStatus::Pending => 'border-status-warn bg-status-warn-soft',
                default => 'border-status-good bg-status-good-soft',
            };
        @endphp
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm {{ $assignmentTone }}" data-test="owner-assignment-banner" data-status="{{ $ownerAssignment->status->value }}">
            <div class="min-w-0">
                <p class="font-semibold text-secondary-dark dark:text-ink">
                    @if ($ownerAssignment->status === AssignmentStatus::Pending)
                        {{ $ownerAssignment->isFor($assignmentViewer) ? __('projects.assignment_pending_you', ['assigner' => $ownerAssignment->assigner?->name ?? __('notifications.system')]) : __('projects.assignment_pending', ['name' => $this->project->user?->name]) }}
                        <span class="font-normal text-secondary-medium dark:text-ink-muted">· {{ $ownerAssignment->seen_at === null ? __('projects.assignment_not_seen') : __('projects.assignment_seen', ['when' => $ownerAssignment->seen_at->diffForHumans()]) }}</span>
                    @elseif ($ownerAssignment->status === AssignmentStatus::Rejected)
                        {{ __('projects.assignment_rejected', ['name' => $this->project->user?->name]) }}
                    @else
                        {{ __('projects.assignment_acknowledged', ['name' => $this->project->user?->name]) }}
                    @endif
                </p>
                @if ($ownerAssignment->response_note !== null)
                    <p class="mt-1 text-secondary-dark dark:text-ink">“{{ $ownerAssignment->response_note }}”</p>
                @endif
                @if ($ownerAssignment->status === AssignmentStatus::Rejected)
                    <p class="mt-1 text-secondary-medium dark:text-ink-muted">{{ __('projects.assignment_rejected_hint') }}</p>
                @endif
            </div>

            <div class="flex flex-wrap gap-2">
                @can('respond', $ownerAssignment)
                    <x-ui.button type="button" tone="primary" wire:click="openAssignmentModal('acknowledge')" data-test="assignment-acknowledge-button">{{ __('projects.assignment_acknowledge') }}</x-ui.button>
                    <x-ui.button type="button" wire:click="openAssignmentModal('reject')" data-test="assignment-reject-button">{{ __('projects.assignment_reject') }}</x-ui.button>
                @endcan
            </div>
        </div>
    @endif
