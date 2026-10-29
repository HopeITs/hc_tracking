{{--
    The project page's Funding tab, as a grid of cards: the headline figures,
    the donors behind the money, every proposal on the project (one row each,
    the chosen one opens in full below), and the funding history as a table.
    Amounts are read only for funding-agreements.view / transfers.view holders
    (fundingByCurrency() and fundingSources() skip the query for everyone else);
    names, numbers and statuses show to everyone.
--}}
@php
    $canSeeMoney = $this->canViewMoney();
    $summary = $this->attachedProposals->isEmpty() ? null : $this->proposalsSummary;
@endphp

<div class="dk-pjgrid">
    {{-- The figures: money first for those who may see it, then the counts. --}}
    <div class="dk-card dk-span-12" data-test="story-funding">
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
                <p class="dk-cell-sub">{{ __('projects.funding_becomes_official_when_a_donor_approves_a_proposal') }}</p>
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
    <div class="dk-card dk-span-5">
        <div class="dk-card-h"><h3>{{ __('story.donors') }}</h3><span class="dk-meta dk-num">{{ $this->fundingSources->count() }}</span></div>
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

    {{-- Proposals: one row each; the chosen one opens in full below. --}}
    <div class="dk-card dk-span-7" data-test="story-proposals">
        <div class="dk-card-h"><h3>{{ __('story.side.proposals') }}</h3><span class="dk-meta dk-num">{{ $this->attachedProposals->count() }}</span></div>
        @if ($this->attachedProposals->isEmpty())
            <div class="dk-card-b">
                <p class="dk-small"><strong>{{ __('projects.no_proposals_linked') }}</strong></p>
                <p class="dk-small dk-muted">{{ __('projects.link_a_proposal_to_record_which_funding_covers_this_project') }}</p>
            </div>
        @else
            <ul class="dk-rows">
                @foreach ($this->attachedProposals as $proposal)
                    @php
                        $isOpen = $proposal->id === $this->embeddedProposalId;
                    @endphp
                    <li @class(['dk-row2', 'dk-row-on' => $isOpen]) wire:key="project-proposal-{{ $proposal->id }}">
                        <div>
                            @if ($this->canViewProposals())
                                <a href="{{ $this->proposalStoryUrl($proposal->id) }}" wire:navigate data-story-proposal-link><strong dir="auto">{{ $proposal->name }}</strong></a>
                            @else
                                <strong dir="auto">{{ $proposal->name }}</strong>
                            @endif
                            <span class="dk-is">
                                <x-ui.project-number :number="$proposal->internal_project_number" kind="internal" />
                                · {{ $proposal->phase->label() }}
                                @if ($proposal->donorNames->isNotEmpty())
                                    · {{ $proposal->donorNames->unique('id')->pluck('name')->implode(', ') }}
                                @endif
                            </span>
                            @if ($proposal->planned_date_begin !== null)
                                <span class="dk-is dk-num">{{ $proposal->planned_date_begin->translatedFormat(__('formats.date')) }} – {{ $proposal->planned_date_end?->translatedFormat(__('formats.date')) }}</span>
                            @endif
                        </div>
                        <div class="dk-row2-r">
                            <x-ui.enum-badge :case="$proposal->status" />
                            @foreach ($this->proposalFunding($proposal) as $row)
                                <span class="dk-num dk-small" dir="ltr" wire:key="proposal-{{ $proposal->id }}-funding-{{ $row['currency']->value }}">
                                    <x-ui.money :amount="(float) $row['received']" :currency="$row['currency']" /> / <x-ui.money :amount="(float) $row['committed']" :currency="$row['currency']" />
                                </span>
                            @endforeach
                            @if ($isOpen)
                                <span class="dk-pill dk-act">{{ __('story.open_below') }}</span>
                            @endif
                            @can('proposals.update')
                                <button type="button" class="dk-iconbtn dk-sm" wire:click="detachProposal({{ $proposal->id }})" aria-label="{{ __('projects.unlink_name', ['name' => $proposal->name]) }}" title="{{ __('projects.unlink_name', ['name' => $proposal->name]) }}"><x-desk.icon name="x" /></button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @can('proposals.update')
            <div class="dk-card-f dk-story-link" data-test="story-link-proposal">
                <x-ui.select :label="__('projects.link_a_proposal')" wire:model="proposalToAttach">
                    <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                    @foreach ($this->attachableProposals as $proposalOption)
                        <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</flux:select.option>
                    @endforeach
                </x-ui.select>
                <button type="button" class="dk-btn dk-sm" wire:click="attachProposal"><x-desk.icon name="plus" />{{ __('projects.link') }}</button>
            </div>
        @endcan
    </div>

    {{-- Funding history, oldest first, as a table. --}}
    @if ($this->fundingHistory->isNotEmpty())
        <div class="dk-card dk-span-12">
            <div class="dk-card-h"><h3>{{ __('projects.funding_history') }}</h3><span class="dk-meta">{{ __('projects.funding_history_hint') }}</span></div>
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
    @endif

    {{-- The chosen proposal, in full, without leaving the project. --}}
    @if ($this->embeddedProposalId !== null)
        <div class="dk-card dk-span-12 dk-pjprop">
            @php
                $i = 2;
                $state = 'now';
            @endphp
            <div class="dk-card-b">
                @include('pages.projects._story-details')
                @include('pages.projects._story-proposal')
            </div>
        </div>
    @endif
</div>
