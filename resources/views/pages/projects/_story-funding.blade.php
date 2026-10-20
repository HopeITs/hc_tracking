{{-- Donor decision chapter: the funding envelope and the donors behind it (moved from the old show page). --}}
<div @class(['grid gap-4', '@3xl/detail:grid-cols-2' => $this->canViewMoney()]) data-test="story-funding">
        @if ($this->canViewMoney())
            <x-ui.panel :title="__('projects.funding_envelope')">
                @if ($this->fundingByCurrency->isEmpty())
                    <x-ui.empty-state icon="banknotes" :heading="__('projects.not_funded_yet')" :body="__('projects.link_a_proposal_to_record_which_funding_covers_this_project')" />
                @else
                    <div class="flex flex-col gap-4 p-3.5">
                        @foreach ($this->fundingByCurrency as $row)
                            <x-ui.capacity-bar :committed="$row['committed']" :used="$row['received']" :currency="$row['currency']" wire:key="funding-{{ $row['currency']->value }}" />
                        @endforeach
                    </div>
                @endif
            </x-ui.panel>
        @endif

        <x-ui.panel :title="__('projects.funding_sources')" :count="$this->fundingSources->count()">
            @if ($this->fundingSources->isEmpty())
                <x-ui.empty-state icon="building-library" :heading="__('projects.no_donor_approved_funding_yet')" :body="__('projects.funding_becomes_official_when_a_donor_approves_a_proposal')" />
            @else
                <ul class="flex flex-col divide-y divide-secondary-soft dark:divide-line" data-funding-sources>
                    @foreach ($this->fundingSources as $source)
                        <li class="flex items-start justify-between gap-3 p-3.5" wire:key="funding-source-{{ $source->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-secondary-dark dark:text-ink">{{ $source->donor->name }}</p>
                                <p class="truncate text-xs text-secondary-light">{{ $source->name }} · {{ $source->status->label() }}</p>
                            </div>
                            @if ($this->canViewAgreements() && $source->donorProposals->isNotEmpty())
                                @foreach ($source->donorProposals as $agreement)
                                    <span class="shrink-0 text-sm font-semibold tabular-nums" dir="ltr">{{ number_format((float) $agreement->budget, 2) }} {{ $agreement->currency->value }}</span>
                                @endforeach
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
</div>
