{{--
    "Projects & places" section of proposals/show (stage 3b): each funded
    project as a fold with its tasks, linking another project, then the
    places as one fold. Buttons open the existing project page, unlink
    confirmation, centres dialog and edit form; nothing new is written here.
    Included by _show-more.blade.php.
--}}
@use('App\Enums\TaskStatus')
@use('App\Models\Task')
@php
    $grantEnd = $this->proposal->effectiveDateEnd() ?? $this->proposal->planned_date_end;
    $mayUpdate = auth()->user()?->can('update', $this->proposal) === true;
    $today = today();
    $taskStatusClass = [
        TaskStatus::Done->value => 'pp-st-done',
        TaskStatus::InProgress->value => 'pp-st-progress',
        TaskStatus::UnderReview->value => 'pp-st-review',
        TaskStatus::Todo->value => '',
    ];
    $missingCentre = collect($this->locationRows)->contains('status', 'missing_center');
@endphp

<div class="pp-lbl">{{ __('proposals.sections.funded_projects') }}</div>

@forelse ($this->projects as $project)
    @php
        $projectTasks = $this->tasks->filter(fn (Task $task): bool => $task->source_type === 'project' && $task->source_id === $project->id)->values();
        $projectDone = $projectTasks->where('status', TaskStatus::Done)->count();
        $projectLate = $projectTasks->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt($today))->count();
        $endsAfterGrant = $grantEnd !== null && $project->end_date !== null && $project->end_date->gt($grantEnd);
    @endphp
    <details class="pp-sub" wire:ignore.self wire:key="sec-project-{{ $project->id }}" data-project="{{ $project->id }}">
        <summary>
            <span class="pp-grow" dir="auto">{{ $project->name }}</span>
            <span class="pp-small">{{ __('proposals.sections.tasks_done', ['done' => $projectDone, 'total' => $projectTasks->count()]) }}</span>
            @if ($projectLate > 0)
                <span class="pp-pill pp-pill-red">{{ trans_choice('proposals.sections.flag_late', $projectLate, ['count' => $projectLate]) }}</span>
            @endif
            @if ($endsAfterGrant)
                <span class="pp-pill pp-pill-red" data-outruns-grant>{{ __('proposals.sections.ends_after_grant') }}</span>
            @endif
            <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="pp-sub-b">
            <div class="pp-small" dir="auto">
                {{ $project->city?->name ?? __('common.unknown_city') }}
                @if ($project->start_date !== null || $project->end_date !== null)
                    · <span class="tabular-nums">{{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }} – {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                @endif
            </div>

            @forelse ($projectTasks as $task)
                @include('pages.proposals._sec-task-row', ['task' => $task, 'taskStatusClass' => $taskStatusClass, 'today' => $today, 'editable' => false])
            @empty
                <span class="pp-small">{{ __('proposals.sections.no_tasks') }}</span>
            @endforelse

            <div class="pp-row-acts">
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="pp-btn pp-btn-sm" data-test="open-project-{{ $project->id }}">{{ __('proposals.sections.open_project') }}</a>
                @if ($mayUpdate)
                    <button type="button" class="pp-btn pp-btn-sm" wire:click="confirmUnlinkProject({{ $project->id }})" aria-label="{{ __('proposals.unlink_project_name', ['name' => $project->name]) }}" data-test="unlink-project-{{ $project->id }}">{{ __('proposals.sections.unlink') }}</button>
                @endif
            </div>
        </div>
    </details>
@empty
    <div class="pp-empty">{{ __('proposals.sections.no_projects') }}</div>
@endforelse

@if ($mayUpdate)
    <form wire:submit="linkProject" class="pp-link-form" data-link-project>
        <label class="sr-only" for="sec-project-to-link">{{ __('proposals.fund_another_project') }}</label>
        <select id="sec-project-to-link" wire:model="projectToLink" class="pp-select" @disabled($this->linkableProjects->isEmpty())>
            <option value="">{{ __('proposals.select_a_project') }}</option>
            @foreach ($this->linkableProjects as $linkable)
                <option value="{{ $linkable->id }}" wire:key="link-project-{{ $linkable->id }}">{{ $linkable->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="pp-btn pp-btn-sm" @disabled($this->linkableProjects->isEmpty()) data-test="link-project">
            <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('proposals.sections.link_project') }}
        </button>
    </form>
    @error('projectToLink')
        <span class="pp-err">{{ $message }}</span>
    @enderror
@endif

<div class="pp-lbl pp-lbl-gap">{{ __('proposals.sections.places') }}</div>

<details class="pp-sub" wire:ignore.self data-test="locations-chapter">
    <summary>
        <span class="pp-grow">{{ trans_choice('proposals.sections.n_locations', count($this->locationRows), ['count' => count($this->locationRows)]) }}</span>
        <span class="pp-small" dir="auto">{{ collect($this->locationRows)->pluck('city')->unique()->implode(', ') }}</span>
        @if ($missingCentre)
            <span class="pp-pill pp-pill-red">{{ __('proposals.sections.missing_centre') }}</span>
        @endif
        <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="pp-sub-b">
        @forelse ($this->locationRows as $locationRow)
            @php
                $program = $locationRow['program'] ?? ($locationRow['programRequestName'] !== null ? __('proposals.location_program_pending', ['name' => $locationRow['programRequestName']]) : null);
            @endphp
            <div class="pp-lrow" wire:key="sec-location-{{ $locationRow['id'] }}" data-location-row="{{ $locationRow['id'] }}" data-location-status="{{ $locationRow['status'] }}">
                <span class="pp-lrow-s">
                    <b dir="auto">{{ $locationRow['city'] }}</b>
                    <span class="pp-small" dir="auto">
                        {{ $program ?? '—' }} · {{ $locationRow['responsible'] ?? __('common.unassigned') }} ·
                        @if ($locationRow['fieldWork'])
                            {{ __('proposals.location_field_work') }}
                        @elseif ($locationRow['status'] === 'missing_center')
                            <span class="pp-red-text">{{ __('proposals.sections.no_centre_yet') }}</span>
                        @else
                            {{ $locationRow['centers'] !== [] ? implode('، ', $locationRow['centers']) : '—' }}
                        @endif
                    </span>
                </span>
                <span class="pp-lrow-r">
                    <span @class(['pp-pill', 'pp-pill-red' => $locationRow['status'] !== 'complete']) data-location-status-word>{{ __('proposals.location_status_'.$locationRow['status']) }}</span>
                </span>
            </div>
        @empty
            <span class="pp-small" data-test="locations-chapter-empty">{{ __('proposals.locations_chapter_empty') }}</span>
        @endforelse

        <div class="pp-row-acts">
            @if ($this->canFillCenters)
                <button type="button" class="pp-btn pp-btn-sm" wire:click="openCenterFill" data-test="add-centers">
                    <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('proposals.add_centers') }}
                </button>
            @endif
            {{-- Same rule as "Edit details" in the head: after donor approval places change through a change request. --}}
            @if ($mayUpdate && ! $this->proposal->status->isApprovedByDonor())
                <button type="button" class="pp-btn pp-btn-sm" wire:click="openEditModal({{ $this->proposalId }})" data-test="edit-locations">{{ __('proposals.sections.edit_locations') }}</button>
            @endif
        </div>
    </div>
</details>
