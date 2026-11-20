{{--
    The proposal card (EditsProposalCard): Title + See more, then
    Start · End | Sent · Approved | Donor, every field edited in place.
    The logical framework sits inside this card from stage 3.
--}}
@php
    $cardProposal = $this->proposal;
    $dateText = fn ($date): ?string => $date?->translatedFormat(__('formats.date'));
    $extendedTo = $cardProposal->extended_date_end;
@endphp

<article class="pp-record" id="card" data-test="proposal-card">
    <div class="pp-title-row">
        @include('pages.proposals._show-card-field', ['field' => 'title', 'display' => $cardProposal->name, 'big' => true])
        <button type="button" class="pp-see-more" wire:click="$toggle('cardMore')" aria-expanded="{{ $cardMore ? 'true' : 'false' }}" aria-controls="pp-more" data-test="card-see-more">
            {{ $cardMore ? __('proposals.page.see_less') : __('proposals.page.see_more') }}
            <svg @class(['pp-chev', 'pp-chev-up' => $cardMore]) width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
    </div>

    @if ($cardMore)
        @php
            $programNames = collect($this->locationRows)->pluck('program')->filter()->unique()->implode(', ');
            $places = collect($this->locationRows)->pluck('city')->filter()->unique()->implode(', ');
            $months = $cardProposal->planned_date_begin !== null && ($extendedTo ?? $cardProposal->planned_date_end) !== null
                ? (int) round($cardProposal->planned_date_begin->diffInDays($extendedTo ?? $cardProposal->planned_date_end) / 30.44)
                : null;
        @endphp
        <div class="pp-fold" id="pp-more" data-test="card-more">
            <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_number') }}</div><div class="pp-v" dir="ltr">{{ $cardProposal->internal_project_number ?: '—' }}</div></div>
            <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_kind') }}</div><div class="pp-v">{{ $cardProposal->kind->label() }}</div></div>
            <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_program') }}</div><div class="pp-v">{{ $programNames ?: '—' }}</div></div>
            @if ($this->canViewAgreements())
                <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_budget') }}</div><div class="pp-v tabular-nums">
                    @if ($cardProposal->requested_budget === null)
                        —
                    @else
                        {{ number_format((float) $cardProposal->requested_budget, 0) }}
                        {{ $cardProposal->requested_currency?->value ?? __('proposals.page.no_currency_chosen') }}
                    @endif
                </div></div>
            @endif
            <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_duration') }}</div><div class="pp-v">{{ $months === null ? '—' : trans_choice('proposals.page.months', $months, ['count' => $months]) }}</div></div>
            <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_extended_to') }}</div><div class="pp-v">{{ $dateText($extendedTo) ?? '—' }}</div></div>
            <div class="pp-kv"><div class="pp-k">{{ __('proposals.page.kv_locations') }}</div><div class="pp-v">{{ $places ?: '—' }}</div></div>
            <div class="pp-kv pp-kv-wide"><div class="pp-k">{{ __('proposals.page.kv_summary') }}</div><div class="pp-v" dir="auto">{{ $cardProposal->notes ?: __('proposals.page.no_summary') }}</div></div>
            @if ($this->canEditCardField('title'))
                <div class="pp-fold-acts">
                    <button type="button" class="pp-btn pp-btn-sm" wire:click="openEditModal({{ $cardProposal->id }})">{{ __('proposals.edit_details') }}</button>
                </div>
            @endif
        </div>
    @endif

    <div class="pp-dates">
        <div class="pp-pair">
            @include('pages.proposals._show-card-field', ['field' => 'start', 'display' => $dateText($cardProposal->planned_date_begin)])
            @include('pages.proposals._show-card-field', ['field' => 'end', 'display' => $dateText($cardProposal->planned_date_end), 'note' => $extendedTo !== null ? __('proposals.page.extended_to', ['date' => $dateText($extendedTo)]) : null])
        </div>
        <div class="pp-pair">
            @include('pages.proposals._show-card-field', ['field' => 'sent', 'display' => $dateText($this->cardDates['sent'])])
            @include('pages.proposals._show-card-field', ['field' => 'approved', 'display' => $dateText($this->cardDates['approved'])])
        </div>
        <div>
            @include('pages.proposals._show-card-field', ['field' => 'donor', 'display' => $cardProposal->donor?->name])
        </div>
    </div>

    @if ($cardProposal->isPayroll())
        <section class="pp-lf" aria-labelledby="lf-h">
            <div class="pp-lf-top">
                <div class="pp-lf-title"><div><h2 id="lf-h">{{ __('proposals.framework.title') }}</h2><p>{{ __('proposals.framework.payroll_none') }}</p></div></div>
            </div>
        </section>
    @else
        <livewire:proposals.logframe :proposal-id="$cardProposal->id" :key="'proposal-logframe-'.$cardProposal->id" />
    @endif
</article>
