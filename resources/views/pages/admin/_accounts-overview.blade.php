{{--
    The Overview view of the accounts page: the summary tiles, the state
    donut, the funds received per currency and the account cards. Included
    by ⚡accounts.blade.php; reads $this, $search, $state and $currencyFilter.
--}}
@php
    $summary = $this->summary;
    $fundsByCurrency = $this->fundsByCurrency;
    $moneyStats = $this->accountMoneyStats;

    // Same rotation cities/⚡index.blade.php uses so the grid reads as lively
    // rather than uniform tiles — never the primary red, reserved for
    // late/critical signal elsewhere in the app.
    $avatarTones = [
        'bg-funding-soft text-funding',
        'bg-status-active-soft text-status-active',
        'bg-status-good-soft text-status-good',
        'bg-status-warn-soft text-status-warn',
    ];

    $stateChart = [
        'series' => [$summary['active'], $summary['closed']],
        'categories' => [__('accounts.open'), __('accounts.closed')],
        'tones' => ['good', 'muted'],
    ];
@endphp

            <div class="grid grid-cols-1 gap-4 @2xl/directory:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div class="grid grid-cols-2 gap-3 @sm/directory:grid-cols-4 @2xl/directory:grid-cols-2">
                    <x-ui.stat-tile :label="__('common.accounts')" :value="$summary['total']" icon="building-library" />
                    <x-ui.stat-tile :label="__('accounts.open_accounts')" :value="$summary['active']" tone="good" icon="lock-open" />
                    <x-ui.stat-tile :label="__('accounts.closed_accounts')" :value="$summary['closed']" icon="lock-closed" />
                    <x-ui.stat-tile :label="__('accounts.total_transfers')" :value="$summary['transfers']" tone="funding" icon="arrows-right-left" />
                </div>

                <x-ui.panel :title="__('accounts.funds_received')" :subtitle="__('accounts.funds_received_hint')" class="min-w-0">
                    <div class="flex flex-col gap-4 p-4 @xl/directory:flex-row @xl/directory:items-center">
                        <x-ui.chart
                            type="donut"
                            :label="__('accounts.accounts_by_state')"
                            :series="$stateChart['series']"
                            :categories="$stateChart['categories']"
                            :tones="$stateChart['tones']"
                            :total-label="__('common.accounts')"
                            :height="140"
                            class="mx-auto shrink-0 @xl/directory:mx-0"
                            style="width: 140px"
                        />

                        <div class="min-w-0 flex-1 space-y-2">
                            @if ($fundsByCurrency->isEmpty())
                                <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('accounts.no_funds_received_yet') }}</p>
                            @else
                                @foreach ($fundsByCurrency as $currency => $total)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-funding-soft px-3 py-2" wire:key="currency-total-{{ $currency }}">
                                        <span class="type-meta font-semibold text-funding">{{ __('ui.received') }}</span>
                                        <x-ui.money :amount="$total" :currency="$currency" class="text-funding [&>span:first-child]:text-base" />
                                    </div>
                                @endforeach
                            @endif

                            <div class="flex items-center gap-4 pt-1">
                                <span class="flex items-center gap-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                                    <span class="size-2 shrink-0 rounded-full bg-status-good" aria-hidden="true"></span>
                                    {{ __('accounts.open') }} ({{ $summary['active'] }})
                                </span>
                                <span class="flex items-center gap-1.5 type-meta text-secondary-medium dark:text-ink-muted">
                                    <span class="size-2 shrink-0 rounded-full bg-secondary-light dark:bg-ink-faint" aria-hidden="true"></span>
                                    {{ __('accounts.closed') }} ({{ $summary['closed'] }})
                                </span>
                            </div>
                        </div>
                    </div>
                </x-ui.panel>
            </div>

    @if ($this->accounts->isEmpty())
        <x-ui.empty-state
            icon="building-library"
            :heading="$search !== '' || $state !== '' || $currencyFilter !== '' ? __('accounts.no_matching_accounts') : __('accounts.no_accounts_yet')"
            :body="$search !== '' || $state !== '' || $currencyFilter !== '' ? __('common.change_the_search_or_filters_and_try_again') : __('accounts.open_the_first_account')"
            class="rounded-xl border border-secondary-soft dark:border-line"
        />
    @else
        <div class="grid grid-cols-1 gap-4 @sm/directory:grid-cols-2 @4xl/directory:grid-cols-3 @7xl/directory:grid-cols-4">
            @foreach ($this->accounts as $index => $account)
                @php
                    $avatarTone = $avatarTones[$account->id % count($avatarTones)];
                    $currencyTotals = $moneyStats->get($account->id, collect());
                    $lastTransferAt = $account->last_transfer_at !== null ? \Carbon\CarbonImmutable::parse($account->last_transfer_at) : null;
                @endphp
                <article
                    wire:key="account-{{ $account->id }}"
                    class="logframe-reveal group relative flex flex-col gap-4 overflow-hidden rounded-xl border border-secondary-soft bg-white p-4 shadow-card transition-all hover:-translate-y-0.5 hover:border-secondary-light hover:shadow-card-hover dark:border-transparent dark:bg-surface-1 dark:shadow-raised dark:hover:shadow-raised"
                    style="--logframe-order: {{ min($index, 11) }}"
                >
                    <span
                        aria-hidden="true"
                        class="{{ $account->is_active ? 'bg-status-good' : 'bg-secondary-light dark:bg-ink-faint' }} absolute inset-x-0 top-0 h-1"
                    ></span>

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="{{ $avatarTone }} flex size-11 shrink-0 items-center justify-center rounded-xl">
                                <flux:icon.building-library class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-base font-bold text-secondary-dark dark:text-ink">{{ $account->account_name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">{{ $account->bank_name }} · {{ $account->currency->value }} · <span dir="ltr" data-test="account-number">{{ $this->showsFullAccountNumbers ? $account->account_number : $account->maskedAccountNumber() }}</span></p>
                                <p class="truncate text-xs tabular-nums text-secondary-medium dark:text-ink-muted">
                                    @if ($account->opened_at !== null)
                                        {{ __('accounts.opened_date', ['date' => $account->opened_at->translatedFormat(__('formats.date'))]) }}
                                    @else
                                        {{ __('accounts.opening_date_unknown') }}
                                    @endif
                                    @if ($account->closed_at !== null)
                                        · {{ __('accounts.closed_date', ['date' => $account->closed_at->translatedFormat(__('formats.date'))]) }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                            'bg-status-good-soft text-status-good' => $account->is_active,
                            'bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted' => ! $account->is_active,
                        ])>
                            {{ $account->is_active ? __('accounts.open') : __('accounts.closed') }}
                        </span>
                    </div>

                    <div class="min-w-0 space-y-1.5">
                        @if ($currencyTotals->isEmpty())
                            <p class="type-meta text-secondary-light italic dark:text-ink-faint">{{ __('accounts.no_transfers_yet') }}</p>
                        @else
                            @foreach ($currencyTotals as $currency => $total)
                                <div class="flex items-center justify-between gap-2 rounded-lg bg-secondary-soft px-2.5 py-1.5 dark:bg-surface-2" wire:key="account-{{ $account->id }}-currency-{{ $currency }}">
                                    <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('ui.received') }}</span>
                                    <x-ui.money :amount="$total" :currency="$currency" />
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-secondary-soft pt-3 dark:border-line">
                        <div class="min-w-0">
                            <a href="{{ route('transfers.index', ['account' => $account->id]) }}" wire:navigate class="type-meta font-semibold text-secondary-medium underline-offset-4 hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                                {{ trans_choice('accounts.count_transfer', $account->transfers_count, ['count' => $account->transfers_count]) }}
                            </a>
                            @if ($lastTransferAt !== null)
                                <p class="type-meta text-secondary-light dark:text-ink-faint">{{ __('accounts.last_transfer_date', ['date' => $lastTransferAt->translatedFormat(__('formats.date'))]) }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('transfers.index', ['account' => $account->id])" wire:navigate :aria-label="__('accounts.view_transfers_for_name', ['name' => $account->account_name])" />
                            @can('accounts.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('common.edit_name', ['name' => $account->account_name])" />
                            @endcan

                            {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. Closing needs accounts.delete. --}}
                            @if ($account->is_active && auth()->user()->can('accounts.delete'))
                                <x-ui.button tone="ghost" size="sm" icon="lock-closed" class="size-9!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('accounts.close_name', ['name' => $account->account_name])" />
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <flux:pagination
            :paginator="$this->accounts"
            class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-ink-muted! dark:[&_button]:text-ink-muted! dark:[&_button:hover]:bg-surface-2!"
            scroll-to="#account-directory-heading"
        />
    @endif
