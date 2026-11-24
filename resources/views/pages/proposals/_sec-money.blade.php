{{--
    "Money" section of proposals/show (stage 3b): one line per currency, never
    added across currencies (signed, received, expected, late, not planned
    and a bar), then each agreement as a fold with its tranches. Every button
    opens the existing agreement or tranche dialog. Shown only to agreement
    viewers (FoldsProposalSections); tranche money only to transfer viewers.
    Included by _show-more.blade.php inside ⚡show's scope ($amount).
--}}
@use('App\Models\TransfersTracking')
@php
    $canTransfers = $this->canViewTransfers();
    $percentOf = fn (float $part, float $whole): float => $whole > 0 ? min(100, round($part / $whole * 100, 2)) : 0;
    $trancheStates = [
        'received' => ['label' => __('proposals.sections.tranche_received'), 'class' => 'pp-st-done'],
        'upcoming' => ['label' => __('proposals.sections.tranche_expected'), 'class' => ''],
        'transit' => ['label' => __('proposals.sections.tranche_sent'), 'class' => ''],
        'late' => ['label' => __('proposals.sections.tranche_late'), 'class' => 'pp-st-late'],
        'failed' => ['label' => __('proposals.sections.tranche_failed'), 'class' => 'pp-st-late'],
    ];
@endphp

@if ($this->agreements->isEmpty())
    <div class="pp-empty" data-money-empty>
        {{ __('proposals.sections.money_empty') }}
        @if ($this->canRecordAgreement)
            <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="openAgreementCreate" data-test="record-agreement">
                <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('funding_agreements.record_agreement') }}
            </button>
        @endif
    </div>
