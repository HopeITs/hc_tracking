{{--
    The Story's head (desk.js renderStory): back to the Desk, the path, the
    title with its one-sentence lede, the next-step card, the facts line and
    the project's own actions.
--}}
@php
    $story = $this->story;
    $next = $story['next'];
    $lead = $story['lead'];
    $health = $story['health'];
    $project = $this->project;
    $beneficiaries = $project->targetDemographicTotal() ?? $project->target_beneficiaries;
    $money = $this->canViewMoney() ? $this->fundingByCurrency->first() : null;
@endphp

<a class="dk-back" href="{{ route('dashboard') }}" wire:navigate data-story-back><x-desk.icon name="back" class="dk-flip" />{{ __('desk.your_desk') }}</a>

<div class="dk-crumb" data-test="project-crumb">
    <span>{{ $project->program->strategy?->name ?? __('common.strategy_not_assigned') }}</span>
    ›
    <a href="{{ route('programs.show', $project->program) }}" wire:navigate>{{ $project->program->name }}</a>
    @if ($this->centerSummary !== null)
        ›
        <span data-test="project-center">
            @if ($this->centerSummary['unavailable'])
                #{{ $this->centerSummary['id'] }} · {{ __('common.hr_unavailable') }}
            @else
                {{ $this->centerSummary['name'] }}
            @endif
            @if ($this->centerSummary['inactive'])
                <span class="dk-pill dk-bad">{{ __('projects.center_inactive') }}</span>
            @endif
        </span>
    @endif
    · {{ $project->city?->name ?? __('common.unknown_city') }}
    <x-projects.sector-chips :sectors="$project->sectors" class="ms-1.5 inline-flex! align-middle" data-test="project-sectors" />
</div>

<div class="dk-story-h">
    <div>
        <h1>
            {{ $project->name }}
            @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'project', $project->id))
                <span class="inline-block size-2.5 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
            @endif
        </h1>
        <p class="dk-lede" data-test="story-lede">{{ $story['lede'] }}</p>
    </div>

    <div class="dk-next" data-test="story-next-step">
        <p class="dk-eyebrow">{{ __('story.next_step') }}</p>
        <p class="dk-big">{{ $next['text'] }}</p>
        @if ($next['mine'])
            <p class="dk-who">{{ __('story.yours') }}</p>
        @elseif ($next['owner'] !== null && $next['ownerName'] !== null)
            <p class="dk-who">{{ __('story.waiting_on', ['name' => $next['ownerName']]) }}</p>
        @elseif ($next['meal'])
            <p class="dk-who">{{ __('story.waiting_on_meal') }}</p>
        @endif
        @if ($next['href'] !== null || ($next['nudge'] && $next['ownerName'] !== null))
            <div class="dk-acts">
                @if ($next['href'] !== null)
                    <a class="dk-btn dk-lg {{ $next['mine'] || $next['owner'] === null ? 'dk-primary' : '' }}" href="{{ $next['href'] }}" wire:navigate data-test="{{ $story['stage'] === 0 ? 'new-concept-note' : 'story-next-go' }}">{{ $next['label'] }}</a>
                @endif
                @if ($next['nudge'] && $next['ownerName'] !== null)
                    <x-desk.poke call="nudgeNextStep" :name="$next['ownerName']" class="dk-btn" data-test="story-nudge" />
                @endif
            </div>
        @endif
    </div>
</div>

