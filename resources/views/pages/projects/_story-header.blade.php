{{--
    The project page's head: back to Projects, the path, the name, one meta
    line (owner, dates, status, proposal, donor), the health and progress with
    the project's actions, four headline figures, and the next step as one
    banner across the page.
--}}
@php
    $story = $this->story;
    $next = $story['next'];
    $lead = $story['lead'];
    $health = $story['health'];
    $project = $this->project;
    $beneficiaries = $project->targetDemographicTotal() ?? $project->target_beneficiaries;
    $money = $this->canViewMoney() ? $this->fundingByCurrency->first() : null;
    $progress = $this->milestoneProgressPercent;
    $isArchived = $project->status === \App\Enums\ProjectStatus::Archived;
    $isClosed = $project->status === \App\Enums\ProjectStatus::Closed;
    $canEditProject = ! $isArchived && (auth()->user()?->can('update', $project) ?? false);
    $canCloseProject = $project->status === \App\Enums\ProjectStatus::Active && (auth()->user()?->can('update', $project) ?? false);
    $canArchiveProject = auth()->user()?->can('delete', $project) ?? false;
@endphp

<a class="dk-back" href="{{ route('projects.index') }}" wire:navigate data-story-back><x-desk.icon name="back" class="dk-flip" />{{ __('common.projects') }}</a>

<header class="dk-pjh">
    <div class="dk-pjh-main">
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

        <h1>
            {{ $project->name }}
            @if (app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), 'project', $project->id))
                <span class="inline-block size-2.5 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
            @endif
        </h1>

        <p class="dk-pjh-meta">
            <x-ui.enum-badge :case="$project->status" />
            <x-ui.project-funding-badge :project="$project" />
            <span><span class="dk-pjh-k">{{ __('story.facts.owner') }}</span> {{ $project->user?->name ?? '—' }}</span>
            <span>
                <span class="dk-pjh-k">{{ __('story.facts.dates') }}</span>
                @if ($project->start_date !== null)
                    <span class="dk-num">{{ $project->start_date->translatedFormat(__('formats.date')) }} – {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '…' }}</span>
                @else
                    <span class="dk-muted">{{ __('story.facts.dates_pending') }}</span>
                @endif
            </span>
            @if ($story['donor'] !== '')
                <span><span class="dk-pjh-k">{{ __('story.facts.donor') }}</span> {{ $story['donor'] }}</span>
            @endif
            @if ($lead !== null)
                <span>
                    <span class="dk-pjh-k">{{ __('story.facts.proposal') }}</span>
                    <x-ui.enum-badge :case="$lead->status" />
                    @if ($project->proposals_count > 1)
                        <span class="dk-muted dk-small">+{{ $project->proposals_count - 1 }}</span>
                    @endif
                </span>
            @endif
        </p>
    </div>

    <div class="dk-pjh-side">
        <div class="dk-story-acts" role="group" aria-label="{{ __('story.actions_label') }}">
            @if ($project->status === \App\Enums\ProjectStatus::Draft && ! $project->hasActiveProposal())
                <span class="dk-pill dk-warn" data-test="no-active-proposal">{{ __('projects.no_active_proposal') }}</span>
            @endif
            @if ($this->canStartConceptNote && $next['href'] !== route('proposals.index', ['for_project' => $project->id]))
                <a class="dk-btn dk-sm" href="{{ route('proposals.index', ['for_project' => $project->id]) }}" wire:navigate data-test="new-concept-note"><x-desk.icon name="plus" />{{ $isClosed ? __('story.acts.new_proposal_reopens') : __('proposals.new_concept_note') }}</a>
            @endif
            @if ($isArchived && $canArchiveProject)
                <button type="button" class="dk-btn dk-primary dk-sm" wire:click="restoreProject({{ $project->id }})" data-test="restore-project"><x-desk.icon name="undo" />{{ __('story.acts.restore') }}</button>
            @endif
            @if ($canEditProject)
                <button type="button" class="dk-btn dk-sm" wire:click="openEditModal({{ $project->id }})" data-test="edit-project"><x-desk.icon name="edit" />{{ __('story.acts.edit') }}</button>
            @endif

            {{-- The rarer actions wait behind one button. --}}
            @if ($canCloseProject || ($canArchiveProject && ! $isArchived))
                <span class="dk-pmenu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" class="dk-iconbtn dk-sm" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu" aria-label="{{ __('projects.more_actions_for', ['name' => $project->name]) }}"><x-desk.icon name="more" /></button>
                    <span class="dk-pop dk-pjl-menu" role="menu" x-show="open" x-cloak x-on:click="open = false">
                        @if ($canCloseProject)
                            <button type="button" role="menuitem" wire:click="confirmClose" data-test="close-project">
                                <flux:icon.check-circle variant="micro" class="size-4" aria-hidden="true" />{{ __('projects.close_project') }}
                            </button>
                        @endif
                        @if ($canArchiveProject && ! $isArchived)
                            <button type="button" role="menuitem" wire:click="confirmArchive({{ $project->id }})" data-test="archive-project">
                                <flux:icon.archive-box variant="micro" class="size-4" aria-hidden="true" />{{ __('story.acts.archive') }}
                            </button>
                        @endif
                    </span>
                </span>
            @endif
        </div>
    </div>
