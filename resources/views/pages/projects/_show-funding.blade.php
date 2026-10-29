{{--
    The project page's Funding tab: a summary strip of the headline figures,
    then three folding sections — the donors behind the money, every proposal
    on the project (the chosen one expands in place) and the funding history.
    The browser remembers which sections are open ($persist).
    Amounts are read only for funding-agreements.view / transfers.view holders
    (fundingByCurrency() and fundingSources() skip the query for everyone else);
    names, numbers and statuses show to everyone.
--}}
@php
    $canSeeMoney = $this->canViewMoney();
    $summary = $this->attachedProposals->isEmpty() ? null : $this->proposalsSummary;
@endphp

<div class="dk-pjfund"
    x-data="{
        donorsOpen: $persist(true).as('project-funding-donors'),
        proposalsOpen: $persist(true).as('project-funding-proposals'),
        historyOpen: $persist(false).as('project-funding-history'),
        linking: false,
        openProposal: {{ $this->embeddedProposalId ?? 'null' }},
    }">
    {{-- The figures: money first for those who may see it, then the counts. --}}
    <div class="dk-card" data-test="story-funding">
        <dl class="dk-cells dk-cells-lg">
            @if ($canSeeMoney)
                @forelse ($this->fundingByCurrency as $row)
                    <div wire:key="funding-figure-{{ $row['currency']->value }}" data-funding-envelope>
                        <dt>{{ __('projects.funding_envelope') }} · {{ $row['currency']->value }}</dt>
                        <dd class="dk-num"><x-ui.money :amount="(float) $row['committed']" :currency="$row['currency']" /></dd>
                        <p class="dk-cell-sub">{{ __('ui.received') }} <strong class="dk-num"><x-ui.money :amount="(float) $row['received']" :currency="$row['currency']" /></strong></p>
                        @php
                            $receivedShare = (float) $row['committed'] > 0 ? min(100, (float) $row['received'] / (float) $row['committed'] * 100) : 0;
                        @endphp
                        <span class="dk-pjh-bar dk-wide" aria-hidden="true"><i style="width: {{ round($receivedShare, 1) }}%"></i></span>
                    </div>
                @empty
                    <div>
                        <dt>{{ __('projects.funding_envelope') }}</dt>
                        <dd>{{ __('projects.not_funded_yet') }}</dd>
                        <p class="dk-cell-sub">{{ __('projects.link_a_proposal_to_record_which_funding_covers_this_project') }}</p>
                    </div>
                @endforelse
            @endif
            <div>
                <dt>{{ __('projects.funding_sources') }}</dt>
                <dd class="dk-num">{{ $this->fundingSources->count() }}</dd>
            </div>
            <div>
                <dt>{{ __('story.side.proposals') }}</dt>
                <dd class="dk-num">{{ $this->attachedProposals->count() }}</dd>
                @if ($summary !== null)
                    <p class="dk-cell-sub" data-test="story-proposals-summary">
                        @if ($canSeeMoney)
                            <span class="dk-num">{{ $summary['agreements'] }}</span> {{ __('projects.stat_funding_agreements') }} ·
                        @endif
                        <span class="dk-num">{{ $summary['underway'] }}</span> {{ __('projects.stat_proposals_underway') }}
                    </p>
                @endif
            </div>
        </dl>
    </div>

    {{-- Donors --}}
    <section class="dk-card dk-fold" :class="{ 'dk-fold-on': donorsOpen }">
        <button type="button" class="dk-card-h dk-fold-h" @click="donorsOpen = ! donorsOpen" :aria-expanded="donorsOpen">
            <h3>{{ __('story.donors') }}</h3>
            <span class="dk-meta dk-num">{{ $this->fundingSources->count() }}</span>
            <x-desk.icon name="chev" class="dk-fold-i" />
        </button>
        <div x-show="donorsOpen" x-collapse>
            @if ($this->fundingSources->isEmpty())
                <div class="dk-card-b">
                    <p class="dk-small"><strong>{{ __('projects.no_donor_approved_funding_yet') }}</strong></p>
                    <p class="dk-small dk-muted">{{ __('projects.funding_becomes_official_when_a_donor_approves_a_proposal') }}</p>
                </div>
            @else
                <ul class="dk-rows" data-funding-sources>
                    @foreach ($this->fundingSources as $source)
                        <li class="dk-row2" wire:key="funding-source-{{ $source->id }}">
                            <div>
                                <strong dir="auto">{{ $source->donor->name }}</strong>
                                <span class="dk-is" dir="auto">{{ $source->name }}</span>
                            </div>
                            <div class="dk-row2-r">
                                <x-ui.enum-badge :case="$source->status" />
                                @if ($this->canViewAgreements() && $source->donorProposals->isNotEmpty())
                                    @foreach ($source->donorProposals as $agreement)
                                        <span class="dk-num dk-small" dir="ltr">{{ number_format((float) $agreement->budget, 2) }} {{ $agreement->currency->value }}</span>
                                    @endforeach
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- Proposals: one row each; the chosen one expands in place. --}}
    <section class="dk-card dk-fold" :class="{ 'dk-fold-on': proposalsOpen }" data-test="story-proposals">
        <button type="button" class="dk-card-h dk-fold-h" @click="proposalsOpen = ! proposalsOpen" :aria-expanded="proposalsOpen">
            <h3>{{ __('story.side.proposals') }}</h3>
            <span class="dk-meta dk-num">{{ $this->attachedProposals->count() }}</span>
            <x-desk.icon name="chev" class="dk-fold-i" />
        </button>
        <div x-show="proposalsOpen" x-collapse>
            @if ($this->attachedProposals->isEmpty())
                <div class="dk-card-b">
                    <p class="dk-small"><strong>{{ __('projects.no_proposals_linked') }}</strong></p>
                    <p class="dk-small dk-muted">{{ __('projects.link_a_proposal_to_record_which_funding_covers_this_project') }}</p>
                </div>
            @else
                {{-- One aligned grid row per proposal; on narrow screens each row stacks into a card. --}}
                <div class="dk-prows" role="table" aria-label="{{ __('story.side.proposals') }}">
                    <div class="dk-prow dk-prow-h" role="row">
                        <span role="columnheader">{{ __('story.facts.proposal') }}</span>
                        <span role="columnheader">{{ __('story.facts.donor') }}</span>
                        <span role="columnheader">{{ __('story.facts.dates') }}</span>
                        <span role="columnheader">{{ __('common.funding') }}</span>
                        <span role="columnheader">{{ __('common.status') }}</span>
                        <span role="columnheader"><span class="sr-only">{{ __('common.actions') }}</span></span>
                    </div>
                    @foreach ($this->attachedProposals as $proposal)
                        @php
                            $isEmbedded = $proposal->id === $this->embeddedProposalId;
                            $donorNames = $proposal->donorNames->unique('id')->pluck('name')->implode(', ');
                        @endphp
                        <div class="dk-prow-wrap" wire:key="project-proposal-{{ $proposal->id }}" :class="{ 'dk-row-on': openProposal === {{ $proposal->id }} }">
                            <div class="dk-prow" role="row">
                                <div class="dk-prow-name" role="cell">
                                    @if ($this->canViewProposals())
                                        <a href="{{ $this->proposalStoryUrl($proposal->id) }}" wire:navigate data-story-proposal-link dir="auto">{{ $proposal->name }}</a>
                                    @else
                                        <strong dir="auto">{{ $proposal->name }}</strong>
                                    @endif
                                    <span class="dk-prow-sub"><x-ui.project-number :number="$proposal->internal_project_number" kind="internal" /> · {{ $proposal->phase->label() }}</span>
                                </div>
                                <div role="cell" data-label="{{ __('story.facts.donor') }}" dir="auto">{{ $donorNames !== '' ? $donorNames : '—' }}</div>
                                <div role="cell" data-label="{{ __('story.facts.dates') }}" class="dk-num">
                                    @if ($proposal->planned_date_begin !== null)
                                        {{ $proposal->planned_date_begin->translatedFormat(__('formats.date')) }}
                                        <span class="dk-prow-sub">→ {{ $proposal->planned_date_end?->translatedFormat(__('formats.date')) ?? '—' }}</span>
                                    @else
                                        —
                                    @endif
                                </div>
                                <div role="cell" data-label="{{ __('common.funding') }}">
                                    @forelse ($this->proposalFunding($proposal) as $row)
                                        @php
                                            $share = (float) $row['committed'] > 0 ? min(100, (float) $row['received'] / (float) $row['committed'] * 100) : 0;
                                        @endphp
                                        <div class="dk-prow-money" dir="ltr" wire:key="proposal-{{ $proposal->id }}-funding-{{ $row['currency']->value }}">
                                            <span class="dk-num"><x-ui.money :amount="(float) $row['received']" :currency="$row['currency']" /> <span class="dk-prow-sub">/ <x-ui.money :amount="(float) $row['committed']" :currency="$row['currency']" /></span></span>
                                            <span class="dk-pjh-bar dk-wide" aria-hidden="true"><i style="width: {{ round($share, 1) }}%"></i></span>
                                        </div>
                                    @empty
                                        <span class="dk-prow-sub">—</span>
                                    @endforelse
                                </div>
                                <div role="cell" data-label="{{ __('common.status') }}"><x-ui.enum-badge :case="$proposal->status" /></div>
                                <div role="cell" class="dk-prow-acts">
                                    @can('proposals.update')
                                        <button type="button" class="dk-iconbtn dk-sm" wire:click="detachProposal({{ $proposal->id }})" aria-label="{{ __('projects.unlink_name', ['name' => $proposal->name]) }}" title="{{ __('projects.unlink_name', ['name' => $proposal->name]) }}"><x-desk.icon name="x" /></button>
                                    @endcan
                                    @if ($isEmbedded)
                                        <button type="button" class="dk-iconbtn dk-sm dk-fold-t" @click="openProposal = openProposal === {{ $proposal->id }} ? null : {{ $proposal->id }}" :aria-expanded="openProposal === {{ $proposal->id }}" aria-label="{{ __('story.show_details') }}" title="{{ __('story.show_details') }}"><x-desk.icon name="chev" class="dk-fold-i" /></button>
                                    @elseif ($this->canViewProposals())
                                        <a href="{{ $this->proposalStoryUrl($proposal->id) }}" wire:navigate class="dk-iconbtn dk-sm dk-fold-t" aria-label="{{ __('story.show_details') }}" title="{{ __('story.show_details') }}"><x-desk.icon name="chev" class="dk-fold-i" /></a>
                                    @endif
                                </div>
                            </div>
                            @if ($isEmbedded)
                                {{-- The chosen proposal, in full, without leaving the project. --}}
                                <div class="dk-pjprop" x-show="openProposal === {{ $proposal->id }}" x-collapse>
                                    @include('pages.projects._story-proposal')
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @can('proposals.update')
                <div class="dk-card-f" x-show="! linking">
                    <button type="button" class="dk-btn dk-sm" @click="linking = true"><x-desk.icon name="plus" />{{ __('projects.link_a_proposal') }}</button>
                </div>
                <div class="dk-card-f dk-story-link" data-test="story-link-proposal" x-show="linking" x-cloak>
                    <x-ui.select :label="__('projects.link_a_proposal')" wire:model="proposalToAttach">
                        <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                        @foreach ($this->attachableProposals as $proposalOption)
                            <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                    <button type="button" class="dk-btn dk-sm" wire:click="attachProposal"><x-desk.icon name="plus" />{{ __('projects.link') }}</button>
                    <button type="button" class="dk-btn dk-sm" @click="linking = false">{{ __('common.cancel') }}</button>
                </div>
            @endcan
        </div>
    </section>

    {{-- Funding history, oldest first, as a table. --}}
    @if ($this->fundingHistory->isNotEmpty())
        <section class="dk-card dk-fold" :class="{ 'dk-fold-on': historyOpen }">
            <button type="button" class="dk-card-h dk-fold-h" @click="historyOpen = ! historyOpen" :aria-expanded="historyOpen">
                <h3>{{ __('projects.funding_history') }}</h3>
                <span class="dk-meta dk-num">{{ $this->fundingHistory->count() }}</span>
                <x-desk.icon name="chev" class="dk-fold-i" />
            </button>
            <div x-show="historyOpen" x-collapse>
                <p class="dk-fold-hint dk-small dk-muted">{{ __('projects.funding_history_hint') }}</p>
                <div class="dk-tblx">
                    <table data-test="funding-history">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('story.history.years') }}</th>
                                <th scope="col">{{ __('story.facts.proposal') }}</th>
                                <th scope="col">{{ __('story.facts.donor') }}</th>
                                <th scope="col">{{ __('common.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->fundingHistory as $entry)
                                <tr wire:key="funding-history-{{ $entry->id }}">
                                    <td class="dk-num" dir="ltr">{{ $entry->planned_date_begin?->format('Y') ?? '—' }}@if ($entry->planned_date_end !== null && $entry->planned_date_end->format('Y') !== $entry->planned_date_begin?->format('Y'))–{{ $entry->planned_date_end->format('y') }}@endif</td>
                                    <td>
                                        @if ($this->canViewProposals())
                                            <a href="{{ $this->attachedProposals->contains('id', $entry->id) ? $this->proposalStoryUrl($entry->id) : route('proposals.show', $entry) }}" wire:navigate dir="auto">{{ $entry->name }}</a>
                                        @else
                                            <span dir="auto">{{ $entry->name }}</span>
                                        @endif
                                        <span class="dk-is"><x-ui.project-number :number="$entry->internal_project_number" kind="internal" /></span>
                                        @if ($entry->previousProposal !== null)
                                            <span class="dk-is" dir="auto">{{ __('projects.funding_history_renews', ['number' => $entry->previousProposal->internal_project_number]) }}</span>
                                        @endif
                                    </td>
                                    <td dir="auto">{{ $entry->donor->name ?? '—' }}</td>
                                    <td><x-ui.enum-badge :case="$entry->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>
