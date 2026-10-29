{{--
    The project page's body: the stage bar (the only place the seven stages
    are drawn; picking one shows its story in the Overview), then four tabs
    that switch in the browser (no round trip). Each tab is a grid of cards:
      Overview  - about, people, the picked stage, coming up, monthly reports;
      Work      - milestones, tasks, risks, targets, report template;
      Funding   - _show-funding: figures, donors, proposals, history, the proposal;
      Files     - files, then what happened.
    Every tab is rendered, so a deep link or a test reads it all; only the
    open one is shown.
--}}
@php
    $story = $this->story;
    $lead = $story['lead'];
    $stages = __('desk.stages');
    $openLead = $lead !== null && auth()->user()?->can('view', $lead);
    $proposalLinks = [
        1 => ['status'],
        2 => ['status', 'logframe', 'targets', 'plan', 'deadlines'],
        3 => array_values(array_filter(['status', $this->canViewAgreements() ? 'funding' : null])),
        6 => ['reports', 'status'],
    ];
    $project = $this->project;
    $demographics = ['men' => $project->target_men, 'women' => $project->target_women, 'boys' => $project->target_boys, 'girls' => $project->target_girls];
    $openSection = $section;
    $sections = [
        'overview' => ['label' => __('story.sections.overview'), 'count' => null],
        'work' => ['label' => __('story.sections.work'), 'count' => $project->milestones_count ?: null],
        'funding' => ['label' => __('story.sections.funding'), 'count' => $project->proposals_count ?: null],
        'files' => ['label' => __('story.sections.files'), 'count' => $project->media_count ?: null],
    ];
    $lastStage = count($stages) - 1;
@endphp

