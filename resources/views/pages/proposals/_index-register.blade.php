{{--
    The register table of proposals/index: totals, the sortable money table
    and its pager. Included by ⚡index.blade.php when the table view has rows.
--}}
@use('App\Models\DonorProposal')
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $today = \Carbon\CarbonImmutable::today();
            $showsYearColumns = $selectedYear !== null && ! $yearOnly;
            $unreadIds = $this->unreadIds;

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('finance_tables.count_proposals', $line['proposals'], ['count' => $line['proposals']]),
                'figures' => array_values(array_filter([
                    ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                    $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                    $showsYearColumns ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $showsYearColumns ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $canTransfers && ! $yearOnly ? ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']] : null,
                    $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                ])),
            ])->all();

            $previousProposalId = null;
        @endphp

        @if ($canAgreements)
            <x-funding.totals :lines="$totalLines" />
        @endif

        <x-funding.register :size="$canAgreements ? '2xl' : 'md'" :caption="__('finance_tables.proposals_caption')">
            <x-slot:head>
                <x-funding.register.th sort="proposal" :active="$sortKey" :dir="$sortDirection">{{ __('common.proposal') }}</x-funding.register.th>
                <x-funding.register.th sort="status" :active="$sortKey" :dir="$sortDirection">{{ __('common.status') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('proposals.phase') }}</x-funding.register.th>
                <x-funding.register.th sort="planned_start" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.planned_start') }}</x-funding.register.th>
                <x-funding.register.th sort="planned_end" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.planned_end') }}</x-funding.register.th>
                <x-funding.register.th sort="projects" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('common.projects') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th>{{ __('common.donors') }}</x-funding.register.th>
                    <x-funding.register.th sort="agreements" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.agreements') }}</x-funding.register.th>
                    <x-funding.register.th>{{ __('common.currency') }}</x-funding.register.th>
                    <x-funding.register.th sort="committed" :active="$sortKey" :dir="$sortDirection" numeric>{{ $yearOnly ? __('finance_tables.committed_whole') : __('common.committed') }}</x-funding.register.th>
                @endif
                @if ($canTransfers)
                    <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                    <x-funding.register.th sort="percent" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.percent_received') }}</x-funding.register.th>
                    @if ($showsYearColumns)
                        <x-funding.register.th sort="due_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                        <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                    @endif
                    @unless ($yearOnly)
                        <x-funding.register.th sort="outstanding" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.outstanding') }}</x-funding.register.th>
                    @endunless
                    <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                    <x-funding.register.th sort="next_due" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.next_due') }}</x-funding.register.th>
                @endif
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $proposal)
                @php
                    // A proposal funded in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousProposalId === $proposal->id;
                    $previousProposalId = $proposal->id;
                    $lineCurrency = $canAgreements ? $proposal->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $lineAgreements = $hasMoney ? $proposal->donorProposals->filter(fn ($agreement): bool => $agreement->currency->value === $lineCurrency)->unique('donor_id') : collect();
                    $nextDue = $canTransfers && $hasMoney && $proposal->money_next_due !== null ? \Carbon\CarbonImmutable::parse($proposal->money_next_due) : null;
                    $overdue = $canTransfers && $hasMoney ? (float) $proposal->money_overdue : 0.0;
                @endphp
                <tr wire:key="proposal-{{ $proposal->id }}-{{ $lineCurrency ?? 'none' }}" data-proposal-row data-proposal="{{ $proposal->id }}">
                    <td data-label="{{ __('common.proposal') }}" data-wide>
                        @if (! $isRepeat && in_array($proposal->id, $unreadIds, true))
                            <span class="me-1 inline-block size-2 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot="proposal-{{ $proposal->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                        @endif
                        <span @class(['font-semibold', $mutedText => $isRepeat])>{{ $proposal->name }}</span>
                    </td>
                    <td data-label="{{ __('common.status') }}">
                        @unless ($isRepeat)
                            <x-ui.enum-badge :case="$proposal->status" />
                        @endunless
                    </td>
                    {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                    <td data-label="{{ __('proposals.phase') }}">{{ $isRepeat ? '' : $proposal->phase->label() }}</td>
                    <td class="date" data-label="{{ __('proposals.planned_start') }}">{{ $isRepeat ? '' : $date($proposal->planned_date_begin) }}</td>
                    <td class="date" data-label="{{ __('proposals.planned_end') }}">{{ $isRepeat ? '' : $date($proposal->planned_date_end) }}</td>
                    <td class="num" data-label="{{ __('common.projects') }}">
                        @unless ($isRepeat)
                            <span class="fig">{{ (int) $proposal->projects_count }}</span>
                        @endunless
                    </td>

                    @if ($canAgreements)
                        <td data-label="{{ __('common.donors') }}">
                            @forelse ($lineAgreements as $lineAgreement)
                                <a href="{{ route('donors.show', $lineAgreement->donor) }}" wire:navigate class="block underline-offset-2 hover:underline">{{ $lineAgreement->donor->name }}</a>
                            @empty
                                <span class="{{ $mutedText }}">{{ __('proposals.no_funding_agreements') }}</span>
                            @endforelse
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.agreements') }}"><span class="fig">{{ (int) $proposal->money_agreements }}</span></td>
                        <td data-label="{{ __('common.currency') }}">{{ $lineCurrency ?? '—' }}</td>
                        <td class="num" data-label="{{ __('common.committed') }}" data-cell="committed">
                            @if ($hasMoney)
                                <span class="fig font-semibold">{{ $money($proposal->money_committed) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    @if ($canTransfers)
                        <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                            @if ($hasMoney)
                                <span class="fig font-semibold text-funding">{{ $money($proposal->money_received) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="num" data-label="{{ __('finance_tables.percent_received') }}" data-cell="percent">
                            @if ($hasMoney)
                                <span class="fig">{{ DonorProposal::percentLabel((float) $proposal->money_received, (float) $proposal->money_committed) }}%</span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($showsYearColumns)
                            <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}" data-cell="due_in_year"><span class="fig">{{ $money($proposal->money_due_in_year) }}</span></td>
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($proposal->money_received_in_year) }}</span></td>
                        @endif
                        @unless ($yearOnly)
                            <td class="num" data-label="{{ __('finance_tables.outstanding') }}" data-cell="outstanding">
                                @if ($hasMoney)
                                    <span class="fig">{{ $money($proposal->money_outstanding) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        @endunless
                        <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue">
                            @if ($hasMoney)
                                <span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="date" data-label="{{ __('finance_tables.next_due') }}">
                            @if ($nextDue !== null)
                                <span @class(['font-semibold text-status-critical' => $nextDue->lt($today)])>{{ $date($nextDue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('proposals.show', $proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $proposal->name])" />
                                @can('update', $proposal)
                                    {{-- Status only moves with an audit note, so this opens the same form the dossier did. --}}
                                    <x-ui.button tone="ghost" size="sm" icon="arrows-right-left" icon:variant="outline" class="size-8!" wire:click="openStageModal({{ $proposal->id }})" :aria-label="__('proposals.change_status')" data-test="advance-proposal-{{ $proposal->id }}" />
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />
                                @endcan
                                @can('delete', $proposal)
                                    @if ($proposal->donor_proposals_count === 0)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-8!" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                                    @else
                                        <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                @endcan
                            </span>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#proposal-pipeline-heading" />
