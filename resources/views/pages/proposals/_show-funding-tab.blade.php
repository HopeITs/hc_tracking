{{--
    The Funding tab of proposals/show: the view toggle, the overview
    (agreements, tranches, chronology) and the agreement and tranche tables.
    Included by ⚡show.blade.php, whose top PHP block defines $mutedText,
    $inkText, $hairline, $amount, $term, $chronology and $arrivals.
--}}
@use('App\Enums\ArStatus')
@use('App\Models\DonorProposal')
@use('App\Models\TransfersTracking')
@use('App\Support\AgreementMoney')
            <div class="mb-6 flex flex-wrap items-center justify-end gap-2">
                <x-ui.view-toggle
                    class="me-auto"
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
                @can('transfers.create')
                <x-ui.button size="sm" icon="calendar-days" class="min-h-10 touch-manipulation" wire:click="openTransferCreate" :disabled="$this->agreements->isEmpty()" data-test="schedule-tranche">{{ __('proposals.schedule_tranche') }}</x-ui.button>
                @endcan
                @can('funding-agreements.create')
                <x-ui.button tone="primary" size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="openAgreementCreate" data-test="record-agreement">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                @endcan
            </div>

            @if ($this->view === 'overview')
            @if ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('proposals.no_funding_agreements')" :body="__('proposals.agreements_signed_against_this_proposal_will_appear_here')" />
            @else
                @if (count($this->moneyByCurrency) > 1)
                    <p class="mb-5 text-xs {{ $mutedText }}">{{ __('proposals.budgets_are_shown_per_currency_they_are_never_added_together') }}</p>
                @endif

                <div class="flex flex-col gap-8">
                    @foreach ($this->agreements as $agreement)
                        @php
                            $money = $this->agreementMoney[$agreement->id];
                            $budget = $money['budget'];
                            $currency = $agreement->currency->value;
                            $agreementReceived = $money['received'];
                            $agreementPending = $money['pending'];
                        @endphp
                        <article class="min-w-0" wire:key="agreement-{{ $agreement->id }}" data-agreement="{{ $agreement->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <p class="min-w-0 text-sm">
                                    <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->donor->name }}</a>
                                    <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="ms-2 text-xs underline-offset-4 hover:underline {{ $mutedText }}">{{ filled($agreement->external_project_number) ? __('proposals.agreement_number', ['number' => $agreement->external_project_number]) : __('proposals.view_agreement') }}</a>
                                </p>
                                <div class="flex items-center gap-1">
                                    <p class="me-2 text-2xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $amount($budget) }} <span class="text-xs font-semibold tracking-normal {{ $mutedText }}">{{ $currency }}</span></p>
                                    @can('transfers.create')
                                    <x-ui.button tone="ghost" size="sm" icon="plus" class="size-9! touch-manipulation" wire:click="openTransferCreate({{ $agreement->id }})" :aria-label="__('proposals.schedule_tranche_for', ['donor' => $agreement->donor->name])" data-test="schedule-tranche-{{ $agreement->id }}" />
                                    @endcan
                                    @can('funding-agreements.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openAgreementEdit({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreement->donor->name])" data-test="edit-agreement-{{ $agreement->id }}" />
                                    @endcan
                                </div>
                            </div>

                            <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $currency, 'received' => $amount($agreementReceived), 'pending' => $amount($agreementPending), 'committed' => $amount($budget)]) }}">
                                <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $budget > 0 ? min(100, round($agreementReceived / $budget * 100, 2)) : 0 }}%"></span>
                                <span class="money-grow money-pending h-full" style="width: {{ $budget > 0 ? min(100, round($agreementPending / $budget * 100, 2)) : 0 }}%"></span>
                            </div>

                            @if ($money['foreignCount'] > 0)
                                <p class="mt-2 text-xs {{ $mutedText }}" data-foreign-tranches>{{ trans_choice('proposals.count_tranches_in_another_currency', $money['foreignCount'], ['count' => $money['foreignCount'], 'currency' => $currency]) }}</p>
                            @endif

                            @can('transfers.view')
                            @if ($agreement->transfers->isEmpty())
                                <p class="mt-3 text-sm {{ $mutedText }}">{{ __('proposals.committed_with_no_transfer_yet') }}</p>
                            @else
                                <ol class="mt-4 flex flex-col">
                                    {{-- Dated tranches in date order; undated ones have no place on the calendar yet, so they sit last. --}}
                                    @foreach ($agreement->transfers->sortBy([
                                        fn (TransfersTracking $a, TransfersTracking $b): int => (($a->receive_date ?? $a->scheduled_date) === null) <=> (($b->receive_date ?? $b->scheduled_date) === null),
                                        fn (TransfersTracking $a, TransfersTracking $b): int => ($a->receive_date ?? $a->scheduled_date) <=> ($b->receive_date ?? $b->scheduled_date),
                                        fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
                                    ]) as $transfer)
                                        @php
                                            $isReceived = $transfer->ar_status === ArStatus::Received;
                                            $isOverdue = $this->transferIsOverdue($transfer);
                                            $shownAmount = (float) ($isReceived ? $transfer->net_amount_received : $transfer->payment_amount);
                                            $shownCurrency = ($isReceived ? $transfer->amount_received_currency : $transfer->amount_transfered_currency)?->value ?? $currency;
                                        @endphp
                                        <li class="relative flex min-w-0 gap-3 pb-4 last:pb-0" wire:key="transfer-{{ $transfer->id }}" data-transfer="{{ $transfer->id }}">
                                            <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                                @unless ($loop->last)
                                                    <span class="absolute top-3 bottom-[-1rem] w-px bg-secondary-soft dark:bg-line-strong"></span>
                                                @endunless
                                                <span @class([
                                                    'relative z-10 mt-1.5 size-2.5 rounded-full',
                                                    'bg-funding' => $isReceived,
                                                    'bg-primary dark:bg-status-critical' => ! $isReceived && ($isOverdue || $transfer->ar_status === ArStatus::Failed),
                                                    'border-2 border-status-warn bg-white dark:bg-surface-1' => ! $isReceived && ! $isOverdue && $transfer->ar_status !== ArStatus::Failed,
                                                ])></span>
                                            </span>

                                            <div class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold tabular-nums {{ $inkText }}">
                                                        {{ $amount($shownAmount) }} <span class="text-xs font-medium {{ $mutedText }}">{{ $shownCurrency }}</span>
                                                    </p>
                                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                                        @if ($transfer->receive_date !== null)
                                                            <span>{{ __('proposals.received_on', ['date' => $transfer->receive_date->translatedFormat(__('formats.date'))]) }}</span>
                                                        @elseif ($transfer->transfer_date !== null)
                                                            <span>{{ __('proposals.sent_on', ['date' => $transfer->transfer_date->translatedFormat(__('formats.date'))]) }}</span>
                                                        @elseif ($transfer->scheduled_date !== null)
                                                            <span @class(['font-semibold text-status-critical' => $isOverdue])>{{ __('proposals.scheduled_for', ['date' => $transfer->scheduled_date->translatedFormat(__('formats.date'))]) }}</span>
                                                        @else
                                                            <span>{{ __('common.unscheduled') }}</span>
                                                        @endif
                                                        <span aria-hidden="true">·</span>
                                                        <span>{{ $transfer->account?->account_name ?? __('proposals.no_account_recorded') }}</span>
                                                        @if ((float) $transfer->fees > 0)
                                                            <span aria-hidden="true">·</span>
                                                            <span>{{ __('proposals.fees_amount', ['amount' => $amount((float) $transfer->fees), 'currency' => $transfer->fees_currency?->value ?? $currency]) }}</span>
                                                        @endif
                                                    </p>
                                                </div>

                                                <div class="flex shrink-0 items-center gap-2">
                                                    @if ($isOverdue)
                                                        <span class="text-[11px] font-bold tracking-wide text-status-critical uppercase">{{ __('proposals.overdue') }}</span>
                                                    @elseif ($transfer->state() === 'transit')
                                                        <span class="text-[11px] font-bold tracking-wide text-status-warn uppercase" data-transit>{{ __('funding_agreements.tranche_state_transit') }}</span>
                                                    @elseif ($transfer->isMissingNetAmount())
                                                        <span class="text-[11px] font-bold tracking-wide text-status-warn uppercase" data-missing-net>{{ __('funding_agreements.received_net_not_recorded') }}</span>
                                                    @endif
                                                    <x-ui.enum-badge :case="$transfer->ar_status" />
                                                    @can('transfers.update')
                                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9! touch-manipulation" wire:click="openTransferEdit({{ $transfer->id }})" :aria-label="__('proposals.edit_tranche')" data-test="edit-transfer-{{ $transfer->id }}" />
                                                    @endcan
                                                </div>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                            @endcan
                        </article>
                    @endforeach
                </div>

                <div class="mt-8 flex flex-col gap-5 border-t pt-5 {{ $hairline }}">
                    @foreach ($this->moneyByCurrency as $currencyCode => $line)
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-4 @2xl/detail:grid-cols-[3.5rem_repeat(4,minmax(0,1fr))]" wire:key="money-line-{{ $currencyCode }}">
                            <p class="col-span-2 text-sm font-extrabold tracking-wide text-funding @2xl/detail:col-span-1">{{ $currencyCode }}</p>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.received') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums text-funding">{{ $amount($line['received']) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.pending') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums text-status-warn">{{ $amount($line['pending']) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.not_yet_scheduled') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums {{ $mutedText }}">{{ $amount($line['unscheduled']) }}</dd>
                            </div>
                            <div>
                                <dt class="type-label {{ $mutedText }}">{{ __('proposals.committed') }}</dt>
                                <dd class="mt-1 text-lg font-bold tabular-nums {{ $inkText }}">{{ $amount($line['committed']) }}</dd>
                            </div>
                        </dl>
                    @endforeach
                </div>
            @endif

            @include('pages.proposals._show-chronology')
            @elseif ($this->agreements->isEmpty())
                <x-ui.empty-state icon="banknotes" :heading="__('proposals.no_funding_agreements')" :body="__('proposals.agreements_signed_against_this_proposal_will_appear_here')" />
            @else
                @php
                    $canTransfers = $this->canViewTransfers();
                    $agreementRows = $this->agreementRows;
                    $fundingColumns = AgreementMoney::columns(withTransfers: $canTransfers);

                    $fundingTotalLines = collect($this->moneyByCurrency)->map(fn (array $line, string $lineCurrency): array => [
                        'currency' => $lineCurrency,
                        'caption' => null,
                        'figures' => array_values(array_filter([
                            ['key' => 'committed', 'label' => __('proposals.committed'), 'value' => $line['committed']],
                            $canTransfers ? ['key' => 'received', 'label' => __('proposals.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                            $canTransfers ? ['key' => 'pending', 'label' => __('proposals.pending'), 'value' => $line['pending'], 'tone' => $line['pending'] > 0 ? 'warn' : null] : null,
                            $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                            $canTransfers ? ['key' => 'unscheduled', 'label' => __('proposals.not_yet_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'] : null,
                        ])),
                        'notes' => [],
                    ])->values()->all();
                @endphp

                <x-funding.totals :lines="$fundingTotalLines" />

                {{-- Every agreement on this proposal, one row each. --}}
                <x-funding.register class="mt-6" :size="$canTransfers ? 'xl' : 'sm'" :caption="__('common.funding_agreements_2')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('funding_agreements.donor') }}</x-funding.register.th>
                        <x-funding.agreement-head :columns="$fundingColumns" />
                    </x-slot:head>

                    @foreach ($agreementRows as $agreementRow)
                        <x-funding.agreement-row :agreement="$agreementRow" :columns="$fundingColumns">
                            <x-slot:lead>
                                <td data-label="{{ __('funding_agreements.donor') }}" data-wide>
                                    @can('donors.view')
                                        <a href="{{ route('donors.show', $agreementRow->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreementRow->donor->name }}</a>
                                    @else
                                        <span class="font-semibold">{{ $agreementRow->donor->name }}</span>
                                    @endcan
                                    <span class="sub">{{ $agreementRow->external_project_number ?? __('common.no_external_project_number') }}</span>
                                </td>
                            </x-slot:lead>

                            <x-slot:actions>
                                <td>
                                    <span class="flex items-center justify-end">
                                        <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('funding-agreements.show', $agreementRow)" wire:navigate :aria-label="__('proposals.view_agreement')" />
                                        @can('transfers.create')
                                            <x-ui.button tone="ghost" size="sm" icon="plus" class="size-8!" wire:click="openTransferCreate({{ $agreementRow->id }})" :aria-label="__('proposals.schedule_tranche_for', ['donor' => $agreementRow->donor->name])" data-test="schedule-tranche-{{ $agreementRow->id }}" />
                                        @endcan
                                        @can('funding-agreements.update')
                                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openAgreementEdit({{ $agreementRow->id }})" :aria-label="__('common.edit_name', ['name' => $agreementRow->donor->name])" data-test="edit-agreement-{{ $agreementRow->id }}" />
                                        @endcan
                                    </span>
                                </td>
                            </x-slot:actions>
                        </x-funding.agreement-row>
                    @endforeach
                </x-funding.register>

                {{-- Every tranche, in full. --}}
                @if ($canTransfers)
                    <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="funding-tranches-heading" data-funding-tranches>
                        <h2 id="funding-tranches-heading" class="text-lg font-bold {{ $inkText }}">
                            {{ __('finance_tables.all_tranches') }}
                            <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->fundingTranches->count() }}</span>
                        </h2>

                        @if ($this->fundingTranches->isEmpty())
                            <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.committed_with_no_transfer_yet') }}</p>
                        @else
                            <x-funding.tranche-table class="mt-4" size="xl" :transfers="$this->fundingTranches" :caption="__('finance_tables.all_tranches')" party="donor" editable />
                        @endif
                    </section>
                @endif
            @endif