<div class="dk-pjbody" x-data="{ section: $wire.$entangle('section'), stage: {{ $story['stage'] }}, pick(i) { this.stage = i; this.section = 'overview'; } }">
    <nav class="dk-rail" aria-label="{{ __('story.stages_label') }}" data-test="story-rail">
        <ol>
            @foreach ($stages as $i => $word)
                <li @class(['dk-done' => $i < $story['stage'], 'dk-now dk-'.$story['health'] => $i === $story['stage']]) x-bind:class="{ 'dk-picked': section === 'overview' && stage === {{ $i }} }">
                    <button type="button" x-on:click="pick({{ $i }})" x-bind:aria-pressed="section === 'overview' && stage === {{ $i }}"><i></i><span>{{ $word }}</span></button>
                </li>
            @endforeach
        </ol>
    </nav>

    <div class="dk-pjtabs" role="tablist" aria-label="{{ __('story.sections.label') }}">
        @foreach ($sections as $sectionKey => $sectionTab)
            <button
                type="button"
                role="tab"
                id="dk-tab-{{ $sectionKey }}"
                aria-controls="dk-section-{{ $sectionKey }}"
                x-on:click="section = '{{ $sectionKey }}'"
                x-bind:aria-selected="section === '{{ $sectionKey }}'"
                aria-selected="{{ $openSection === $sectionKey ? 'true' : 'false' }}"
                data-section-tab="{{ $sectionKey }}"
            >
                {{ $sectionTab['label'] }}
                @if ($sectionTab['count'] !== null)
                    <span class="dk-num">{{ $sectionTab['count'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Overview --}}
    <section class="dk-pjsec @container/detail" id="dk-section-overview" role="tabpanel" aria-labelledby="dk-tab-overview" x-show="section === 'overview'" @if ($openSection !== 'overview') style="display: none" @endif data-section="overview">
        <div class="dk-pjgrid">
            <div class="dk-card dk-span-7" data-test="story-about">
                <div class="dk-card-h"><h3>{{ __('story.description') }}</h3></div>
                <div class="dk-card-b">
                    <p class="dk-pjabout">{{ $project->description ?? __('projects.no_description_recorded') }}</p>
                </div>
                <div class="dk-card-h dk-card-sub"><h3>{{ __('story.people') }}</h3>
                    <span class="dk-meta">{{ __('projects.headline_target') }}: <strong class="dk-num">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</strong></span>
                </div>
                <dl class="dk-cells">
                    @foreach ($demographics as $key => $value)
                        <div>
                            <dt>{{ __('projects.'.$key) }}</dt>
                            <dd class="dk-num">{{ $value === null ? '—' : number_format($value) }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt>{{ __('projects.demographic_total') }}</dt>
                        <dd class="dk-num">{{ $project->targetDemographicTotal() === null ? '—' : number_format($project->targetDemographicTotal()) }}</dd>
                    </div>
                </dl>
            </div>

            {{-- The picked stage of the bar above: its story, details and links. --}}
            <div class="dk-card dk-span-5" data-test="story-chapters">
                <div class="dk-card-h">
                    <h3>{{ __('story.stage') }}</h3>
                    <span class="dk-pjstep-nav">
                        <button type="button" class="dk-iconbtn dk-sm" x-on:click="stage = Math.max(0, stage - 1)" x-bind:aria-disabled="stage === 0" aria-label="{{ __('story.previous_stage') }}"><x-desk.icon name="chev" class="dk-flip rotate-180" /></button>
                        <span class="dk-num dk-small" x-text="(stage + 1) + ' / {{ $lastStage + 1 }}'">{{ $story['stage'] + 1 }} / {{ $lastStage + 1 }}</span>
                        <button type="button" class="dk-iconbtn dk-sm" x-on:click="stage = Math.min({{ $lastStage }}, stage + 1)" x-bind:aria-disabled="stage === {{ $lastStage }}" aria-label="{{ __('story.next_stage') }}"><x-desk.icon name="chev" class="dk-flip" /></button>
                    </span>
                </div>
                <ol class="dk-pjstage">
                    @foreach ($story['chapters'] as $i => $chapter)
                        @php
                            $state = $chapter['state'];
                            $hasFiles = $state === 'done' && in_array($i, [1, 3, 6], true) && isset($this->chapterFiles[$i]);
                            $hasLinks = $openLead && isset($proposalLinks[$i]) && $state !== 'later';
                        @endphp
                        <li
                            @class(['dk-ch', 'dk-'.$state, 'dk-'.$story['health'] => $state === 'now'])
                            id="dk-ch-{{ $i }}"
                            data-chapter="{{ $i }}" data-state="{{ $state }}"
                            wire:key="story-chapter-{{ $i }}"
                            x-show="stage === {{ $i }}"
                            @if ($i !== $story['stage']) style="display: none" @endif
                        >
                            <div class="dk-ch-top">
                                <h4>{{ $stages[$i] }}</h4>
                                @if ($state === 'now')
                                    <span class="dk-here">{{ __('story.now') }}</span>
                                @elseif ($state === 'done')
                                    <span class="dk-pill dk-good">{{ __('story.stage_done') }}</span>
                                @else
                                    <span class="dk-pill dk-mute">{{ __('story.stage_later') }}</span>
                                @endif
                                @if ($chapter['when'] !== null)
                                    <span class="dk-when">{{ $chapter['when'] }}</span>
                                @endif
                            </div>
                            <p class="dk-ch-sum">{{ $chapter['summary'] }}</p>

                            @if ($hasFiles)
                                @include('pages.projects._story-details')
                            @endif
                            @if ($hasLinks)
                                <div class="dk-acts dk-start" data-test="story-proposal-links">
                                    @foreach ($proposalLinks[$i] as $tabKey)
                                        <a class="dk-btn dk-sm" href="{{ $this->proposalStoryUrl($lead->id, $tabKey) }}" wire:navigate data-proposal-tab-link="{{ $tabKey }}">{{ __('story.open.'.$tabKey) }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>

            <div class="dk-card dk-span-7" data-test="story-upcoming">
                <div class="dk-card-h">
                    <h3>{{ __('story.coming_up') }}</h3>
                    <button type="button" class="dk-link" x-on:click="section = 'work'">{{ __('story.see_all_work') }}</button>
                </div>
                @if ($this->upcomingMilestones->isEmpty())
                    <p class="dk-card-b dk-muted dk-small">{{ __('story.nothing_coming_up') }}</p>
                @else
                    <ul class="dk-rows">
                        @foreach ($this->upcomingMilestones as $upcoming)
                            @php
                                $upcomingLate = $upcoming->planned_end_date !== null && $upcoming->planned_end_date->isPast();
                            @endphp
                            <li class="dk-pjup" wire:key="upcoming-{{ $upcoming->id }}">
                                <span @class(['dk-pjup-d dk-num', 'dk-bad' => $upcomingLate])>{{ $upcoming->planned_end_date?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                                <span class="dk-pjup-n" dir="auto">{{ $upcoming->name }}</span>
                                <span class="dk-muted dk-small">{{ $upcoming->responsibleUser?->name }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="dk-card dk-span-5" data-test="story-reporting">
                <div class="dk-card-h">
                    <h3>{{ __('story.monthly_reports') }}</h3>
                    <a class="dk-link" href="{{ route('reporting-duties.index') }}" wire:navigate>{{ __('story.open.duties') }}</a>
                </div>
                <div class="dk-card-b">
                    @if ($story['months'] === [] && $this->templateLines === [])
                        <p class="dk-muted dk-small">{{ __('story.no_reports_yet') }}</p>
                    @endif
                    @php
                        $i = 5;
                        $state = 'now';
                    @endphp
                    @include('pages.projects._story-details')
                    @php
                        $i = 4;
                    @endphp
                    @include('pages.projects._story-details')
                </div>
            </div>
        </div>
    </section>

    {{-- Work --}}
    <section class="dk-pjsec @container/detail" id="dk-section-work" role="tabpanel" aria-labelledby="dk-tab-work" x-show="section === 'work'" @if ($openSection !== 'work') style="display: none" @endif data-section="work">
        <div data-test="story-delivery">
            <div class="dk-pjwork-h">
                <x-ui.relation-tabs :tabs="$this->tabs" :active="$this->deliveryTab" wire-target="tab" class="dk-subtabs" />
                <span class="dk-meta" data-test="story-milestone-progress">
                    @if ($this->milestoneProgressPercent === null)
                        {{ __('projects.no_weighted_milestones') }}
                    @else
                        <strong class="dk-num">{{ $this->milestoneProgressPercent }}%</strong> · {{ __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                    @endif
                </span>
            </div>
            <div class="dk-pjwork">
                @switch($this->deliveryTab)
                    @case('tasks')
                        @include('pages.projects._show-tasks-tab')
                        @break
                    @case('risks')
                        @include('pages.projects._show-risks-tab')
                        @break
                    @case('targets')
                        @include('pages.projects._show-targets-tab')
                        @break
                    @case('template')
                        @include('pages.projects._show-template-tab')
                        @break
                    @default
                        @include('pages.projects._show-milestones-tab')
                @endswitch
            </div>
        </div>
    </section>

    {{-- Funding --}}
    <section class="dk-pjsec @container/detail" id="dk-section-funding" role="tabpanel" aria-labelledby="dk-tab-funding" x-show="section === 'funding'" @if ($openSection !== 'funding') style="display: none" @endif data-section="funding">
        @include('pages.projects._show-funding')
    </section>

    {{-- Files & activity --}}
    <section class="dk-pjsec @container/detail" id="dk-section-files" role="tabpanel" aria-labelledby="dk-tab-files" x-show="section === 'files'" @if ($openSection !== 'files') style="display: none" @endif data-section="files">
        <div class="dk-pjgrid">
            <div class="dk-card dk-span-7" data-test="story-side-files">
                <div class="dk-card-h"><h3>{{ __('story.side.files') }}</h3><span class="dk-meta dk-num">{{ $project->media_count }}</span></div>
                <div class="dk-card-b">
                    @include('pages.projects._show-files-tab')
                </div>
            </div>
            <div class="dk-card dk-span-5" data-test="story-side-activity">
                <div class="dk-card-h"><h3>{{ __('story.side.activity') }}</h3></div>
                <div class="dk-card-b">
                    @include('pages.projects._show-activity-tab')
                </div>
            </div>
        </div>
    </section>
</div>
