{{--
    The side column's funding block (desk.js sideBlocks): received of
    committed per currency for money holders, then the donors behind it as a
    plain list. Amounts are read only for funding-agreements.view /
    transfers.view holders (fundingByCurrency() and fundingSources() skip
    the query for everyone else).
--}}
<div data-test="story-funding">
    @if ($this->canViewMoney())
        <p class="dk-fcat">{{ __('projects.funding_envelope') }}</p>
        @if ($this->fundingByCurrency->isEmpty())
            <p class="dk-small"><strong>{{ __('projects.not_funded_yet') }}</strong></p>
            <p class="dk-small dk-muted">{{ __('projects.link_a_proposal_to_record_which_funding_covers_this_project') }}</p>
        @else
            <div class="dk-money" data-funding-envelope>
                @foreach ($this->fundingByCurrency as $row)
                    <div class="dk-mrow" wire:key="funding-{{ $row['currency']->value }}">
                        <span class="dk-cur">{{ $row['currency']->value }}</span>
                        <div class="dk-fig dk-rec"><strong class="dk-num"><x-ui.money :amount="(float) $row['received']" :currency="$row['currency']" /></strong><span>{{ __('ui.received') }}</span></div>
                        <div class="dk-fig"><strong class="dk-num"><x-ui.money :amount="(float) $row['committed']" :currency="$row['currency']" /></strong><span>{{ __('common.committed') }}</span></div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    <p class="dk-fcat">{{ __('projects.funding_sources') }} <span class="dk-num dk-muted">{{ $this->fundingSources->count() }}</span></p>
    @if ($this->fundingSources->isEmpty())
        <p class="dk-small"><strong>{{ __('projects.no_donor_approved_funding_yet') }}</strong></p>
        <p class="dk-small dk-muted">{{ __('projects.funding_becomes_official_when_a_donor_approves_a_proposal') }}</p>
    @else
        <ul class="dk-plist dk-tight" data-funding-sources>
            @foreach ($this->fundingSources as $source)
                <li wire:key="funding-source-{{ $source->id }}">
                    <div>
                        <strong dir="auto">{{ $source->donor->name }}</strong>
                        <span class="dk-is" dir="auto">{{ $source->name }} · {{ $source->status->label() }}</span>
                    </div>
                    @if ($this->canViewAgreements() && $source->donorProposals->isNotEmpty())
                        <span class="dk-pr-r dk-num dk-small" dir="ltr">
                            @foreach ($source->donorProposals as $agreement)
                                <span>{{ number_format((float) $agreement->budget, 2) }} {{ $agreement->currency->value }}</span>
                            @endforeach
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
