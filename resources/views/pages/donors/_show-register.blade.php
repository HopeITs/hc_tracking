{{--
    The Table view of the donor page: the totals per currency, one row per
    agreement, every tranche and the donor reports still owed. Included by
    ⚡show.blade.php, which defines $amount, $formatDate, $mutedText, $inkText
    and $hairline.
--}}
@use('App\Models\DonorProposal')
            @php
                $canTransfers = auth()->user()->can('transfers.view');
                $agreementRows = $this->agreementRows;
                $columns = \App\Support\AgreementMoney::columns(withTransfers: $canTransfers);

                $totalLines = collect($this->moneyByCurrency)->map(fn (array $line, string $lineCurrency): array => [
                    'currency' => $lineCurrency,
                    'caption' => null,
                    'figures' => array_values(array_filter([
                        ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                        $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                        $canTransfers ? ['key' => 'due', 'label' => __('donors.due_ahead'), 'value' => $line['due'], 'tone' => $line['due'] > 0 ? 'warn' : null] : null,
                        $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                        $canTransfers && $line['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $line['failed'], 'tone' => 'critical'] : null,
                        $canTransfers ? ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'] : null,
                    ])),
                    'notes' => [],
                ])->values()->all();

                $owedReports = $this->agreements->flatMap(fn ($owingAgreement) => $owingAgreement->reports->map(fn ($owedReport): array => ['agreement' => $owingAgreement, 'report' => $owedReport]))->sortBy(fn (array $owed) => $owed['report']->due_on)->values();
            @endphp

            <div class="mt-9">
                <x-funding.totals :lines="$totalLines" />
                @foreach ($this->receivedElsewhere as $elsewhereCurrency => $elsewhereTotal)
                    <p class="mt-2 text-xs {{ $mutedText }}" wire:key="elsewhere-{{ $elsewhereCurrency }}">{{ __('donors.received_in_other_currency', ['currency' => $elsewhereCurrency, 'amount' => $amount($elsewhereTotal)]) }}</p>
                @endforeach
            </div>

            {{-- Every agreement this donor holds, one row each. --}}
            <section class="mt-10 min-w-0" aria-labelledby="agreements-heading" data-agreements>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="agreements-heading" class="text-lg font-bold {{ $inkText }}">
                        {{ __('common.funding_agreements_2') }}
                        <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $agreementRows->count() }}</span>
                    </h2>
                    @can('funding-agreements.create')
                    <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="recordAgreementForDonor" data-test="record-agreement-for-donor">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                    @endcan
                </div>

                <x-funding.register class="mt-4" :size="$canTransfers ? 'xl' : 'sm'" :caption="__('donors.agreements_and_what_they_fund')">
                    <x-slot:head>
                        <x-funding.register.th>{{ __('common.proposal') }}</x-funding.register.th>
                        <x-funding.agreement-head :columns="$columns" />
                    </x-slot:head>

                    @foreach ($agreementRows as $agreementRow)
                        <x-funding.agreement-row :agreement="$agreementRow" :columns="$columns">
                            <x-slot:lead>
                                <td data-label="{{ __('common.proposal') }}" data-wide>
                                    @can('proposals.view')
                                        <a href="{{ route('proposals.show', $agreementRow->proposal) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreementRow->proposal->name }}</a>
                                    @else
                                        <span class="font-semibold">{{ $agreementRow->proposal->name }}</span>
                                    @endcan
                                    <span class="sub">{{ $agreementRow->proposal->status->label() }} · {{ $agreementRow->external_project_number ?? __('common.no_external_project_number') }}</span>
                                </td>
                            </x-slot:lead>

                            <x-slot:actions>
                                <td>
                                    <span class="flex items-center justify-end">
                                        <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('funding-agreements.show', $agreementRow)" wire:navigate :aria-label="__('donors.view_agreement_for', ['name' => $agreementRow->proposal->name])" />
                                        @can('funding-agreements.update')
                                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openAgreementEdit({{ $agreementRow->id }})" :aria-label="__('common.edit_name', ['name' => $agreementRow->proposal->name])" data-test="edit-agreement-{{ $agreementRow->id }}" />
                                        @endcan
                                    </span>
                                </td>
                            </x-slot:actions>
                        </x-funding.agreement-row>
                    @endforeach
                </x-funding.register>
            </section>

            {{-- Every tranche, in full. Nothing to open. --}}
            @if ($canTransfers)
                <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="all-tranches-heading" data-all-tranches>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="all-tranches-heading" class="text-lg font-bold {{ $inkText }}">
                            {{ __('finance_tables.all_tranches') }}
                            <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ $this->allTranches->count() }}</span>
                        </h2>
                        @if ($this->allTranches->isNotEmpty())
                            <x-ui.button size="sm" icon="arrow-down-tray" class="min-h-10 touch-manipulation" wire:click="exportTranchesCsv" wire:loading.attr="disabled" wire:target="exportTranchesCsv" data-test="export-csv">{{ __('finance_tables.export_csv') }}</x-ui.button>
                        @endif
                    </div>

                    @if ($this->allTranches->isEmpty())
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('donors.no_dated_tranches') }}</p>
                    @else
                        <x-funding.tranche-table class="mt-4" size="xl" :transfers="$this->allTranches" :caption="__('finance_tables.all_tranches')" party="proposal" />
                    @endif
                </section>
            @endif

            {{-- What this donor is still owed back. --}}
            @if ($owedReports->isNotEmpty())
                <section class="mt-10 min-w-0 border-t pt-8 {{ $hairline }}" aria-labelledby="owed-reports-heading" data-owed-donor-reports>
                    <h2 id="owed-reports-heading" class="text-lg font-bold {{ $inkText }}">
                        {{ trans_choice('donors.count_reports_owed_to_donor', $owedReports->count(), ['count' => $owedReports->count()]) }}
                    </h2>

                    <x-funding.register class="mt-4" size="sm" :caption="__('funding_agreements.donor_reports')">
                        <x-slot:head>
                            <x-funding.register.th>{{ __('funding_agreements.report_title') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('common.type') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('common.proposal') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('funding_agreements.due') }}</x-funding.register.th>
                            <x-funding.register.th>{{ __('common.state') }}</x-funding.register.th>
                        </x-slot:head>

                        @foreach ($owedReports as $owed)
                            <tr wire:key="owed-report-{{ $owed['report']->id }}" data-owed-donor-report="{{ $owed['report']->id }}">
                                <td data-label="{{ __('funding_agreements.report_title') }}" data-wide>
                                    <a href="{{ route('funding-agreements.show', $owed['agreement']) }}#reports-heading" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $owed['report']->title }}</a>
                                </td>
                                <td data-label="{{ __('common.type') }}">{{ $owed['report']->type->label() }}</td>
                                <td data-label="{{ __('common.proposal') }}">{{ $owed['agreement']->proposal->name }}</td>
                                <td class="date" data-label="{{ __('funding_agreements.due') }}">{{ $formatDate($owed['report']->due_on) }}</td>
                                <td data-label="{{ __('common.state') }}">
                                    <span @class(['text-xs font-semibold', 'text-status-critical' => $owed['report']->isOverdue(), $mutedText => ! $owed['report']->isOverdue()])>
                                        {{ $owed['report']->isOverdue() ? __('donors.report_overdue_since', ['date' => $formatDate($owed['report']->due_on)]) : __('donors.report_due_on', ['date' => $formatDate($owed['report']->due_on)]) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </x-funding.register>
                </section>
            @endif

            <div class="pb-10"></div>
