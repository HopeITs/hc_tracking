<?php

use App\Support\Like;
use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.transfers')] class extends Component {
    use ManagesTransferForm;
    use WithPagination;

    /**
     * How the ladder may be ranked. `attention` leads with money that has
     * misbehaved, which is the job this page exists for.
     *
     * @var list<string>
     */
    public const SORTS = ['attention', 'shortfall', 'date', 'amount'];

    /**
     * Where a tranche's money can be stuck, in the vocabulary the funding
     * pages share, plus the two conditions only this page can see: money that
     * landed short of what was sent, and money recorded as received without
     * anyone saying what actually arrived.
     *
     * @var list<string>
     */
    public const STAGES = ['overdue', 'transit', 'upcoming', 'failed', 'short', 'unrecorded'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $arStatus = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: 'attention')]
    public string $sort = 'attention';

    /**
     * One funding agreement to narrow the ledger to, set by the "see in the
     * transfers ledger" link on an agreement page.
     */
    #[Url(except: '')]
    public string $agreement = '';

    /**
     * Deep link from a notification's "Mark received": opens that tranche's
     * edit form once, then clears itself.
     */
    #[Url(as: 'edit', except: '')]
    public string $editTransfer = '';

    /**
     * `sort` and `stage` arrive from the URL, so a hand-typed value would
     * otherwise leave the page sorted by `attention` with no pill pressed, or
     * showing "Reset filters" with nothing filtered. Normalise on the way in.
     */
    public function mount(): void
    {
        if (! in_array($this->sort, self::SORTS, true)) {
            $this->sort = 'attention';
        }

        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = '';
        }

        if (ctype_digit($this->editTransfer)) {
            $this->openTransferEdit((int) $this->editTransfer);
        }

        $this->editTransfer = '';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArStatus(): void
    {
        $this->resetPage();
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->arStatus = '';
        $this->account = '';
        $this->stage = '';
        $this->agreement = '';
        $this->resetPage();
    }

    /**
     * The agreement the ledger is narrowed to, for the filter chip.
     */
    #[Computed]
    public function agreementFilter(): ?DonorProposal
    {
        $agreementId = (int) $this->agreement;

        return $agreementId > 0
            ? DonorProposal::query()->with(['donor:id,name', 'proposal:id,name'])->find($agreementId, ['id', 'donor_id', 'proposal_id', 'project_num'])
            : null;
    }

    /**
     * Narrow the ladder to one stage, or clear it by choosing it again.
     */
    public function toggleStage(string $stage): void
    {
        $this->stage = $this->stage === $stage || ! in_array($stage, self::STAGES, true) ? '' : $stage;
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        $this->sort = in_array($sort, self::SORTS, true) ? $sort : 'attention';
        $this->resetPage();
    }

    /**
     * A tranche whose journey has finished and whose two ends can honestly be
     * compared: received, with a net amount recorded, in the same currency it
     * was sent in. Anything else has no shortfall, because subtracting a
     * figure in one currency from a figure in another is not arithmetic.
     */
    protected function settledSql(): string
    {
        return "(ar_status = 'received' and net_amount_received is not null and amount_received_currency = amount_transfered_currency)";
    }

    /**
     * Stage conditions as SQL, keyed by stage.
     *
     * `$today` is produced here, never by a visitor, so it interpolates safely
     * — the same approach the agreements ledger takes.
     *
     * @return array<string, string>
     */
    protected function stageSql(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $settled = $this->settledSql();

        return [
            'overdue' => "(ar_status = 'pending' and transfer_date is null and scheduled_date is not null and scheduled_date < '{$today}')",
            'transit' => "(ar_status = 'pending' and transfer_date is not null)",
            'upcoming' => "(ar_status = 'pending' and transfer_date is null and (scheduled_date is null or scheduled_date >= '{$today}'))",
            'failed' => "(ar_status = 'failed')",
            'short' => "({$settled} and payment_amount > 0 and net_amount_received < payment_amount)",
            'unrecorded' => "(ar_status = 'received' and net_amount_received is null)",
        ];
    }

    /**
     * Tranches matching every active filter, with no ordering and no eager
     * loads, so both the ledger aggregate and the paginated ladder can build
     * on it.
     *
     * The ledger passes $withStage false so its figures keep describing the
     * whole filtered set while a stage narrows only the ladder beneath it.
     *
     * @return Builder<TransfersTracking>
     */
    protected function filteredQuery(bool $withStage = true): Builder
    {
        $search = trim($this->search);
        $stages = $this->stageSql();

        return TransfersTracking::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHas('donorProposal', function (Builder $query) use ($search): void {
                    $query
                        ->where('project_num', 'like', Like::contains($search))
                        ->orWhereHas('donor', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)))
                        ->orWhereHas('proposal', fn (Builder $query) => $query->where('name', 'like', Like::contains($search)));
                });
            })
            ->when($this->arStatus !== '', fn (Builder $query) => $query->where('ar_status', $this->arStatus))
            ->when($this->account !== '', fn (Builder $query) => $query->where('account_id', (int) $this->account))
            ->when((int) $this->agreement > 0, fn (Builder $query) => $query->where('donor_proposal_id', (int) $this->agreement))
            ->when(
                $withStage && array_key_exists($this->stage, $stages),
                fn (Builder $query) => $query->whereRaw($stages[$this->stage]),
            );
    }

    /**
     * @param  Builder<TransfersTracking>  $query
     * @return Builder<TransfersTracking>
     */
    protected function applySort(Builder $query): Builder
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        $sorted = match ($this->sort) {
            // Proportional loss, so a small tranche bled dry outranks a large
            // one that lost a rounding error. Tranches with no comparable end
            // sort last rather than masquerading as losing nothing.
            'shortfall' => $query
                ->orderByRaw("case when {$settled} and payment_amount > 0 then (payment_amount - net_amount_received) / payment_amount else -1 end desc")
                ->orderByDesc('payment_amount'),
            'amount' => $query->orderByDesc('payment_amount'),
            'date' => $query->orderByRaw('scheduled_date is null, scheduled_date desc'),
            // Overdue first and oldest-overdue first inside it, then outright
            // failures, then money that landed short, then money still moving.
            default => $query
                ->orderByRaw("case
                    when {$stages['overdue']} then 0
                    when {$stages['failed']} then 1
                    when {$stages['short']} then 2
                    when {$stages['unrecorded']} then 3
                    when {$stages['transit']} then 4
                    else 5 end")
                ->orderByRaw('scheduled_date is null, scheduled_date asc'),
        };

        return $sorted->orderByDesc('id');
    }

    /** @return LengthAwarePaginator<int, TransfersTracking> */
    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        $query = $this->filteredQuery()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->with([
                'donorProposal:id,donor_id,proposal_id,project_num',
                'donorProposal.donor:id,name',
                'donorProposal.proposal:id,name',
                // Nullable: a tranche may be scheduled before an account exists.
                'account:id,account_name,is_active',
            ]);

        return $this->applySort($query)->paginate(20);
    }

    /**
     * One ledger line per currency money was sent in: what left the donor,
     * what actually landed, and what the journey took out of it.
     *
     * Every figure is a grouped aggregate so the line costs one query however
     * many tranches exist. Currencies are grouped, never added: a USD line and
     * a EUR line are two separate stories on the same page.
     *
     * Only settled tranches feed the shortfall. Money still travelling has not
     * lost anything yet, and saying otherwise would invent leakage.
     *
     * The shortfall is the sum of each tranche's own loss, never the
     * difference between two totals. A tranche that arrived over — a
     * favourable rate, a donor rounding up — is real, but it did not put the
     * money back into the tranche that lost it, so it is reported beside the
     * shortfall and never allowed to cancel it. Netting the two is how a page
     * ends up announcing "landed in full" over money that never arrived.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function ledger(): Collection
    {
        $settled = $this->settledSql();
        $stages = $this->stageSql();

        // Per tranche: what it lost, and how much of that loss the recorded
        // fee explains. Written as CASE rather than LEAST so the expression
        // does not depend on one database's scalar function set.
        $rowShortfall = "(case when {$settled} and net_amount_received < payment_amount then payment_amount - net_amount_received else 0 end)";
        $rowFees = "(case
            when not ({$settled}) then 0
            when net_amount_received >= payment_amount then 0
            when fees_currency <> amount_transfered_currency then 0
            when fees < payment_amount - net_amount_received then fees
            else payment_amount - net_amount_received end)";

        return $this->filteredQuery(withStage: false)
            ->selectRaw("
                amount_transfered_currency as currency,
                count(*) as tranches,
                coalesce(sum(payment_amount), 0) as expected,
                coalesce(sum(case when {$settled} then payment_amount else 0 end), 0) as settled_sent,
                coalesce(sum(case when {$settled} then net_amount_received else 0 end), 0) as landed,
                coalesce(sum({$rowShortfall}), 0) as shortfall,
                coalesce(sum({$rowFees}), 0) as fees,
                coalesce(sum(case when {$settled} and net_amount_received > payment_amount then net_amount_received - payment_amount else 0 end), 0) as overage,
                coalesce(sum(case when {$stages['overdue']} then payment_amount else 0 end), 0) as overdue,
                sum(case when {$stages['overdue']} then 1 else 0 end) as overdue_count,
                coalesce(sum(case when {$stages['transit']} then payment_amount else 0 end), 0) as transit,
                sum(case when {$stages['transit']} then 1 else 0 end) as transit_count,
                coalesce(sum(case when {$stages['upcoming']} then payment_amount else 0 end), 0) as upcoming,
                sum(case when {$stages['upcoming']} then 1 else 0 end) as upcoming_count,
                coalesce(sum(case when {$stages['failed']} then payment_amount else 0 end), 0) as failed,
                sum(case when {$stages['failed']} then 1 else 0 end) as failed_count,
                sum(case when {$stages['short']} then 1 else 0 end) as short_count,
                sum(case when {$stages['unrecorded']} then 1 else 0 end) as unrecorded_count,
                coalesce(sum(case when {$stages['unrecorded']} then payment_amount else 0 end), 0) as unrecorded_amount,
                sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then 1 else 0 end) as converted_count,
                coalesce(sum(case when ar_status = 'received' and amount_received_currency <> amount_transfered_currency then payment_amount else 0 end), 0) as converted_amount,
                coalesce(sum(case when transfer_date is not null then payment_amount else 0 end), 0) as actually_sent
            ")
            ->groupBy('amount_transfered_currency')
            ->orderByRaw('sum(payment_amount) desc')
            ->get()
            ->map(function (TransfersTracking $row): array {
                $settledSent = (float) $row->getAttribute('settled_sent');
                $landed = (float) $row->getAttribute('landed');

                // Already summed per tranche in SQL, so a tranche that arrived
                // over cannot hide one that arrived short. Fees are named
                // first because they are the part someone recorded; whatever
                // is left is the exchange and the unexplained, and it is not
                // padded to look tidy.
                $shrink = (float) $row->getAttribute('shortfall');
                $feesTaken = (float) $row->getAttribute('fees');

                return [
                    'currency' => (string) $row->getAttribute('currency'),
                    'tranches' => (int) $row->getAttribute('tranches'),
                    // Everything this currency's tranches add up to, sent or not.
                    // Never labelled "sent": most of it may not have left yet.
                    'expected' => (float) $row->getAttribute('expected'),
                    // Only what actually left the donor, i.e. has a transfer date.
                    'sent' => (float) $row->getAttribute('actually_sent'),
                    'settledSent' => $settledSent,
                    'landed' => $landed,
                    'shrink' => $shrink,
                    'fees' => $feesTaken,
                    'exchangeGap' => max(0.0, $shrink - $feesTaken),
                    'overage' => (float) $row->getAttribute('overage'),
                    // Everything whose journey has not finished: still to be sent,
                    // moving, failed, converted, or received without a net amount.
                    'inFlight' => max(0.0, (float) $row->getAttribute('expected') - $settledSent),
                    'overdue' => (float) $row->getAttribute('overdue'),
                    'overdueCount' => (int) $row->getAttribute('overdue_count'),
                    'transit' => (float) $row->getAttribute('transit'),
                    'transitCount' => (int) $row->getAttribute('transit_count'),
                    'upcoming' => (float) $row->getAttribute('upcoming'),
                    'upcomingCount' => (int) $row->getAttribute('upcoming_count'),
                    'failed' => (float) $row->getAttribute('failed'),
                    'failedCount' => (int) $row->getAttribute('failed_count'),
                    'shortCount' => (int) $row->getAttribute('short_count'),
                    'unrecordedCount' => (int) $row->getAttribute('unrecorded_count'),
                    'unrecordedAmount' => (float) $row->getAttribute('unrecorded_amount'),
                    'convertedCount' => (int) $row->getAttribute('converted_count'),
                    'convertedAmount' => (float) $row->getAttribute('converted_amount'),
                ];
            });
    }

    /**
     * Whether any tranche exists at all, so an empty page can tell the
     * difference between "nothing recorded yet" and "nothing matches".
     */
    #[Computed]
    public function hasAnyTransfer(): bool
    {
        return TransfersTracking::query()->exists();
    }

    /**
     * A ledger narrowed to one agreement opens the new tranche on it, with its
     * currency, as recording one from the agreement page would.
     */
    public function openCreateModal(): void
    {
        $agreementId = (int) $this->agreement;

        $this->openTransferCreate($agreementId > 0 && DonorProposal::query()->whereKey($agreementId)->exists() ? $agreementId : null);
    }

    public function openEditModal(int $transferId): void
    {
        $this->openTransferEdit($transferId);
    }

    protected function transferSaved(TransfersTracking $transfer, bool $isCreate): void
    {
        $this->resetPage();
    }

    protected function transferDeleted(int $transferId): void
    {
        $this->resetPage();
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $arStatus !== '' || $account !== '' || $stage !== '' || $agreement !== '';

    $mutedText = 'text-secondary-medium dark:text-secondary-soft';
    $inkText = 'text-secondary-dark dark:text-white';
    $hairline = 'border-secondary-soft dark:border-secondary-medium';

    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $exact = fn (float $value): string => number_format($value, 2);
    /**
     * A percentage that never rounds a real loss down to zero. A 30 USD fee on
     * a 100,000 USD tranche is 0.03% — the common case in this domain — and
     * printing it as a red "−0%" reads as a broken page, not as a small loss.
     */
    $percent = function (float $part, float $whole): string {
        if ($whole <= 0) {
            return '0';
        }

        $value = $part / $whole * 100;
        $formatted = Number::format($value, maxPrecision: 1);

        return $value > 0 && (float) str_replace(',', '', $formatted) === 0.0
            ? __('transfers.under_a_tenth_of_a_percent')
            : $formatted;
    };

    /**
     * Whole units, except for an amount that would otherwise round to zero:
     * a shortfall of 0.40 is still a shortfall and must not print as "0".
     */
    $amountAtLeast = fn (float $value): string => $value > 0 && $value < 1
        ? number_format($value, 2)
        : Number::format($value, maxPrecision: 0);

    /**
     * An exchange rate with its trailing zeros dropped. Trimming the raw
     * decimal:6 string would turn a rate of 0 into an empty cell.
     */
    $rate = function (string $value): string {
        $trimmed = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    };

    $sortOptions = [
        'attention' => __('transfers.sort_attention'),
        'shortfall' => __('transfers.sort_shortfall'),
        'date' => __('transfers.sort_date'),
        'amount' => __('transfers.sort_amount'),
    ];
@endphp

<section
    class="@container/ladder flex min-h-full w-full flex-1 flex-col gap-6 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="transfer-ladder-heading"
>
    <x-ui.page-header
        heading-id="transfer-ladder-heading"
        :title="__('common.transfers')"
        :count="$this->transfers->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('transfers.search_transfers')" :placeholder="__('common.reference_donor_or_proposal')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('transfers.receipt_status')" wire:model.live="arStatus" :value="$arStatus">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (ArStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'ar-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('transfers.account')" wire:model.live="account" :value="$account">
                <flux:select.option value="">{{ __('common.all_accounts') }}</flux:select.option>
                @foreach ($this->accountOptions as $accountOption)
                    <flux:select.option :value="$accountOption->id" :wire:key="'tr-account-'.$accountOption->id">
                        {{ $accountOption->account_name }}@unless ($accountOption->is_active) · {{ __('common.closed') }}@endunless
                    </flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('transfers.create')
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/ladder:min-h-10" wire:click="openCreateModal" data-test="create-transfer-button">
                {{ __('transfers.record_transfer') }}
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $this->hasAnyTransfer)
        <x-ui.empty-state
            class="rtl:[&_svg]:-scale-x-100"
            icon="arrow-right-circle"
            :heading="__('transfers.no_transfers_recorded')"
            :body="__('transfers.record_the_first_tranche_against_a_funding_agreement')"
        />
    @else
        {{-- The ledger: per currency, what left the donor against what actually arrived. --}}
        <section aria-labelledby="ledger-heading" class="@container/ledger" data-ledger>
            <h2 id="ledger-heading" class="sr-only">{{ __('transfers.what_was_sent_against_what_landed') }}</h2>

            @forelse ($this->ledger as $line)
                @php
                    // The shrink hairline is drawn against everything sent, so
                    // money still travelling occupies its share as a ghost
                    // rather than silently making the loss look larger. A
                    // tranche that arrived over pushes the total past what was
                    // sent, so the scale takes whichever is larger.
                    $scale = max($line['expected'], $line['landed'] + $line['shrink'] + $line['inFlight'], 0.01);
                    $shortfallPercent = $percent($line['shrink'], $line['settledSent']);
                    // "Landed in full" may only be said when nothing is still
                    // outstanding. Otherwise it is the same lie the per-row
                    // netting produced, reached by a different route.
                    $everythingSettled = $line['inFlight'] <= 0;

                    $figures = [
                        [
                            'stage' => null,
                            'value' => $line['overage'],
                            'label' => __('transfers.arrived_over'),
                            'dot' => 'bg-funding',
                            'tone' => 'text-funding',
                            'note' => __('transfers.more_than_was_sent'),
                            'count' => $line['overage'] > 0 ? 1 : 0,
                        ],
                        // Fees and the exchange gap are two halves of the
                        // shortfall, not two places money is stuck. Filtering
                        // both to `short` made four controls that all did the
                        // same thing and lit up together. The shortfall figure
                        // itself is the control now; these two only report.
                        [
                            'stage' => null,
                            'value' => $line['fees'],
                            'label' => __('transfers.fees'),
                            'dot' => 'money-fees',
                            'tone' => 'text-status-warn',
                            'note' => __('transfers.percent_of_settled', ['percent' => $percent($line['fees'], $line['settledSent'])]),
                            'count' => $line['shortCount'],
                        ],
                        [
                            'stage' => null,
                            'value' => $line['exchangeGap'],
                            'label' => __('transfers.exchange_gap'),
                            'dot' => 'money-gap',
                            'tone' => $inkText,
                            'note' => __('transfers.percent_of_settled', ['percent' => $percent($line['exchangeGap'], $line['settledSent'])]),
                            'count' => $line['shortCount'],
                        ],
                        [
                            'stage' => 'overdue',
                            'value' => $line['overdue'],
                            'label' => __('transfers.stage_overdue'),
                            'dot' => 'bg-primary',
                            'tone' => 'text-status-critical',
                            'note' => trans_choice('transfers.count_tranches', $line['overdueCount'], ['count' => $line['overdueCount']]),
                            'count' => $line['overdueCount'],
                        ],
                        [
                            'stage' => 'transit',
                            'value' => $line['transit'],
                            'label' => __('transfers.stage_in_transit'),
                            'dot' => 'bg-status-warn',
                            'tone' => 'text-status-warn',
                            'note' => trans_choice('transfers.count_tranches', $line['transitCount'], ['count' => $line['transitCount']]),
                            'count' => $line['transitCount'],
                        ],
                        [
                            'stage' => 'upcoming',
                            'value' => $line['upcoming'],
                            'label' => __('transfers.stage_upcoming'),
                            'dot' => 'money-pending',
                            'tone' => $inkText,
                            'note' => trans_choice('transfers.count_tranches', $line['upcomingCount'], ['count' => $line['upcomingCount']]),
                            'count' => $line['upcomingCount'],
                        ],
                        [
                            'stage' => 'failed',
                            'value' => $line['failed'],
                            'label' => __('transfers.stage_failed'),
                            'dot' => 'money-failed',
                            'tone' => 'text-status-critical',
                            'note' => trans_choice('transfers.count_tranches', $line['failedCount'], ['count' => $line['failedCount']]),
                            'count' => $line['failedCount'],
                        ],
                    ];

                    // A figure earns its place when it has money behind it, or
                    // while it is the active filter. Nothing is shown at zero
                    // just to keep the row a tidy width.
                    $figures = array_values(array_filter(
                        $figures,
                        fn (array $figure): bool => $figure['value'] > 0 || $stage === $figure['stage'],
                    ));

                    $segments = [
                        ['key' => 'landed', 'stage' => null, 'value' => $line['landed'], 'fill' => 'bg-funding', 'label' => __('transfers.landed')],
                        ['key' => 'fees', 'stage' => 'short', 'value' => $line['fees'], 'fill' => 'money-fees', 'label' => __('transfers.fees')],
                        ['key' => 'gap', 'stage' => 'short', 'value' => $line['exchangeGap'], 'fill' => 'money-gap', 'label' => __('transfers.exchange_gap')],
                        ['key' => 'transit', 'stage' => 'transit', 'value' => $line['transit'], 'fill' => 'bg-status-warn', 'label' => __('transfers.stage_in_transit')],
                        ['key' => 'overdue', 'stage' => 'overdue', 'value' => $line['overdue'], 'fill' => 'bg-primary', 'label' => __('transfers.stage_overdue')],
                        ['key' => 'upcoming', 'stage' => 'upcoming', 'value' => $line['upcoming'], 'fill' => 'money-pending', 'label' => __('transfers.stage_upcoming')],
                        ['key' => 'failed', 'stage' => 'failed', 'value' => $line['failed'], 'fill' => 'money-failed', 'label' => __('transfers.stage_failed')],
                    ];
                @endphp

                <div
                    @class(['grid gap-y-5', 'mt-8 border-t pt-8 '.$hairline => ! $loop->first])
                    wire:key="ledger-{{ $line['currency'] }}"
                    data-ledger-currency="{{ $line['currency'] }}"
                >
                    {{-- Every figure is a label line, a number, then an optional note.
                         Aligned to the start, not the end: "Expected" carries a third
                         line, and bottom-aligning pushed its number above the two
                         beside it. Start-aligned, all the labels share one line and
                         all the figures share one baseline. --}}
                    <div class="grid gap-x-10 gap-y-6 @6xl/ledger:grid-cols-[auto_minmax(0,1fr)] @6xl/ledger:items-start">
                        <div class="flex flex-wrap items-start gap-x-7 gap-y-4 @xl/ledger:gap-x-10">
                            {{-- "Expected", not "Sent": most of this may not have left
                                 the donor yet, and the rows below say so themselves. --}}
                            <div class="min-w-0">
                                <p class="flex h-5 items-center text-xs font-semibold {{ $mutedText }}">
                                    {{ __('transfers.expected') }} · {{ trans_choice('transfers.count_tranches', $line['tranches'], ['count' => $line['tranches']]) }}
                                </p>
                                <p class="mt-1.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-4xl @xl/ledger:text-5xl {{ $inkText }}">
                                    <span x-data="countUp({{ (int) round($line['expected']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['expected']) }}</span><span class="ms-2 align-top text-sm font-bold tracking-normal {{ $mutedText }}">{{ $line['currency'] }}</span>
                                </p>
                                <p class="mt-1 text-[11px] tabular-nums {{ $mutedText }}">
                                    {{ __('transfers.of_which_sent', ['amount' => $amount($line['sent'])]) }}
                                </p>
                            </div>

                            <div class="min-w-0">
                                <p class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                    <span class="size-2 rounded-full bg-funding" aria-hidden="true"></span>{{ __('transfers.landed') }}
                                </p>
                                <p class="mt-1.5 flex items-baseline gap-2.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums text-funding @sm/ledger:text-4xl @xl/ledger:text-5xl" data-ledger-landed>
                                    <span x-data="countUp({{ (int) round($line['landed']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['landed']) }}</span>
                                </p>
                            </div>

                            {{-- The one figure this page exists to show, and the
                                 control that narrows the ladder to the tranches
                                 that produced it. --}}
                            @if ($line['settledSent'] > 0)
                                @php
                                    $shortfallIsActive = $stage === 'short';
                                @endphp
                                <div class="min-w-0">
                                    <button
                                        type="button"
                                        wire:click="toggleStage('short')"
                                        aria-pressed="{{ $shortfallIsActive ? 'true' : 'false' }}"
                                        @disabled($line['shrink'] <= 0)
                                        data-ledger-figure="short"
                                        @class([
                                            'group -m-2 rounded-lg p-2 text-start transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary enabled:hover:bg-board-head disabled:cursor-default dark:enabled:hover:bg-white/5',
                                            'bg-board-head ring-1 ring-secondary-dark dark:bg-white/5 dark:ring-white' => $shortfallIsActive,
                                        ])
                                    >
                                        <span class="flex h-5 items-center text-xs font-semibold {{ $mutedText }} group-enabled:group-hover:underline group-aria-pressed:underline">{{ __('transfers.shortfall') }}</span>
                                        <span @class([
                                            'mt-1.5 flex items-baseline gap-2 text-3xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-4xl @xl/ledger:text-5xl',
                                            'text-status-critical' => $line['shrink'] > 0,
                                            'text-funding' => $line['shrink'] <= 0,
                                        ]) data-ledger-shortfall>
                                            @if ($line['shrink'] > 0)
                                                −{{ $amountAtLeast($line['shrink']) }}<span class="text-lg font-bold tracking-normal">{{ $shortfallPercent }}%</span>
                                            @elseif ($everythingSettled)
                                                <span class="text-2xl @xl/ledger:text-3xl">{{ __('transfers.landed_in_full') }}</span>
                                            @else
                                                {{-- Nothing lost so far, but money is still out there. --}}
                                                <span class="text-2xl @xl/ledger:text-3xl">{{ __('transfers.settled_money_landed_in_full') }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Where the rest of it is. Each figure narrows the ladder below. --}}
                        @if ($figures !== [])
                            {{-- Wrapping rather than a fixed column count: a seven-digit
                                 figure in a sixth of the row overlaps its neighbour. --}}
                            <ul class="flex flex-wrap items-start gap-x-8 gap-y-4 @6xl/ledger:justify-end">
                                @foreach ($figures as $figure)
                                    @php
                                        $isActive = $figure['stage'] !== null && $stage === $figure['stage'];
                                        $isEmpty = $figure['count'] <= 0;
                                    @endphp
                                    <li class="min-w-0">
                                        @if ($figure['stage'] === null)
                                            {{-- Not a place money can be stuck, so not a filter. --}}
                                            <div class="p-2 -m-2">
                                                <span class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                                    <span class="size-2 shrink-0 rounded-full {{ $figure['dot'] }}" aria-hidden="true"></span>{{ $figure['label'] }}
                                                </span>
                                                <span class="mt-1.5 block text-xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-2xl {{ $figure['tone'] }}">{{ $amountAtLeast($figure['value']) }}</span>
                                                <span class="mt-1 block text-[11px] tabular-nums {{ $mutedText }}">{{ $figure['note'] }}</span>
                                            </div>
                                        @else
                                            <button
                                                type="button"
                                                wire:click="toggleStage('{{ $figure['stage'] }}')"
                                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                                @disabled($isEmpty && ! $isActive)
                                                @class([
                                                    'group -m-2 w-full rounded-lg p-2 text-start transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary enabled:hover:bg-board-head disabled:cursor-default dark:enabled:hover:bg-white/5',
                                                    'bg-board-head ring-1 ring-secondary-dark dark:bg-white/5 dark:ring-white' => $isActive,
                                                ])
                                                data-ledger-figure="{{ $figure['stage'] }}"
                                            >
                                                <span class="flex h-5 items-center gap-1.5 text-xs font-semibold {{ $mutedText }}">
                                                    <span class="size-2 shrink-0 rounded-full {{ $figure['dot'] }}" aria-hidden="true"></span>{{ $figure['label'] }}
                                                </span>
                                                <span @class(['mt-1.5 block text-xl leading-none font-extrabold tracking-tight tabular-nums @sm/ledger:text-2xl', $figure['tone'] => $figure['value'] > 0, $mutedText.' opacity-60' => $figure['value'] <= 0])>{{ $amountAtLeast($figure['value']) }}</span>
                                                <span class="mt-1 block text-[11px] tabular-nums {{ $mutedText }} group-enabled:group-hover:underline group-aria-pressed:underline">{{ $figure['note'] }}</span>
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- The shrink line: everything sent, split by what became of it. --}}
                    <div>
                        <div class="measure-track flex h-4 w-full gap-[3px] overflow-hidden rounded-[3px]" role="group" aria-label="{{ __('transfers.shrink_line_label', ['currency' => $line['currency']]) }}">
                            @foreach ($segments as $segment)
                                @continue($segment['value'] <= 0)
                                @php
                                    $segmentTitle = $segment['label'].' · '.$exact($segment['value']).' '.$line['currency'];
                                    $isActive = $segment['stage'] !== null && $stage === $segment['stage'];
                                @endphp
                                @if ($segment['stage'] === null)
                                    <span
                                        class="ledger-segment h-full min-w-1.5 rounded-[2px] {{ $segment['fill'] }}"
                                        style="width: {{ round($segment['value'] / $scale * 100, 3) }}%"
                                        title="{{ $segmentTitle }}"
                                    ><span class="sr-only">{{ $segmentTitle }}</span></span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="toggleStage('{{ $segment['stage'] }}')"
                                        @class(['ledger-segment h-full min-w-1.5 rounded-[2px] transition-opacity duration-150 hover:opacity-80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary', $segment['fill'], 'ring-1 ring-secondary-dark dark:ring-white' => $isActive])
                                        style="width: {{ round($segment['value'] / $scale * 100, 3) }}%"
                                        title="{{ $segmentTitle }}"
                                        aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                    ><span class="sr-only">{{ $segmentTitle }}</span></button>
                                @endif
                            @endforeach
                        </div>

                        {{-- Money with no segment on the line above. Naming the
                             amount, not just the count, so the unfilled remainder
                             is accounted for rather than left as a silent gap. --}}
                        @if ($line['convertedCount'] > 0 || $line['unrecordedCount'] > 0)
                            <p class="mt-2 text-xs {{ $mutedText }}">
                                @if ($line['convertedCount'] > 0)
                                    {{ trans_choice('transfers.count_tranches_landed_in_another_currency', $line['convertedCount'], ['count' => $line['convertedCount'], 'amount' => $exact($line['convertedAmount']).' '.$line['currency']]) }}
                                @endif
                                @if ($line['unrecordedCount'] > 0)
                                    {{ trans_choice('transfers.count_tranches_missing_net', $line['unrecordedCount'], ['count' => $line['unrecordedCount'], 'amount' => $exact($line['unrecordedAmount']).' '.$line['currency']]) }}
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm {{ $mutedText }}">{{ __('transfers.no_matching_transfers') }}</p>
            @endforelse
        </section>

        {{-- How the ladder is ranked, and what is currently narrowing it. --}}
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t pt-5 {{ $hairline }}">
            <div class="flex flex-wrap items-center gap-x-1 gap-y-2">
                <span class="me-2 text-xs font-semibold {{ $mutedText }}">{{ __('transfers.sort_by') }}</span>
                @foreach ($sortOptions as $sortKey => $sortLabel)
                    <button
                        type="button"
                        wire:click="setSort('{{ $sortKey }}')"
                        aria-pressed="{{ $sort === $sortKey ? 'true' : 'false' }}"
                        @class([
                            'rounded-full px-3 py-1.5 text-xs font-semibold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                            'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark' => $sort === $sortKey,
                            'text-secondary-medium hover:bg-board-head dark:text-secondary-soft dark:hover:bg-white/5' => $sort !== $sortKey,
                        ])
                        data-sort="{{ $sortKey }}"
                    >{{ $sortLabel }}</button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span class="text-xs tabular-nums {{ $mutedText }}">
                    {{ trans_choice('transfers.count_matching_transfer', $this->transfers->total(), ['count' => $this->transfers->total()]) }}
                </span>
                @if ($this->agreementFilter !== null)
                    <button type="button" wire:click="$set('agreement', '')" class="inline-flex min-h-8 items-center gap-1.5 rounded-full bg-funding-soft ps-3 pe-2 text-xs font-semibold text-funding transition-colors hover:bg-funding hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-white" data-agreement-chip>
                        {{ __('transfers.agreement_filter_chip', ['donor' => $this->agreementFilter->donor->name, 'proposal' => $this->agreementFilter->proposal->name]) }}
                        <flux:icon.x-mark variant="micro" class="size-3.5" />
                    </button>
                @endif
                @if ($filtersAreActive)
                    <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
                @endif
            </div>
        </div>

        {{-- The ladder. --}}
        @if ($this->transfers->isEmpty())
            <x-ui.empty-state
                class="rtl:[&_svg]:-scale-x-100"
                icon="arrow-right-circle"
                :heading="__('transfers.no_matching_transfers')"
                :body="__('common.change_the_search_or_filters_and_try_again')"
            />
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->transfers as $transfer)
                    @php
                        $sentCurrency = $transfer->amount_transfered_currency;
                        $promised = (float) $transfer->payment_amount;
                        $transferState = $transfer->state();
                        $landedHere = $transfer->amount_received_currency === $sentCurrency;
                        $netLanded = $transfer->net_amount_received === null ? null : (float) $transfer->net_amount_received;

                        // A measure is only drawn against the promise when both
                        // ends of the journey are in the same currency. A
                        // converted tranche gets its own reading instead.
                        $isComparable = $transferState === 'received' && $netLanded !== null && $landedHere;
                        // Money that did arrive, in a currency the promise cannot be
                        // measured against. It landed; it just cannot be subtracted.
                        $landedElsewhere = $transferState === 'received' && $netLanded !== null && ! $landedHere;
                        $feesHere = $transfer->fees_currency === $sentCurrency;
                        $feesAmount = (float) $transfer->fees;

                        $shrink = $isComparable ? max(0.0, $promised - $netLanded) : 0.0;
                        $feesTaken = $isComparable && $feesHere ? min($shrink, $feesAmount) : 0.0;
                        $gapTaken = $shrink - $feesTaken;
                        $overage = $isComparable ? max(0.0, $netLanded - $promised) : 0.0;

                        // The bar is scaled to whichever is larger, so a tranche
                        // that arrived fuller than it left still fits and the
                        // promise tick shows it overshot.
                        $rowScale = max($promised, $netLanded ?? 0.0, 0.01);
                        $promisedTick = round($promised / $rowScale * 100, 3);

                        $daysLate = $transferState === 'late' && $transfer->scheduled_date !== null
                            ? (int) round($transfer->scheduled_date->diffInDays(CarbonImmutable::today()))
                            : null;
                        // Only when the money landed after it was sent, and only
                        // when it landed at all. A receive date before the transfer
                        // date is a typo, and "landed in −3 days" reports the typo
                        // as a fact; a failed tranche never "landed" in any days.
                        $daysInTransit = $transferState === 'received'
                            && $transfer->transfer_date !== null
                            && $transfer->receive_date !== null
                            && $transfer->receive_date->gte($transfer->transfer_date)
                                ? (int) round($transfer->transfer_date->diffInDays($transfer->receive_date))
                                : null;

                        // Money the donor has sent that has not arrived. Without
                        // this the page is blind to the case it exists for: a wire
                        // sent months ago that the account never saw is only ever
                        // labelled "In transit", with no age and no urgency.
                        $daysInFlight = $transferState === 'transit' && $transfer->transfer_date !== null
                            ? (int) round($transfer->transfer_date->diffInDays(CarbonImmutable::today()))
                            : null;

                        $rowTone = match ($transferState) {
                            'late', 'failed' => 'text-status-critical',
                            'transit' => 'text-status-warn',
                            default => $mutedText,
                        };

                        $stateLabel = match (true) {
                            // Marked received with nothing recorded is not "landed".
                            $transfer->isMissingNetAmount() => __('transfers.not_recorded_yet'),
                            $transferState === 'received' => __('transfers.landed'),
                            $transferState === 'failed' => __('transfers.stage_failed'),
                            $transferState === 'transit' => __('transfers.stage_in_transit'),
                            $transferState === 'late' => __('transfers.stage_overdue'),
                            default => __('transfers.stage_upcoming'),
                        };

                        // The three nodes of the journey. An empty node is still
                        // drawn: a date nobody has filled in is information.
                        $thread = [
                            ['label' => __('transfers.scheduled'), 'date' => $transfer->scheduled_date, 'done' => $transfer->scheduled_date !== null],
                            ['label' => __('transfers.sent_on'), 'date' => $transfer->transfer_date, 'done' => $transfer->transfer_date !== null],
                            ['label' => __('transfers.received_on'), 'date' => $transfer->receive_date, 'done' => $transfer->receive_date !== null],
                        ];

                        $measureLabel = $isComparable || $landedElsewhere
                            ? __('transfers.measure_label_landed', [
                                'sent' => $exact($promised).' '.$sentCurrency->value,
                                'landed' => $exact($netLanded).' '.$transfer->amount_received_currency->value,
                            ])
                            : __('transfers.measure_label_pending', [
                                'sent' => $exact($promised).' '.$sentCurrency->value,
                                'state' => $stateLabel,
                            ]);
                    @endphp

                    <li wire:key="transfer-{{ $transfer->id }}" data-transfer-state="{{ $transferState }}">
                        <div
                            x-data="{ open: false }"
                            class="grid gap-x-8 gap-y-4 py-5 @4xl/ladder:grid-cols-[minmax(14rem,.85fr)_minmax(0,1.15fr)] @4xl/ladder:items-center"
                        >
                            {{-- Who owes it, and the journey its dates describe. --}}
                            <div class="min-w-0">
                                <div class="flex items-start gap-2">
                                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', match ($transferState) {
                                        'received' => 'bg-funding',
                                        'failed', 'late' => 'bg-primary',
                                        'transit' => 'bg-status-warn',
                                        default => 'money-pending',
                                    }]) aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <a
                                            href="{{ route('donors.show', $transfer->donorProposal->donor) }}"
                                            wire:navigate
                                            class="block truncate text-sm font-semibold underline-offset-2 hover:underline {{ $inkText }}"
                                        >{{ $transfer->donorProposal->donor->name }}</a>
                                        <p class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                                            <a href="{{ route('proposals.show', $transfer->donorProposal->proposal) }}" wire:navigate class="truncate underline-offset-2 hover:underline">{{ $transfer->donorProposal->proposal->name }}</a>
                                            <span aria-hidden="true">·</span>
                                            <a href="{{ route('funding-agreements.show', $transfer->donorProposal) }}" wire:navigate class="shrink-0 underline-offset-2 hover:underline" data-agreement-link>{{ filled($transfer->donorProposal->project_num) ? __('proposals.agreement_number', ['number' => $transfer->donorProposal->project_num]) : __('proposals.view_agreement') }}</a>
                                        </p>
                                    </div>
                                </div>

                                {{-- Scheduled, sent, landed — three nodes on one thread. --}}
                                <ol class="mt-3 flex items-center gap-0 ps-4">
                                    @foreach ($thread as $node)
                                        @if (! $loop->first)
                                            <li aria-hidden="true" @class(['h-px w-4 shrink-0 @xl/ladder:w-7', $node['done'] ? 'bg-secondary-light' : 'bg-secondary-soft dark:bg-secondary-medium'])></li>
                                        @endif
                                        <li class="flex items-center gap-1.5">
                                            <span @class([
                                                'size-1.5 shrink-0 rounded-full',
                                                'bg-secondary-dark dark:bg-white' => $node['done'],
                                                'ring-1 ring-secondary-light' => ! $node['done'],
                                            ]) aria-hidden="true"></span>
                                            {{-- secondary-light is 2.69:1 on white. The
                                                 "not yet" signal is the hollow dot, not a
                                                 colour nobody can read. --}}
                                            <span class="text-[11px] tabular-nums whitespace-nowrap {{ $mutedText }}">
                                                <span class="sr-only">{{ $node['label'] }}: </span>
                                                @if ($node['date'] === null)
                                                    <span class="sr-only">{{ __('transfers.not_yet_assigned') }}</span>
                                                    <span aria-hidden="true">—</span>
                                                @else
                                                    {{ $node['date']->translatedFormat(__('formats.date_short')) }}
                                                @endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ol>

                                <p class="mt-2 ps-4 text-xs font-semibold {{ $rowTone }}">
                                    @if ($daysLate !== null)
                                        {{ trans_choice('transfers.count_days_overdue', $daysLate, ['count' => $daysLate]) }}
                                    @elseif ($daysInFlight !== null)
                                        {{ trans_choice('transfers.in_transit_for_count_days', $daysInFlight, ['count' => $daysInFlight]) }}
                                    @elseif ($daysInTransit !== null)
                                        {{ trans_choice('transfers.landed_in_count_days', $daysInTransit, ['count' => $daysInTransit]) }}
                                    @else
                                        {{ $stateLabel }}
                                    @endif
                                    <span class="{{ $mutedText }}">
                                        ·
                                        @if ($transfer->account === null)
                                            <span class="italic">{{ __('transfers.no_account') }}</span>
                                        @else
                                            {{ $transfer->account->account_name }}
                                        @endif
                                    </span>
                                </p>
                            </div>

                            {{-- The measure: the promise at full length, and what is left of it. --}}
                            <div class="min-w-0">
                                <div class="flex items-end justify-between gap-4">
                                    <p class="text-2xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                        {{ $amount($promised) }}<span class="ms-1.5 text-[11px] font-bold tracking-normal {{ $mutedText }}">{{ $sentCurrency->value }}</span>
                                    </p>

                                    <div class="flex items-center gap-1.5">
                                        @if ($isComparable && $shrink > 0)
                                            <p class="text-sm leading-none font-bold tabular-nums text-status-critical" data-row-shortfall>
                                                −{{ $percent($shrink, $promised) }}%
                                            </p>
                                        @elseif ($isComparable && $overage > 0)
                                            <p class="text-sm leading-none font-bold tabular-nums text-funding">
                                                +{{ $percent($overage, $promised) }}%
                                            </p>
                                        @elseif ($isComparable)
                                            <p class="text-sm leading-none font-bold tabular-nums text-funding">{{ __('transfers.landed_in_full') }}</p>
                                        @else
                                            <x-ui.enum-badge :case="$transfer->ar_status" />
                                        @endif

                                        <button
                                            type="button"
                                            x-on:click="open = ! open"
                                            {{-- Static default so the control is still described
                                                 correctly before Alpine takes over the binding. --}}
                                            aria-expanded="false"
                                            x-bind:aria-expanded="open ? 'true' : 'false'"
                                            aria-controls="transfer-detail-{{ $transfer->id }}"
                                            class="-me-1 flex size-8 items-center justify-center rounded-md text-secondary-medium transition-colors duration-150 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary dark:text-secondary-soft dark:hover:bg-white/5"
                                            aria-label="{{ __('transfers.show_the_arithmetic') }}"
                                        >
                                            <flux:icon name="chevron-down" class="size-4 transition-transform duration-200" x-bind:class="open && 'rotate-180'" />
                                        </button>

                                        @can('transfers.update')
                                        {{-- No delete: a money record never disappears. --}}
                                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-8!" wire:click="openEditModal({{ $transfer->id }})" :aria-label="__('transfers.edit_transfer')" />
                                        @endcan
                                    </div>
                                </div>

                                {{-- The bar is the promise, drawn at full length. What landed is solid;
                                     what the journey took is cut out of it. --}}
                                <div class="relative mt-2.5 h-4" role="img" aria-label="{{ $measureLabel }}">
                                    <div class="measure-track absolute inset-0 flex gap-[2px] overflow-hidden rounded-[3px]">
                                        @if ($isComparable)
                                            <span class="measure-fill h-full bg-funding" style="width: {{ round(($netLanded ?? 0) / $rowScale * 100, 3) }}%" title="{{ __('transfers.landed') }} · {{ $exact($netLanded) }} {{ $sentCurrency->value }}"></span>
                                            @if ($feesTaken > 0)
                                                <span class="measure-fill money-fees h-full" style="width: {{ round($feesTaken / $rowScale * 100, 3) }}%" title="{{ __('transfers.fees') }} · {{ $exact($feesTaken) }} {{ $sentCurrency->value }}"></span>
                                            @endif
                                            @if ($gapTaken > 0)
                                                <span class="measure-fill money-gap h-full" style="width: {{ round($gapTaken / $rowScale * 100, 3) }}%" title="{{ __('transfers.exchange_gap') }} · {{ $exact($gapTaken) }} {{ $sentCurrency->value }}"></span>
                                            @endif
                                        @else
                                            {{-- Nothing has landed. The measure is drawn empty rather than
                                                 omitted: an unlit slot is information, a blank row is not. --}}
                                            <span @class(['measure-fill h-full w-full', match (true) {
                                                // It arrived, just not in a currency the promise
                                                // can be measured against — so it reads as landed,
                                                // never as money nobody recorded.
                                                $landedElsewhere => 'bg-funding',
                                                $transferState === 'failed' => 'money-failed',
                                                $transferState === 'late' => 'money-overdue',
                                                $transferState === 'transit' => 'money-transit',
                                                $transferState === 'received' => 'money-unrecorded',
                                                default => 'money-pending',
                                            }]) title="{{ $measureLabel }}"></span>
                                        @endif
                                    </div>

                                    {{-- Where the promise sat, so an overshoot is visible as an overshoot. --}}
                                    @if ($isComparable && $overage > 0)
                                        <span class="absolute inset-y-0 w-px bg-secondary-dark dark:bg-white" style="inset-inline-start: {{ $promisedTick }}%" aria-hidden="true"></span>
                                    @endif
                                </div>

                                {{-- The arithmetic that produced the gap. --}}
                                <div
                                    id="transfer-detail-{{ $transfer->id }}"
                                    x-show="open"
                                    x-collapse
                                    {{-- Hidden before Alpine boots too: twenty panels flashing open
                                         and collapsing on load is a worse first paint than none. --}}
                                    style="display: none"
                                    class="mt-3 border-t pt-3 {{ $hairline }}"
                                >
                                    <dl class="grid grid-cols-2 gap-x-6 gap-y-2.5 text-xs @xl/ladder:grid-cols-4">
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('transfers.sent') }}</dt>
                                            <dd class="mt-0.5"><x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" /></dd>
                                        </div>
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('transfers.fees') }}</dt>
                                            <dd class="mt-0.5"><x-ui.money :amount="$transfer->fees" :currency="$transfer->fees_currency" /></dd>
                                        </div>
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('transfers.exchange_rate') }}</dt>
                                            <dd class="mt-0.5 font-semibold tabular-nums">{{ $rate($transfer->exchange_rate) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="{{ $mutedText }}">{{ __('common.net_received') }}</dt>
                                            <dd class="mt-0.5">
                                                @if ($transfer->net_amount_received === null)
                                                    <span class="text-status-critical">{{ __('transfers.not_recorded_yet') }}</span>
                                                @else
                                                    <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>

                                    <p class="mt-3 text-xs {{ $mutedText }}">
                                        @if ($isComparable && $shrink > 0)
                                            <span class="font-semibold text-status-critical">−{{ $exact($shrink) }} {{ $sentCurrency->value }}</span>
                                            {{ __('transfers.never_reached_the_account') }}
                                        @elseif (! $landedHere && $transfer->net_amount_received !== null)
                                            {{ __('transfers.sent_in_currency_landed_in_currency', ['sent' => $sentCurrency->value, 'landed' => $transfer->amount_received_currency->value]) }}
                                        @elseif ($transfer->isMissingNetAmount())
                                            {{ __('transfers.received_but_no_net_amount_recorded') }}
                                        @else
                                            {{ __('transfers.nothing_has_left_the_donor_yet') }}
                                        @endif
                                        <span class="{{ $inkText }}">{{ __('transfers.reference_num', ['reference' => $transfer->donorProposal->project_num]) }}</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <flux:pagination :paginator="$this->transfers" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#transfer-ladder-heading" />
        @endif
    @endif

    <x-funding.transfer-modal
        :editing="$editingTransferId !== null"
        :agreement-options="$showTransferModal ? $this->agreementOptions : []"
        :account-options="$showTransferModal ? $this->accountOptions : []"
        :confirming-delete="$confirmingTransferDelete"
    />
</section>
