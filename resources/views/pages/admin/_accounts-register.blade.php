{{--
    The Table view of the accounts page: the totals strip, the register and
    its pager. Included by ⚡accounts.blade.php; reads $this, $selectedYear,
    $chosen, $sortKey, $sortDirection, $showsFullNumbers and $mutedText.
--}}
        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => __('finance_tables.count_accounts_open', ['count' => $line['accounts'], 'open' => $line['open_accounts']]),
                'figures' => array_values(array_filter([
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good'],
                    $selectedYear !== null && $chosen['figures'] === 'lifetime' ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $line['in_transit'], 'tone' => $line['in_transit'] > 0 ? 'warn' : null],
                    ['key' => 'upcoming', 'label' => __('finance_tables.upcoming'), 'value' => $line['upcoming']],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null],
                ])),
            ])->all();

            $showsYearColumn = $selectedYear !== null && $chosen['figures'] === 'lifetime';
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="xl" :caption="__('finance_tables.accounts_caption')">
            <x-slot:head>
                <x-funding.register.th sort="account" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.account_name') }}</x-funding.register.th>
                <x-funding.register.th sort="bank" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.bank_name') }}</x-funding.register.th>
                <x-funding.register.th sort="country" :active="$sortKey" :dir="$sortDirection">{{ __('donors.country') }}</x-funding.register.th>
                <x-funding.register.th sort="currency" :active="$sortKey" :dir="$sortDirection">{{ __('common.currency') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('accounts.account_number') }}</x-funding.register.th>
                <x-funding.register.th sort="state" :active="$sortKey" :dir="$sortDirection">{{ __('common.state') }}</x-funding.register.th>
                <x-funding.register.th sort="opened" :active="$sortKey" :dir="$sortDirection">{{ __('accounts.opened_on') }}</x-funding.register.th>
                <x-funding.register.th sort="closed" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.closed_on') }}</x-funding.register.th>
                <x-funding.register.th sort="transfers" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('common.transfers') }}</x-funding.register.th>
                <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                @if ($showsYearColumn)
                    <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                @endif
                <x-funding.register.th sort="in_transit" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.in_transit') }}</x-funding.register.th>
                <x-funding.register.th sort="upcoming" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.upcoming') }}</x-funding.register.th>
                <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                <x-funding.register.th sort="last_receipt" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.last_receipt') }}</x-funding.register.th>
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $account)
                @php
                    $lastReceipt = $account->money_last_receipt !== null ? \Carbon\CarbonImmutable::parse($account->money_last_receipt) : null;
                    $overdue = (float) $account->money_overdue;
                    $otherCurrency = (int) $account->money_other_currency;
                @endphp
                <tr wire:key="account-{{ $account->id }}" data-account-row="{{ $account->id }}">
                    <td data-label="{{ __('accounts.account_name') }}" data-wide><span class="font-semibold">{{ $account->account_name }}</span></td>
                    <td data-label="{{ __('accounts.bank_name') }}">{{ $account->bank_name }}</td>
                    <td data-label="{{ __('donors.country') }}">{{ $account->country }}</td>
                    <td data-label="{{ __('common.currency') }}">{{ $account->currency->value }}</td>
                    <td data-label="{{ __('accounts.account_number') }}"><span class="fig" data-test="account-number">{{ $showsFullNumbers ? $account->account_number : $account->maskedAccountNumber() }}</span></td>
                    <td data-label="{{ __('common.state') }}">
                        <span @class([
                            'inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap',
                            'text-status-good' => $account->is_active,
                            $mutedText => ! $account->is_active,
                        ])>
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-status-good' => $account->is_active, 'bg-secondary-light dark:bg-ink-faint' => ! $account->is_active]) aria-hidden="true"></span>
                            {{ $account->is_active ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                    </td>
                    <td class="date" data-label="{{ __('accounts.opened_on') }}">{{ $date($account->opened_at) }}</td>
                    <td class="date" data-label="{{ __('finance_tables.closed_on') }}">{{ $date($account->closed_at) }}</td>
                    <td class="num" data-label="{{ __('common.transfers') }}">
                        @can('transfers.view')
                            <a href="{{ route('transfers.index', ['account' => $account->id]) }}" wire:navigate class="fig font-semibold underline underline-offset-2">{{ (int) $account->money_transfers }}</a>
                        @else
                            <span class="fig">{{ (int) $account->money_transfers }}</span>
                        @endcan
                    </td>
                    <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                        <span class="fig font-semibold text-funding">{{ $money($account->money_received) }}</span>
                        @if ($otherCurrency > 0)
                            {{-- Counted, never converted: the transfers table shows each one in the currency it landed in. --}}
                            <span class="sub" data-other-currency>{{ trans_choice('finance_tables.count_landed_in_another_currency', $otherCurrency, ['count' => $otherCurrency]) }}</span>
                        @endif
                    </td>
                    @if ($showsYearColumn)
                        <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($account->money_received_in_year) }}</span></td>
                    @endif
                    <td class="num" data-label="{{ __('finance_tables.in_transit') }}" data-cell="in_transit"><span class="fig">{{ $money($account->money_in_transit) }}</span></td>
                    <td class="num" data-label="{{ __('finance_tables.upcoming') }}" data-cell="upcoming"><span class="fig">{{ $money($account->money_upcoming) }}</span></td>
                    <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue"><span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span></td>
                    <td class="date" data-label="{{ __('finance_tables.last_receipt') }}">{{ $date($lastReceipt) }}</td>
                    <td>
                        <span class="flex items-center justify-end">
                            @can('transfers.view')
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('transfers.index', ['account' => $account->id])" wire:navigate :aria-label="__('accounts.view_transfers_for_name', ['name' => $account->account_name])" />
                            @endcan
                            @can('accounts.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('common.edit_name', ['name' => $account->account_name])" />
                            @endcan

                            {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. Closing needs accounts.delete. --}}
                            @if ($account->is_active && auth()->user()->can('accounts.delete'))
                                <x-ui.button tone="ghost" size="sm" icon="lock-closed" icon:variant="outline" class="size-8!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('accounts.close_name', ['name' => $account->account_name])" />
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#account-directory-heading" />
