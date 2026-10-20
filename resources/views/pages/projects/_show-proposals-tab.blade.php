{{--
    The side column's proposals block (desk.js sideBlocks): the linked
    proposals as a plain list, the link picker for proposal editors, and the
    funding history (names, numbers and statuses only, never money).
--}}
<div data-test="story-proposals">
    @if ($this->attachedProposals->isEmpty())
        <p class="dk-small"><strong>{{ __('projects.no_proposals_linked') }}</strong></p>
        <p class="dk-small dk-muted">{{ __('projects.link_a_proposal_to_record_which_funding_covers_this_project') }}</p>
    @else
        @php
            $summary = $this->proposalsSummary;
        @endphp
        <p class="dk-small dk-muted" data-test="story-proposals-summary">
            @if ($this->canViewMoney())
                <span class="dk-num">{{ $summary['donors'] }}</span> {{ __('projects.stat_donors_funding') }} ·
                <span class="dk-num">{{ $summary['agreements'] }}</span> {{ __('projects.stat_funding_agreements') }} ·
            @endif
            <span class="dk-num">{{ $summary['underway'] }}</span> {{ __('projects.stat_proposals_underway') }}
        </p>

        <ul class="dk-plist dk-tight">
            @foreach ($this->attachedProposals as $proposal)
                <li wire:key="project-proposal-{{ $proposal->id }}">
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
                            @if ($proposal->planned_date_begin !== null)
                                · <span class="dk-num">{{ $proposal->planned_date_begin->translatedFormat(__('formats.date')) }} – {{ $proposal->planned_date_end?->translatedFormat(__('formats.date')) }}</span>
                            @endif
                        </span>
                        @foreach ($this->proposalFunding($proposal) as $row)
                            <span class="dk-is dk-num" dir="ltr" wire:key="proposal-{{ $proposal->id }}-funding-{{ $row['currency']->value }}">
                                <x-ui.money :amount="(float) $row['received']" :currency="$row['currency']" /> / <x-ui.money :amount="(float) $row['committed']" :currency="$row['currency']" />
                            </span>
                        @endforeach
                    </div>
                    <span class="dk-pr-r">
                        <x-ui.enum-badge :case="$proposal->status" />
                        @can('proposals.update')
                            <button type="button" class="dk-iconbtn dk-sm" wire:click="detachProposal({{ $proposal->id }})" aria-label="{{ __('projects.unlink_name', ['name' => $proposal->name]) }}" title="{{ __('projects.unlink_name', ['name' => $proposal->name]) }}"><x-desk.icon name="x" /></button>
                        @endcan
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @can('proposals.update')
        <div class="dk-story-link" data-test="story-link-proposal">
            <x-ui.select :label="__('projects.link_a_proposal')" wire:model="proposalToAttach">
                <flux:select.option value="">{{ __('common.select_a_proposal') }}</flux:select.option>
                @foreach ($this->attachableProposals as $proposalOption)
                    <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }} · {{ $proposalOption->internal_project_number }}</flux:select.option>
                @endforeach
            </x-ui.select>
            <button type="button" class="dk-btn dk-sm" wire:click="attachProposal"><x-desk.icon name="plus" />{{ __('projects.link') }}</button>
        </div>
    @endcan

    @if ($this->fundingHistory->isNotEmpty())
        <p class="dk-fcat">{{ __('projects.funding_history') }}</p>
        <ol class="dk-fhist" data-test="funding-history">
            @foreach ($this->fundingHistory as $entry)
                <li wire:key="funding-history-{{ $entry->id }}">
                    <span class="dk-yr dk-num" dir="ltr">{{ $entry->planned_date_begin?->format('Y') ?? '—' }}@if ($entry->planned_date_end !== null && $entry->planned_date_end->format('Y') !== $entry->planned_date_begin?->format('Y'))–{{ $entry->planned_date_end->format('y') }}@endif</span>
                    <span>
                        @if ($this->canViewProposals())
                            <a href="{{ $this->attachedProposals->contains('id', $entry->id) ? $this->proposalStoryUrl($entry->id) : route('proposals.show', $entry) }}" wire:navigate dir="auto">{{ $entry->name }}</a>
                        @else
                            <span dir="auto">{{ $entry->name }}</span>
                        @endif
                        <span class="dk-is">
                            <x-ui.project-number :number="$entry->internal_project_number" kind="internal" />
                            · {{ $entry->donor->name ?? '—' }} · {{ $entry->status->label() }}
                            @if ($entry->actual_date_begin !== null)
                                · <span dir="ltr">{{ $entry->actual_date_begin->format('Y') }}–{{ $entry->actual_date_end?->format('Y') ?? '…' }}</span>
                            @endif
                        </span>
                        @if ($entry->previousProposal !== null)
                            <span class="dk-is" dir="auto">{{ __('projects.funding_history_renews', ['number' => $entry->previousProposal->internal_project_number]) }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ol>
        <p class="dk-small dk-muted">{{ __('projects.funding_history_hint') }}</p>
    @endif
</div>