<dl class="dk-facts" data-test="story-facts">
    <div>
        <dt>{{ __('story.facts.project_status') }}</dt>
        <dd><x-ui.enum-badge :case="$project->status" /> <x-ui.project-funding-badge :project="$project" /></dd>
    </div>
    <div>
        <dt>{{ __('story.facts.proposal') }}</dt>
        <dd>
            @if ($lead !== null)
                <x-ui.enum-badge :case="$lead->status" />
                @if ($project->proposals_count > 1)
                    <span class="dk-muted dk-small">+{{ $project->proposals_count - 1 }}</span>
                @endif
            @else
                —
            @endif
        </dd>
    </div>
    <div>
        <dt>{{ __('story.facts.health') }}</dt>
        <dd><span class="dk-health dk-{{ $health }}" data-test="story-health">{{ __('desk.health.'.$health) }}</span></dd>
    </div>
    <div>
        <dt>{{ __('story.facts.donor') }}</dt>
        <dd>{{ $story['donor'] !== '' ? $story['donor'] : '—' }}</dd>
    </div>
    <div>
        <dt>{{ __('story.facts.dates') }}</dt>
        <dd>
            @if ($project->start_date !== null)
                <span>{{ $project->start_date->translatedFormat(__('formats.date')) }} – {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '…' }}</span>
            @else
                <span class="dk-muted">{{ __('story.facts.dates_pending') }}</span>
            @endif
        </dd>
    </div>
    <div>
        <dt>{{ __('story.facts.beneficiaries') }}</dt>
        <dd><span class="dk-num">{{ $beneficiaries === null ? '—' : number_format($beneficiaries) }}</span></dd>
    </div>
    @if ($money !== null)
        <div data-test="story-received">
            <dt>{{ __('story.facts.received') }}</dt>
            <dd><span class="dk-num">{{ \App\Support\Desk\Money::format($money['received'], $money['currency']) }}</span> <span class="dk-muted">{{ __('story.facts.of') }} {{ \App\Support\Desk\Money::format($money['committed'], $money['currency']) }}</span></dd>
        </div>
    @endif
    <div>
        <dt>{{ __('story.facts.reports') }}</dt>
        <dd>
            @if ($story['reports']['total'] > 0)
                <span class="dk-num">{{ $story['reports']['validated'] }}/{{ $story['reports']['total'] }}</span>
            @else
                <span class="dk-muted">{{ __('story.facts.not_reporting') }}</span>
            @endif
        </dd>
    </div>
    <div>
        <dt>{{ __('story.facts.owner') }}</dt>
        <dd>{{ $project->user?->name ?? '—' }}</dd>
    </div>
</dl>

<div class="dk-story-acts" role="group" aria-label="{{ __('story.actions_label') }}">
    @if ($project->status === \App\Enums\ProjectStatus::Draft && ! $project->hasActiveProposal())
        <span class="dk-pill dk-warn" data-test="no-active-proposal">{{ __('projects.no_active_proposal') }}</span>
    @endif
    @php
        $isArchived = $project->status === \App\Enums\ProjectStatus::Archived;
        $isClosed = $project->status === \App\Enums\ProjectStatus::Closed;
    @endphp
    @unless ($isArchived)
        @can('update', $project)
            <button type="button" class="dk-btn dk-sm" wire:click="openEditModal({{ $project->id }})" data-test="edit-project"><x-desk.icon name="edit" />{{ __('story.acts.edit') }}</button>
        @else
            <button type="button" class="dk-btn dk-ghost dk-sm dk-lockd" title="{{ __('story.acts.why_update') }}" aria-disabled="true" data-test="edit-project-locked"><x-desk.icon name="lock" />{{ __('story.acts.edit') }}</button>
        @endcan
    @endunless
    @if ($project->status === \App\Enums\ProjectStatus::Active)
        @can('update', $project)
            <button type="button" class="dk-btn dk-sm" wire:click="confirmClose" data-test="close-project">{{ __('projects.close_project') }}</button>
        @else
            <button type="button" class="dk-btn dk-ghost dk-sm dk-lockd" title="{{ __('story.acts.why_update') }}" aria-disabled="true" data-test="close-project-locked"><x-desk.icon name="lock" />{{ __('projects.close_project') }}</button>
        @endcan
    @endif
    @if ($isArchived)
        @can('delete', $project)
            <button type="button" class="dk-btn dk-primary dk-sm" wire:click="restoreProject({{ $project->id }})" data-test="restore-project"><x-desk.icon name="undo" />{{ __('story.acts.restore') }}</button>
        @else
            <button type="button" class="dk-btn dk-ghost dk-sm dk-lockd" title="{{ __('story.acts.why_archive') }}" aria-disabled="true" data-test="restore-project-locked"><x-desk.icon name="lock" />{{ __('story.acts.restore') }}</button>
        @endcan
    @else
        @can('delete', $project)
            <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="confirmArchive({{ $project->id }})" data-test="archive-project">{{ __('story.acts.archive') }}</button>
        @else
            <button type="button" class="dk-btn dk-ghost dk-sm dk-lockd" title="{{ __('story.acts.why_archive') }}" aria-disabled="true" data-test="archive-project-locked"><x-desk.icon name="lock" />{{ __('story.acts.archive') }}</button>
        @endcan
    @endif
    @if ($this->canStartConceptNote && $next['href'] !== route('proposals.index', ['for_project' => $project->id]))
        <a class="dk-btn dk-sm" href="{{ route('proposals.index', ['for_project' => $project->id]) }}" wire:navigate data-test="new-concept-note"><x-desk.icon name="plus" />{{ $isClosed ? __('story.acts.new_proposal_reopens') : __('proposals.new_concept_note') }}</a>
    @endif
</div>
