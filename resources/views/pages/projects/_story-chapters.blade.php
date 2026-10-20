{{--
    The stage rail and the seven chapters (desk.js chapter()), with the side
    column of proposals, files and activity. A done chapter folds its details
    behind "Show details"; the chapter we are in shows them open; a later
    chapter says what will happen there. Delivery (milestones, tasks, plan,
    risks, targets) always shows its working tabs, since milestones are
    planned before the project runs. Editing opens today's screens (stage 4
    brings the side sheets).
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
@endphp

<nav class="dk-rail" aria-label="{{ __('story.stages_label') }}" data-test="story-rail">
    <ol>
        @foreach ($stages as $i => $word)
            <li @class(['dk-done' => $i < $story['stage'], 'dk-now dk-'.$story['health'] => $i === $story['stage']])>
                <button type="button" x-on:click="document.getElementById('dk-ch-{{ $i }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"><i></i><span>{{ $word }}</span></button>
            </li>
        @endforeach
    </ol>
</nav>

<div class="dk-story-grid">
    <div class="dk-chapters @container/detail" data-test="story-chapters">
        @foreach ($story['chapters'] as $i => $chapter)
            @php
                $state = $chapter['state'];
            @endphp
            <article
                @class(['dk-ch', 'dk-'.$state, 'dk-'.$story['health'] => $state === 'now'])
                id="dk-ch-{{ $i }}"
                data-chapter="{{ $i }}" data-state="{{ $state }}"
                wire:key="story-chapter-{{ $i }}"
                x-data="{ open: {{ $state === 'now' ? 'true' : 'false' }} }"
            >
                <div class="dk-spine"><i></i></div>
                <div class="dk-ch-body">
                    <div class="dk-ch-top">
                        <h2>{{ $stages[$i] }}</h2>
                        @if ($state === 'now')
                            <span class="dk-here">{{ __('story.now') }}</span>
                        @elseif ($chapter['when'] !== null)
                            <span class="dk-when">{{ $chapter['when'] }}</span>
                        @endif
                    </div>
                    <p class="dk-ch-sum">{{ $chapter['summary'] }}</p>

                    @php
                        $hasDetail = $i === 0 || $i === 4 || ($openLead && isset($proposalLinks[$i]));
                    @endphp

                    @if ($hasDetail && $state === 'done')
                        <button type="button" class="dk-ch-more" x-on:click="open = ! open" x-bind:aria-expanded="open" x-text="open ? @js(__('story.hide_details')) : @js(__('story.show_details'))">{{ __('story.show_details') }}</button>
                    @endif

                    @if ($hasDetail && $state !== 'later')
                        <div class="dk-ch-detail" x-show="open" @if ($state === 'done') x-cloak @endif>
                            @if ($i === 0)
                                <div class="dk-block" data-test="story-about">
                                    <div class="dk-block-h"><h3>{{ __('story.description') }}</h3></div>
                                    <p class="dk-ch-sum">{{ $project->description ?? __('projects.no_description_recorded') }}</p>
                                    <div class="dk-benrow">
                                        @foreach ($demographics as $key => $value)
                                            <div class="dk-fig"><strong class="dk-num">{{ $value === null ? '—' : number_format($value) }}</strong><span>{{ __('projects.'.$key) }}</span></div>
                                        @endforeach
                                    </div>
                                    <p class="dk-muted dk-small">
                                        {{ __('projects.headline_target') }}: <span class="dk-num">{{ $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries) }}</span>
                                        · {{ __('projects.demographic_total') }}: <span class="dk-num">{{ $project->targetDemographicTotal() === null ? '—' : number_format($project->targetDemographicTotal()) }}</span>
                                    </p>
                                </div>
                            @endif

                            @if ($i === 4)
                                <div class="dk-acts dk-start"><a class="dk-btn dk-sm" href="{{ route('reporting-duties.index') }}" wire:navigate>{{ __('story.open.duties') }}</a></div>
                            @endif

                            @if ($openLead && isset($proposalLinks[$i]))
                                <div class="dk-acts dk-start" data-test="story-proposal-links">
                                    @foreach ($proposalLinks[$i] as $tabKey)
                                        <a class="dk-btn dk-sm" href="{{ $this->proposalStoryUrl($lead->id, $tabKey) }}" wire:navigate data-proposal-tab-link="{{ $tabKey }}">{{ __('story.open.'.$tabKey) }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($i === 2 && $this->embeddedProposalId !== null)
                        @include('pages.projects._story-proposal')
                    @endif

                    @if ($i === 5)
                        <div class="dk-block" data-test="story-delivery">
                            <div class="dk-block-h">
                                <h3>{{ __('projects.milestone_progress') }}</h3>
                                <span class="dk-meta" data-test="story-milestone-progress">
                                    @if ($this->milestoneProgressPercent === null)
                                        {{ __('projects.no_weighted_milestones') }}
                                    @else
                                        <strong class="dk-num">{{ $this->milestoneProgressPercent }}%</strong> · {{ __('projects.milestones_reached', ['reached' => $project->reached_milestones_count, 'total' => $project->milestones_count]) }}
                                    @endif
                                </span>
                            </div>
                            <x-ui.relation-tabs :tabs="$this->tabs" :active="$this->deliveryTab" wire-target="tab" />
                            <div class="mt-4">
                                @switch($this->deliveryTab)
                                    @case('tasks')
                                        @include('pages.projects._show-tasks-tab')
                                        @break
                                    @case('plan')
                                        @include('pages.projects._show-plan-tab')
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
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    <aside class="dk-story-side @container/detail" data-test="story-side">
        <section class="dk-sblock" data-test="story-side-funding">
            <h3>{{ __('story.funding') }}</h3>
            @include('pages.projects._story-funding')
        </section>
        <section class="dk-sblock" data-test="story-side-proposals">
            <h3>{{ __('story.side.proposals') }}</h3>
            @include('pages.projects._show-proposals-tab')
        </section>
        <section class="dk-sblock" data-test="story-side-files">
            <h3>{{ __('story.side.files') }}</h3>
            @include('pages.projects._show-files-tab')
        </section>
        <section class="dk-sblock" data-test="story-side-activity">
            <h3>{{ __('story.side.activity') }}</h3>
            @include('pages.projects._show-activity-tab')
        </section>
    </aside>
</div>