@else
    @foreach ($this->moneyByCurrency as $currency => $line)
        @php
            $expected = max(0.0, $line['pending'] - $line['overdue']);
        @endphp
        <div class="pp-moneyline" wire:key="moneyline-{{ $currency }}" data-money-line="{{ $currency }}">
            <b>{{ $currency }}</b>
            <div class="min-w-0">
                <div class="pp-figs">
                    <span>{{ __('proposals.sections.money_signed') }} <b>{{ $amount($line['committed']) }}</b></span>
                    @if ($canTransfers)
                        <span>{{ __('proposals.sections.money_received') }} <b>{{ $amount($line['received']) }}</b></span>
                        <span>{{ __('proposals.sections.money_expected') }} <b>{{ $amount($expected) }}</b></span>
                        <span>{{ __('proposals.sections.money_late') }} <b @class(['pp-red-text' => $line['overdue'] > 0])>{{ $amount($line['overdue']) }}</b></span>
                        @if (round($line['unscheduled'], 2) > 0)
                            <span>{{ __('proposals.sections.money_not_planned') }} <b class="pp-red-text">{{ $amount($line['unscheduled']) }}</b></span>
                        @endif
                    @endif
                </div>
                @if ($canTransfers)
                    <div class="pp-bigbar" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $currency, 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                        <i class="pp-i-ink" style="width: {{ $percentOf($line['received'], $line['committed']) }}%"></i>
                        <i class="pp-i-red" style="width: {{ $percentOf($line['overdue'], $line['committed']) }}%"></i>
                        <i class="pp-i-grey" style="width: {{ $percentOf($expected, $line['committed']) }}%"></i>
                    </div>
                @endif
            </div>
        </div>
    @endforeach

    @if ($canTransfers)
        <div class="pp-legend" aria-hidden="true">
            <span><i class="pp-i-ink"></i>{{ __('proposals.sections.money_received') }}</span>
            <span><i class="pp-i-red"></i>{{ __('proposals.sections.money_late') }}</span>
            <span><i class="pp-i-grey"></i>{{ __('proposals.sections.money_expected') }}</span>
            <span><i class="pp-i-soft"></i>{{ __('proposals.sections.money_not_planned_yet') }}</span>
        </div>
    @endif

    @foreach ($this->agreements as $agreement)
        @php
            $money = $this->agreementMoney[$agreement->id];
            $agreementCurrency = $agreement->currency->value;
            $tranches = $agreement->transfers->sortBy([
                fn (TransfersTracking $a, TransfersTracking $b): int => (($a->receive_date ?? $a->scheduled_date) === null) <=> (($b->receive_date ?? $b->scheduled_date) === null),
                fn (TransfersTracking $a, TransfersTracking $b): int => ($a->receive_date ?? $a->scheduled_date) <=> ($b->receive_date ?? $b->scheduled_date),
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
            ]);
            $arrived = $tranches->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'received')->count();
            $lateTranches = $tranches->filter(fn (TransfersTracking $transfer): bool => in_array($transfer->state(), ['late', 'failed'], true))->count();
        @endphp
        <details class="pp-sub" wire:ignore.self wire:key="agreement-{{ $agreement->id }}" data-agreement="{{ $agreement->id }}">
            <summary>
                <span class="pp-grow" dir="auto">{{ $agreement->donor->name }}</span>
                <span class="pp-small tabular-nums">{{ $amount((float) $agreement->budget) }} {{ $agreementCurrency }}</span>
                @if ($canTransfers)
                    <span class="pp-pill">{{ __('proposals.sections.money_arrived_of', ['arrived' => $arrived, 'total' => $tranches->count()]) }}</span>
                    @if ($lateTranches > 0)
                        <span class="pp-pill pp-pill-red">{{ trans_choice('proposals.sections.flag_late', $lateTranches, ['count' => $lateTranches]) }}</span>
                    @endif
                @endif
                <svg class="pp-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </summary>
            <div class="pp-sub-b">
                <x-proposals.partner-note :partner="$this->proposal->mainPartner" for="all" class="pp-small block" />
                <div class="pp-small pp-row-links">
                    <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="pp-link">{{ filled($agreement->external_project_number) ? __('proposals.agreement_number', ['number' => $agreement->external_project_number]) : __('proposals.view_agreement') }}</a>
                    @can('donors.view')
                        <span aria-hidden="true">·</span>
                        <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="pp-link-mute" dir="auto">{{ $agreement->donor->name }}</a>
                    @endcan
                    @if ($money['foreignCount'] > 0)
                        <span aria-hidden="true">·</span>
                        <span data-foreign-tranches>{{ trans_choice('proposals.count_tranches_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount'], 'currency' => $agreementCurrency]) }}</span>
                    @endif
                </div>

                @if ($canTransfers)
                    @forelse ($tranches as $transfer)
                        @php
                            $state = $transfer->state();
                            $isReceived = $state === 'received';
                            $shownAmount = (float) ($isReceived ? ($transfer->net_amount_received ?? $transfer->amount_received) : $transfer->payment_amount);
                            $shownCurrency = ($isReceived ? $transfer->amount_received_currency : $transfer->amount_transfered_currency)?->value ?? $agreementCurrency;
                            $when = match (true) {
                                $transfer->receive_date !== null => __('proposals.sections.tranche_arrived_on', ['date' => $transfer->receive_date->translatedFormat(__('formats.date'))]),
                                $transfer->transfer_date !== null => __('proposals.sections.tranche_sent_on', ['date' => $transfer->transfer_date->translatedFormat(__('formats.date'))]),
                                $transfer->scheduled_date !== null => __('proposals.sections.tranche_due_on', ['date' => $transfer->scheduled_date->translatedFormat(__('formats.date'))]),
                                default => __('proposals.sections.tranche_no_date'),
                            };
                        @endphp
                        <div class="pp-lrow" wire:key="transfer-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}" data-tranche-state="{{ $state }}">
                            <span class="pp-lrow-s">
                                <b class="tabular-nums">{{ $amount($shownAmount) }} {{ $shownCurrency }}</b>
                                <span class="pp-small">
                                    {{ $when }} · {{ $transfer->account?->account_name ?? __('proposals.no_account_recorded') }}
                                    @if ($transfer->isMissingNetAmount())
                                        · <span class="pp-red-text" data-missing-net>{{ __('funding_agreements.received_net_not_recorded') }}</span>
                                    @endif
                                </span>
                            </span>
                            <span class="pp-lrow-r">
                                @can('transfers.update')
                                    <button type="button" class="pp-status {{ $trancheStates[$state]['class'] }}" wire:click="openTransferEdit({{ $transfer->id }})" aria-label="{{ __('proposals.edit_tranche') }}: {{ $trancheStates[$state]['label'] }}" data-test="edit-transfer-{{ $transfer->id }}">{{ $trancheStates[$state]['label'] }}</button>
                                @else
                                    <span class="pp-status {{ $trancheStates[$state]['class'] }}">{{ $trancheStates[$state]['label'] }}</span>
                                @endcan
                            </span>
                        </div>
                    @empty
                        <span class="pp-small">{{ __('proposals.committed_with_no_transfer_yet') }}</span>
                    @endforelse
                @endif

                <div class="pp-row-acts">
                    @can('transfers.create')
                        <button type="button" class="pp-btn pp-btn-sm" wire:click="openTransferCreate({{ $agreement->id }})" data-test="schedule-tranche-{{ $agreement->id }}">
                            <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('proposals.schedule_tranche') }}
                        </button>
                    @endcan
                    @can('funding-agreements.update')
                        <button type="button" class="pp-btn pp-btn-sm" wire:click="openAgreementEdit({{ $agreement->id }})" data-test="edit-agreement-{{ $agreement->id }}">{{ __('funding_agreements.edit_agreement') }}</button>
                    @endcan
                </div>
            </div>
        </details>
    @endforeach
@endif