</header>

{{-- Four figures that answer "how is it going": progress, people, money, reports. --}}
<dl class="dk-pjh-figs" data-test="story-facts">
    <div>
        <dt>{{ __('story.facts.health') }}</dt>
        <dd>
            <span class="dk-health dk-{{ $health }}" data-test="story-health">{{ __('desk.health.'.$health) }}</span>
            <span class="dk-pjh-bar" role="img" aria-label="{{ $progress === null ? __('projects.no_weighted_milestones') : __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}"><i style="width: {{ $progress ?? 0 }}%"></i></span>
            <strong class="dk-num">{{ $progress === null ? '—' : round($progress).'%' }}</strong>
        </dd>
    </div>
    <div>
        <dt>{{ __('story.facts.beneficiaries') }}</dt>
        <dd><strong class="dk-num">{{ $beneficiaries === null ? '—' : number_format($beneficiaries) }}</strong></dd>
    </div>
    @if ($money !== null)
        <div data-test="story-received">
            <dt>{{ __('story.facts.received') }}</dt>
            <dd><strong class="dk-num">{{ \App\Support\Desk\Money::format($money['received'], $money['currency']) }}</strong> <span class="dk-muted">{{ __('story.facts.of') }} {{ \App\Support\Desk\Money::format($money['committed'], $money['currency']) }}</span></dd>
        </div>
    @endif
    <div>
        <dt>{{ __('story.facts.reports') }}</dt>
        <dd>
            @if ($story['reports']['total'] > 0)
                <strong class="dk-num">{{ $story['reports']['validated'] }}/{{ $story['reports']['total'] }}</strong>
            @else
                <span class="dk-muted">{{ __('story.facts.not_reporting') }}</span>
            @endif
        </dd>
    </div>
</dl>

{{-- The one thing to do next, across the page so it cannot be missed. --}}
<div @class(['dk-pjnext', 'dk-mine' => $next['mine']]) data-test="story-next-step">
    <div class="dk-pjnext-t">
        <p class="dk-eyebrow">{{ __('story.next_step') }}</p>
        <p class="dk-big">{{ $next['text'] }}</p>
        @if ($next['mine'])
            <p class="dk-who">{{ __('story.yours') }}</p>
        @elseif ($next['owner'] !== null && $next['ownerName'] !== null)
            <p class="dk-who">{{ __('story.waiting_on', ['name' => $next['ownerName']]) }}</p>
        @elseif ($next['meal'])
            <p class="dk-who">{{ __('story.waiting_on_meal') }}</p>
        @endif
    </div>
    @if ($next['href'] !== null || ($next['nudge'] && $next['ownerName'] !== null))
        <div class="dk-acts">
            @if ($next['nudge'] && $next['ownerName'] !== null)
                <x-desk.poke call="nudgeNextStep" :name="$next['ownerName']" class="dk-btn" data-test="story-nudge" />
            @endif
            @if ($next['href'] !== null)
                <a class="dk-btn {{ $next['mine'] || $next['owner'] === null ? 'dk-primary' : '' }}" href="{{ $next['href'] }}" wire:navigate data-test="{{ $story['stage'] === 0 ? 'new-concept-note' : 'story-next-go' }}">{{ $next['label'] }}</a>
            @endif
        </div>
    @endif
</div>
