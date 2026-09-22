<?php

use App\Enums\ArStatus;
use App\Enums\DonorType;
use App\Livewire\Concerns\ManagesDonorForm;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.donors')] class extends Component {
    use ManagesDonorForm;
    use WithPagination;

    /** The type filter value that selects donors carrying no classification. */
    public const UNCLASSIFIED = 'unclassified';

    /** How many donors the share-of-wallet strip names before folding the rest together. */
    public const NAMED_SEGMENTS = 7;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $relationship = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public bool $showDeleteModal = false;
    public ?int $deletingDonorId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRelationship(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->type = '';
        $this->relationship = '';
        $this->sort = 'name';
        $this->resetPage();
    }

    /**
     * Select a donor type from the type rail, or clear it when already selected.
     */
    public function toggleType(string $type): void
    {
        $allowed = [...DonorType::values(), self::UNCLASSIFIED];

        $this->type = in_array($type, $allowed, true) && $this->type !== $type ? $type : '';
        $this->resetPage();
    }

    /**
     * The donor query narrowed by search and relationship, and by type unless asked not to.
     *
     * @return Builder<Donor>
     */
    protected function filteredQuery(bool $withType = true): Builder
    {
        $search = trim($this->search);

        return Donor::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('country', 'like', '%'.$search.'%')
                        ->orWhere('contact_person', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($this->relationship === 'funding', fn (Builder $query) => $query->has('donorProposals'))
            ->when($this->relationship === 'prospect', fn (Builder $query) => $query->doesntHave('donorProposals'))
            ->when($withType && $this->type === self::UNCLASSIFIED, fn (Builder $query) => $query->whereNull('type'))
            ->when($withType && in_array($this->type, DonorType::values(), true), fn (Builder $query) => $query->where('type', $this->type));
    }

    /** @return LengthAwarePaginator<int, Donor> */
    #[Computed]
    public function donors(): LengthAwarePaginator
    {
        $nextDue = TransfersTracking::query()
            ->selectRaw('min(transfers_tracking.scheduled_date)')
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereColumn('donor_proposal.donor_id', 'donors.id')
            ->where('transfers_tracking.ar_status', ArStatus::Pending);

        return $this->filteredQuery()
            ->select(['donors.id', 'donors.name', 'donors.type', 'donors.contact_person', 'donors.email', 'donors.phone', 'donors.country', 'donors.next_step', 'donors.next_step_due_on', 'donors.last_contacted_on', 'donors.updated_at'])
            ->selectSub($nextDue, 'next_due_date')
            ->withCount('donorProposals')
            ->with([
                'donorProposals' => fn ($query) => $query->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])->orderByDesc('budget'),
                'donorProposals.proposal' => fn ($query) => $query->select(['id', 'name', 'status']),
                'donorProposals.proposal.projects' => fn ($query) => $query->select(['projects.id', 'projects.name']),
                'donorProposals.transfers' => fn ($query) => $query->select(['id', 'donor_proposal_id', 'ar_status', 'payment_amount', 'amount_transfered_currency', 'net_amount_received', 'amount_received_currency', 'scheduled_date', 'transfer_date', 'receive_date']),
            ])
            ->when($this->sort === 'agreements', fn (Builder $query) => $query->orderByDesc('donor_proposals_count'))
            ->when($this->sort === 'next_due', fn (Builder $query) => $query->orderByRaw('next_due_date is null')->orderBy('next_due_date'))
            ->when($this->sort === 'follow_up', fn (Builder $query) => $query->orderByRaw('donors.next_step_due_on is null')->orderBy('donors.next_step_due_on'))
            ->orderBy('donors.name')
            ->orderBy('donors.id')
            ->paginate(12);
    }

    /**
     * Ids of every donor matching the current filters, across all pages.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function filteredDonorIds(): Collection
    {
        return $this->filteredQuery()->pluck('donors.id');
    }

    /**
     * The one-line reading of the portfolio: who funds, what, and what is due.
     *
     * @return array{donors: int, funding: int, proposals: int, projects: int, overdue: int, followUps: int}
     */
    #[Computed]
    public function pulse(): array
    {
        $ids = $this->filteredDonorIds;
        $agreements = DonorProposal::query()->whereIn('donor_id', $ids);

        $pendingTransfers = fn () => TransfersTracking::query()
            ->where('ar_status', ArStatus::Pending)
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('donor_id', $ids));

        return [
            'donors' => $ids->count(),
            'funding' => (clone $agreements)->distinct()->count('donor_id'),
            'proposals' => (clone $agreements)->distinct()->count('proposal_id'),
            'projects' => DB::table('project_proposal')
                ->whereIn('proposal_id', (clone $agreements)->select('proposal_id'))
                ->distinct()
                ->count('project_id'),
            // Sent money is in transit, not overdue, however late it is.
            'overdue' => $pendingTransfers()->whereNull('transfer_date')->whereDate('scheduled_date', '<', today())->count(),
            'followUps' => Donor::query()->whereIn('id', $ids)->whereDate('next_step_due_on', '<=', today())->count(),
        ];
    }

    /**
     * Each currency's committed money split by donor, largest first.
     *
     * Received counts only money that arrived in the agreement's own currency,
     * so a segment never mixes currencies.
     *
     * @return list<array{currency: string, committed: float, received: float, segments: list<array{donorId: ?int, name: string, committed: float, received: float, share: float, count: int}>}>
     */
    #[Computed]
    public function wallet(): array
    {
        $ids = $this->filteredDonorIds;

        $committed = DonorProposal::query()
            ->whereIn('donor_id', $ids)
            ->selectRaw('donor_id, currency, sum(budget) as total')
            ->groupBy('donor_id', 'currency')
            ->get();

        $received = TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->whereIn('donor_proposal.donor_id', $ids)
            ->where('transfers_tracking.ar_status', ArStatus::Received)
            ->whereColumn('transfers_tracking.amount_received_currency', 'donor_proposal.currency')
            ->selectRaw('donor_proposal.donor_id as donor_id, donor_proposal.currency as currency, sum(transfers_tracking.net_amount_received) as total')
            ->groupBy('donor_proposal.donor_id', 'donor_proposal.currency')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->donor_id.'|'.$row->currency => (float) $row->total]);

        $names = Donor::query()->whereIn('id', $committed->pluck('donor_id')->unique())->pluck('name', 'id');

        return $committed
            ->groupBy(fn ($row): string => $row->currency->value)
            ->sortKeys()
            ->map(function (Collection $rows, string $currency) use ($received, $names): array {
                $total = (float) $rows->sum('total');

                $segments = $rows
                    ->map(fn ($row): array => [
                        'donorId' => (int) $row->donor_id,
                        'name' => (string) ($names[$row->donor_id] ?? ''),
                        'committed' => (float) $row->total,
                        'received' => (float) ($received[$row->donor_id.'|'.$currency] ?? 0),
                        'count' => 1,
                    ])
                    ->sortByDesc('committed')
                    ->values();

                $named = $segments->take(self::NAMED_SEGMENTS);
                $rest = $segments->slice(self::NAMED_SEGMENTS);

                if ($rest->isNotEmpty()) {
                    $named->push([
                        'donorId' => null,
                        'name' => trans_choice('donors.count_other_donors', $rest->count(), ['count' => $rest->count()]),
                        'committed' => (float) $rest->sum('committed'),
                        'received' => (float) $rest->sum('received'),
                        'count' => $rest->count(),
                    ]);
                }

                return [
                    'currency' => $currency,
                    'committed' => $total,
                    'received' => (float) $segments->sum('received'),
                    'segments' => $named
                        ->map(fn (array $segment): array => [...$segment, 'share' => $total > 0 ? round($segment['committed'] / $total * 100, 2) : 0])
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * How many donors of each type match the other filters; the rail stays fully selectable.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    #[Computed]
    public function typeRail(): array
    {
        $counts = $this->filteredQuery(withType: false)
            ->selectRaw("coalesce(type, '".self::UNCLASSIFIED."') as type_key, count(*) as total")
            ->groupBy('type_key')
            ->pluck('total', 'type_key');

        $stops = array_map(fn (DonorType $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'count' => (int) ($counts[$case->value] ?? 0),
        ], DonorType::cases());

        $stops[] = [
            'value' => self::UNCLASSIFIED,
            'label' => __('donors.not_classified'),
            'count' => (int) ($counts[self::UNCLASSIFIED] ?? 0),
        ];

        return $stops;
    }

    /**
     * How many rows the funders column shows, so the transfers column can match its height.
     *
     * Each currency block costs roughly two rows for its heading.
     */
    #[Computed]
    public function overviewRows(): int
    {
        $rows = collect($this->wallet)->sum(fn (array $line): int => count($line['segments']) + 2);

        return max(3, min(8, $rows));
    }

    /**
     * The pending transfers ahead, overdue ones first, for the transfers column.
     *
     * @return Collection<int, array{donorId: int, donor: string, proposal: string, agreementId: int, amount: float, currency: string, date: CarbonImmutable, overdue: bool}>
     */
    #[Computed]
    public function upcomingTransfers(): Collection
    {
        return TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'ar_status'])
            ->where('ar_status', ArStatus::Pending)
            ->whereNotNull('scheduled_date')
            ->whereHas('donorProposal', fn (Builder $query) => $query->whereIn('donor_id', $this->filteredDonorIds))
            ->with(['donorProposal:id,donor_id,proposal_id', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name'])
            ->orderBy('scheduled_date')
            ->limit($this->overviewRows)
            ->get()
            ->map(fn (TransfersTracking $transfer): array => [
                'donorId' => $transfer->donorProposal->donor_id,
                'donor' => $transfer->donorProposal->donor->name,
                'proposal' => (string) $transfer->donorProposal->proposal?->name,
                'agreementId' => $transfer->donor_proposal_id,
                'amount' => (float) $transfer->payment_amount,
                'currency' => $transfer->amount_transfered_currency->value,
                'date' => $transfer->scheduled_date,
                'overdue' => $transfer->state() === 'late',
            ]);
    }

    /**
     * One donor's money per currency, and the transfer rhythm behind it.
     *
     * @return array{lines: array<string, array{committed: float, received: float, pending: float, share: int}>, next: ?array{amount: float, currency: string, date: CarbonImmutable, overdue: bool}, overdue: int, lastReceived: ?CarbonImmutable, projects: int}
     */
    public function donorMoney(Donor $donor): array
    {
        $lines = [];
        $pendingTransfers = collect();
        $receivedDates = collect();

        foreach ($donor->donorProposals as $agreement) {
            $currency = $agreement->currency->value;
            $lines[$currency] ??= ['committed' => 0.0, 'received' => 0.0, 'pending' => 0.0, 'share' => 0];
            $lines[$currency]['committed'] += (float) $agreement->budget;

            foreach ($agreement->transfers as $transfer) {
                if ($transfer->ar_status === ArStatus::Received && $transfer->amount_received_currency?->value === $currency) {
                    $lines[$currency]['received'] += (float) $transfer->net_amount_received;
                }

                if ($transfer->ar_status === ArStatus::Pending) {
                    $pendingTransfers->push($transfer);

                    if ($transfer->amount_transfered_currency?->value === $currency) {
                        $lines[$currency]['pending'] += (float) $transfer->payment_amount;
                    }
                }

                if ($transfer->ar_status === ArStatus::Received && $transfer->receive_date !== null) {
                    $receivedDates->push($transfer->receive_date);
                }
            }
        }

        ksort($lines);

        foreach ($lines as $currency => $line) {
            $lines[$currency]['share'] = $line['committed'] > 0 ? (int) floor($line['received'] / $line['committed'] * 100) : 0;
        }

        $next = $pendingTransfers->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first();

        return [
            'lines' => $lines,
            'next' => $next === null ? null : [
                'amount' => (float) $next->payment_amount,
                'currency' => (string) $next->amount_transfered_currency?->value,
                'date' => $next->scheduled_date,
                'overdue' => $next->state() === 'late',
            ],
            'overdue' => $pendingTransfers->filter(fn (TransfersTracking $transfer): bool => $transfer->state() === 'late')->count(),
            'lastReceived' => $receivedDates->sortDesc()->first(),
            'projects' => $donor->donorProposals->flatMap(fn (DonorProposal $agreement) => $agreement->proposal?->projects->pluck('id') ?? [])->unique()->count(),
        ];
    }

    /**
     * Whether this donor may be deleted.
     *
     * donor_proposal.donor_id RESTRICTs: a donor that has signed anything stays.
     */
    public function canDelete(int $donorId): bool
    {
        $donor = Donor::query()->select(['id'])->withCount('donorProposals')->find($donorId);

        return $donor !== null && $donor->donor_proposals_count === 0;
    }

    protected function donorSaved(Donor $donor, bool $isCreate): void
    {
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('donors.donor_created') : __('donors.donor_updated'),
        );
    }

    public function confirmDelete(int $donorId): void
    {
        Gate::authorize('donors.delete');

        $this->deletingDonorId = $donorId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingDonorId = null;
        $this->showDeleteModal = false;
    }

    public function deleteDonor(): void
    {
        if ($this->deletingDonorId === null) {
            $this->showDeleteModal = false;

            return;
        }

        if (! $this->canDelete($this->deletingDonorId)) {
            $this->cancelDelete();

            Flux::toast(
                variant: 'danger',
                text: __('donors.this_donor_has_signed_a_funding_agreement_and_cannot_be_dele'),
            );

            return;
        }

        Gate::authorize('donors.delete');

        Donor::query()->findOrFail($this->deletingDonorId)->delete();

        $this->cancelDelete();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('donors.donor_deleted'));
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $type !== '' || $relationship !== '';
    $hasAnyDonor = $filtersAreActive || $this->donors->total() > 0;
    $pulse = $this->pulse;
    $mutedText = 'text-secondary-medium dark:text-secondary-soft';
    $inkText = 'text-secondary-dark dark:text-white';
    $hairline = 'border-secondary-soft dark:border-secondary-medium';
    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $shortAmount = fn (float $value): string => Number::abbreviate($value, maxPrecision: 1);
    $initials = fn (string $name): string => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode('')) ?: '·';
    $pageDonorIds = $this->donors->getCollection()->pluck('id')->all();
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white"
    aria-labelledby="donor-directory-heading"
    x-data="{ focus: null, lit(id) { return this.focus === null || this.focus === id } }"
>
    <x-ui.page-header
        heading-id="donor-directory-heading"
        :title="__('common.donors')"
        :count="$this->donors->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('donors.search_donors')" :placeholder="__('donors.name_country_or_contact')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('donors.relationship')" wire:model.live="relationship" :value="$relationship">
                <flux:select.option value="">{{ __('donors.all_donors') }}</flux:select.option>
                <flux:select.option value="funding">{{ __('donors.funding_now') }}</flux:select.option>
                <flux:select.option value="prospect">{{ __('donors.prospects') }}</flux:select.option>
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.type')" wire:model.live="type" :value="$type">
                <flux:select.option value="">{{ __('donors.all_types') }}</flux:select.option>
                @foreach ($this->typeRail as $typeStop)
                    <flux:select.option :value="$typeStop['value']">{{ $typeStop['label'] }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('donors.sort_by')" wire:model.live="sort" :value="$sort">
                <flux:select.option value="name">{{ __('donors.sort_name') }}</flux:select.option>
                <flux:select.option value="agreements">{{ __('donors.sort_agreements') }}</flux:select.option>
                <flux:select.option value="next_due">{{ __('donors.sort_next_due') }}</flux:select.option>
                <flux:select.option value="follow_up">{{ __('donors.sort_follow_up') }}</flux:select.option>
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('donors.create')
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-donor-button">
                {{ __('donors.add_donor') }}
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if ($filtersAreActive)
        <div class="flex flex-wrap items-center gap-2 text-sm" data-active-filters>
            <span class="{{ $mutedText }}">{{ trans_choice('donors.count_matching_donor', $pulse['donors'], ['count' => $pulse['donors']]) }}</span>
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        </div>
    @endif

    @if (! $hasAnyDonor)
        <x-ui.empty-state icon="building-library" :heading="__('donors.no_donors_yet')" :body="__('donors.add_the_first_donor')" />
    @else
        {{-- Overview: one framed band — the money by funder beside what is due next. --}}
        <div class="overflow-hidden rounded-2xl border {{ $hairline }} bg-white dark:bg-secondary-dark" data-donor-overview>
            {{-- Pulse strip --}}
            <dl class="grid grid-cols-2 border-b bg-board-head/70 {{ $hairline }} @3xl/directory:grid-cols-4 dark:bg-white/[0.03]" data-donor-pulse>
                @foreach ([
                    ['label' => __('donors.funding_now'), 'value' => $pulse['funding'], 'suffix' => __('donors.of_count_donors', ['count' => $pulse['donors']]), 'tone' => $inkText],
                    ['label' => __('common.proposals'), 'value' => $pulse['proposals'], 'suffix' => __('donors.funded'), 'tone' => $inkText],
                    ['label' => __('common.projects'), 'value' => $pulse['projects'], 'suffix' => __('donors.reached'), 'tone' => 'text-funding'],
                    ['label' => __('donors.overdue'), 'value' => $pulse['overdue'], 'suffix' => trans_choice('donors.transfers_word', $pulse['overdue']), 'tone' => $pulse['overdue'] > 0 ? 'text-status-critical' : $mutedText],
                ] as $figure)
                    <div @class(['px-5 py-4', 'border-s '.$hairline => $loop->index % 2 === 1, '@3xl/directory:border-s' => $loop->index === 2, 'border-t @3xl/directory:border-t-0 '.$hairline => $loop->index >= 2])>
                        <dt class="type-label {{ $mutedText }}">{{ $figure['label'] }}</dt>
                        <dd class="mt-1 flex items-baseline gap-2">
                            <span class="text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $figure['tone'] }}" x-data="countUp({{ $figure['value'] }})" x-text="display">{{ $figure['value'] }}</span>
                            <span class="text-xs font-medium {{ $mutedText }}">{{ $figure['suffix'] }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>

            <div class="grid @5xl/directory:grid-cols-[minmax(0,1fr)_21rem]">
                {{-- Who funds us: per currency, a ranked bar per donor. --}}
                <section aria-labelledby="wallet-heading" class="min-w-0 p-5 @3xl/directory:p-6" data-wallet>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                        <h2 id="wallet-heading" class="text-base font-bold {{ $inkText }}">{{ __('donors.who_funds_us') }}</h2>
                        <p class="flex items-center gap-4 text-xs {{ $mutedText }}">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-funding" aria-hidden="true"></span>{{ __('donors.received') }}</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-funding/20" aria-hidden="true"></span>{{ __('donors.still_to_come') }}</span>
                        </p>
                    </div>

                    @forelse ($this->wallet as $line)
                        @php
                            $largest = max(collect($line['segments'])->max('committed'), 1);
                            $isRoomy = count($line['segments']) <= 4;
                            $currencyShare = $line['committed'] > 0 ? (int) floor($line['received'] / $line['committed'] * 100) : 0;
                        @endphp
                        <div @class(['mt-5', 'border-t pt-5 '.$hairline => ! $loop->first]) wire:key="wallet-{{ $line['currency'] }}" data-wallet-currency="{{ $line['currency'] }}">
                            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1">
                                <p class="flex items-baseline gap-2.5">
                                    <span class="rounded-md bg-funding-soft px-1.5 py-0.5 text-xs font-extrabold tracking-wide text-funding">{{ $line['currency'] }}</span>
                                    <span class="text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}" x-data="countUp({{ (int) round($line['committed']) }})" x-text="new Intl.NumberFormat('en-US').format(display)">{{ $amount($line['committed']) }}</span>
                                    <span class="text-xs font-semibold {{ $mutedText }}">{{ __('donors.committed_lower') }}</span>
                                </p>
                                <p class="text-sm tabular-nums {{ $mutedText }}">
                                    <span class="font-extrabold text-funding">{{ $amount($line['received']) }}</span> {{ __('donors.received_lower') }} · <span class="font-bold {{ $inkText }}">{{ $currencyShare }}%</span>
                                </p>
                            </div>

                            <ol class="-mx-2 mt-4 flex flex-col {{ $isRoomy ? 'gap-1.5' : 'gap-0.5' }}" aria-label="{{ __('donors.share_of_currency', ['currency' => $line['currency']]) }}">
                                @foreach ($line['segments'] as $segment)
                                    @php
                                        $receivedShare = $segment['committed'] > 0 ? min(100, round($segment['received'] / $segment['committed'] * 100, 2)) : 0;
                                        $isRest = $segment['donorId'] === null;
                                        $rowHref = $isRest ? null : (in_array($segment['donorId'], $pageDonorIds, true) ? '#donor-'.$segment['donorId'] : route('donors.show', $segment['donorId']));
                                    @endphp
                                    <li wire:key="wallet-{{ $line['currency'] }}-{{ $segment['donorId'] ?? 'rest' }}">
                                        <a
                                            @if ($rowHref !== null) href="{{ $rowHref }}" @if (! str_starts_with($rowHref, '#')) wire:navigate @endif @endif
                                            x-on:mouseenter="focus = {{ $segment['donorId'] ?? 'null' }}"
                                            x-on:mouseleave="focus = null"
                                            :class="lit({{ $segment['donorId'] ?? 'null' }}) ? '' : 'opacity-40'"
                                            class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)_auto] items-center gap-3 rounded-lg px-2 {{ $isRoomy ? 'py-2.5' : 'py-1.5' }} transition-[background-color,opacity] duration-200 hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary @3xl/directory:grid-cols-[minmax(0,10rem)_minmax(0,1fr)_8rem] dark:hover:bg-white/5"
                                            aria-label="{{ __('donors.segment_label', ['name' => $segment['name'], 'currency' => $line['currency'], 'committed' => $amount($segment['committed']), 'received' => $amount($segment['received']), 'percent' => (int) round($segment['share'])]) }}"
                                            data-wallet-segment="{{ $segment['donorId'] ?? 'rest' }}"
                                        >
                                            <span class="truncate text-sm font-semibold {{ $isRest ? $mutedText : $inkText }}">{{ $segment['name'] }}</span>
                                            <span class="relative w-full {{ $isRoomy ? 'h-5' : 'h-3' }}" aria-hidden="true">
                                                <span class="wallet-grow absolute inset-y-0 start-0 overflow-hidden rounded-full {{ $isRest ? 'bg-secondary-soft dark:bg-secondary-medium' : 'bg-funding/20' }}" style="width: {{ max(1.5, round($segment['committed'] / $largest * 100, 2)) }}%; --wallet-order: {{ $loop->index }}">
                                                    <span class="absolute inset-y-0 start-0 rounded-full {{ $isRest ? 'bg-secondary-light' : 'bg-funding' }}" style="width: {{ $receivedShare }}%"></span>
                                                </span>
                                            </span>
                                            <span class="text-end text-sm tabular-nums">
                                                <span class="font-bold {{ $inkText }}">{{ $shortAmount($segment['committed']) }}</span>
                                                <span class="ms-1 inline-block w-9 text-xs {{ $mutedText }}">{{ (int) round($segment['share']) }}%</span>
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @empty
                        <p class="mt-4 text-sm {{ $mutedText }}">{{ __('donors.no_money_committed_yet') }}</p>
                    @endforelse
                </section>

                {{-- Right rail: transfers ahead and the donor mix. --}}
                <aside class="flex min-w-0 flex-col border-t {{ $hairline }} @5xl/directory:border-t-0 @5xl/directory:border-s">
                    <section aria-labelledby="transfers-heading" class="p-5 @3xl/directory:p-6" data-upcoming-transfers>
                        <h2 id="transfers-heading" class="text-base font-bold {{ $inkText }}">{{ __('donors.transfers_ahead') }}</h2>
                        @if ($this->upcomingTransfers->isEmpty())
                            <p class="mt-3 text-sm {{ $mutedText }}">{{ __('donors.no_transfer_scheduled_ahead') }}</p>
                        @else
                            <ol class="relative mt-4 flex flex-col gap-4" role="list">
                                <span class="absolute start-[0.28rem] top-2 bottom-2 w-px bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                                @foreach ($this->upcomingTransfers as $transfer)
                                    <li class="relative ps-6" wire:key="upcoming-{{ $transfer['agreementId'] }}-{{ $loop->index }}">
                                        <span @class(['absolute start-0 top-1 size-2.5 rounded-full ring-4 ring-white dark:ring-secondary-dark', 'bg-primary' => $transfer['overdue'], 'bg-funding' => ! $transfer['overdue']]) aria-hidden="true"></span>
                                        <div class="flex items-baseline justify-between gap-3">
                                            <p @class(['text-xs font-bold', 'text-status-critical' => $transfer['overdue'], $mutedText => ! $transfer['overdue']])>
                                                {{ $transfer['date']->translatedFormat(__('formats.date')) }}
                                                @if ($transfer['overdue'])
                                                    · {{ __('donors.overdue') }}
                                                @endif
                                            </p>
                                            <p class="shrink-0 text-sm font-extrabold tabular-nums {{ $inkText }}">{{ $shortAmount($transfer['amount']) }} <span class="text-[11px] font-semibold {{ $mutedText }}">{{ $transfer['currency'] }}</span></p>
                                        </div>
                                        <p class="mt-0.5 truncate text-sm">
                                            <a href="{{ route('donors.show', $transfer['donorId']) }}" wire:navigate class="font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $transfer['donor'] }}</a>
                                            <a href="{{ route('funding-agreements.show', $transfer['agreementId']) }}" wire:navigate class="text-xs underline-offset-4 hover:underline {{ $mutedText }}">· {{ $transfer['proposal'] }}</a>
                                        </p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </section>

                    <nav aria-label="{{ __('donors.donor_types') }}" class="mt-auto border-t p-5 {{ $hairline }} @3xl/directory:px-6" data-type-rail>
                        <h2 class="type-label {{ $mutedText }}">{{ __('donors.donor_mix') }}</h2>
                        @php
                            $typeMax = max(collect($this->typeRail)->max('count'), 1);
                            $shownTypes = collect($this->typeRail)->filter(fn (array $stop): bool => $stop['count'] > 0 || $stop['value'] === $type);
                            $unusedTypes = collect($this->typeRail)->diffKeys($shownTypes)->pluck('label');
                        @endphp
                        <ul class="-mx-2 mt-2 flex flex-col" role="list">
                            @foreach ($shownTypes as $stop)
                                @php
                                    $isSelected = $type === $stop['value'];
                                @endphp
                                <li wire:key="type-{{ $stop['value'] }}">
                                    <button
                                        type="button"
                                        wire:click="toggleType('{{ $stop['value'] }}')"
                                        aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                        @class([
                                            'grid w-full grid-cols-[7rem_minmax(0,1fr)_1.5rem] items-center gap-3 rounded-md px-2 py-1.5 text-start text-sm transition-colors hover:bg-board-head focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-white/5',
                                            'bg-primary/8 dark:bg-white/10' => $isSelected,
                                            'opacity-50' => $stop['count'] === 0 && ! $isSelected,
                                        ])
                                        data-type-stop="{{ $stop['value'] }}"
                                    >
                                        <span @class(['truncate font-semibold', 'text-status-critical' => $isSelected, $inkText => ! $isSelected])>{{ $stop['label'] }}</span>
                                        <span class="h-1.5 rounded-full bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true">
                                            <span class="block h-full rounded-full {{ $isSelected ? 'bg-primary' : 'bg-secondary-dark dark:bg-white' }}" style="width: {{ round($stop['count'] / $typeMax * 100) }}%"></span>
                                        </span>
                                        <span class="text-end font-extrabold tabular-nums {{ $inkText }}">{{ $stop['count'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($unusedTypes->isNotEmpty())
                            <p class="mt-2 text-xs {{ $mutedText }}">{{ __('donors.no_donors_of_type', ['types' => $unusedTypes->implode(', ')]) }}</p>
                        @endif
                    </nav>
                </aside>
            </div>
        </div>

        {{-- The ledger: aligned columns, one donor per row. --}}
        <section aria-labelledby="ledger-heading" class="pt-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="ledger-heading" class="text-lg font-bold {{ $inkText }}">{{ __('donors.every_donor_and_what_they_fund') }}</h2>
                <p class="flex flex-wrap items-baseline gap-x-3 text-xs {{ $mutedText }}">
                    @if ($pulse['followUps'] > 0 && $sort !== 'follow_up')
                        <button type="button" wire:click="$set('sort', 'follow_up')" class="font-semibold text-status-critical underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary" data-follow-ups-due>
                            {{ trans_choice('donors.count_follow_ups_due', $pulse['followUps'], ['count' => $pulse['followUps']]) }}
                        </button>
                    @endif
                    <span>{{ trans_choice('donors.count_matching_donor', $this->donors->total(), ['count' => $this->donors->total()]) }}</span>
                </p>
            </div>

            @if ($this->donors->isEmpty())
                <x-ui.empty-state icon="building-library" :heading="__('donors.no_matching_donors')" :body="__('common.change_the_search_or_filters_and_try_again')" />
            @else
                @php
                    $columns = '@4xl/directory:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.4fr)_8.5rem_7.25rem]';
                @endphp
                <div class="mt-3 overflow-hidden rounded-2xl border {{ $hairline }}">
                    <div class="hidden gap-x-8 border-b bg-board-head/70 px-5 py-2.5 {{ $hairline }} {{ $columns }} @4xl/directory:grid dark:bg-white/[0.03]" aria-hidden="true">
                        <span class="type-label {{ $mutedText }}">{{ __('donors.donor') }}</span>
                        <span class="type-label {{ $mutedText }}">{{ __('donors.committed') }}</span>
                        <span class="type-label {{ $mutedText }}">{{ __('donors.funds') }}</span>
                        <span class="type-label {{ $mutedText }}">{{ __('donors.next_transfer') }}</span>
                        <span></span>
                    </div>

                    @foreach ($this->donors as $donor)
                        @php
                            $money = $this->donorMoney($donor);
                            $isProspect = $donor->donor_proposals_count === 0;
                            $shownAgreements = $donor->donorProposals->take(3);
                            $hiddenAgreements = $donor->donorProposals->count() - $shownAgreements->count();
                        @endphp
                        <article
                            id="donor-{{ $donor->id }}"
                            wire:key="donor-{{ $donor->id }}"
                            x-on:mouseenter="focus = {{ $donor->id }}"
                            x-on:mouseleave="focus = null"
                            :class="focus === {{ $donor->id }} ? 'bg-board-head/70 dark:bg-white/[0.04]' : (lit({{ $donor->id }}) ? '' : 'opacity-45')"
                            class="logframe-reveal grid scroll-mt-24 items-start gap-x-8 gap-y-4 border-b px-5 py-5 transition-[background-color,opacity] duration-200 last:border-b-0 target:bg-board-head {{ $hairline }} {{ $columns }}"
                            style="--logframe-order: {{ $loop->index }}"
                            data-donor-row
                        >
                            {{-- Identity and contact --}}
                            <div class="flex min-w-0 gap-3">
                                <span @class([
                                    'flex size-10 shrink-0 items-center justify-center rounded-full text-xs font-extrabold',
                                    'bg-secondary-dark text-white dark:bg-white dark:text-secondary-dark' => ! $isProspect,
                                    'border border-dashed border-secondary-light text-secondary-medium dark:text-secondary-soft' => $isProspect,
                                ]) aria-hidden="true">{{ $initials($donor->name) }}</span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-base leading-tight font-bold {{ $inkText }}">{{ $donor->name }}</span>
                                        <x-ui.enum-badge :case="$donor->type" />
                                    </div>
                                    <p class="mt-0.5 truncate text-xs {{ $mutedText }}">
                                        {{ collect([$donor->country, $donor->contact_person])->filter()->implode(' · ') ?: __('donors.no_contact_on_file') }}
                                    </p>
                                    @if (filled($donor->email) || filled($donor->phone))
                                        <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                            @if (filled($donor->email))
                                                <a href="mailto:{{ $donor->email }}" class="inline-flex min-w-0 max-w-full items-center gap-1 font-medium underline-offset-4 hover:underline {{ $inkText }}" title="{{ $donor->email }}">
                                                    <flux:icon.envelope variant="micro" class="size-3.5 shrink-0 text-secondary-light" /><span class="truncate">{{ $donor->email }}</span>
                                                </a>
                                            @endif
                                            @if (filled($donor->phone))
                                                <a href="tel:{{ preg_replace('/[^\d+]/', '', $donor->phone) }}" dir="ltr" class="inline-flex items-center gap-1 font-medium tabular-nums underline-offset-4 hover:underline {{ $inkText }}">
                                                    <flux:icon.phone variant="micro" class="size-3.5 text-secondary-light" />{{ $donor->phone }}
                                                </a>
                                            @endif
                                        </p>
                                    @endif
                                    @if (filled($donor->next_step))
                                        <p @class(['mt-1.5 flex min-w-0 items-start gap-1 text-xs', 'text-status-critical' => $donor->isFollowUpOverdue(), $inkText => ! $donor->isFollowUpOverdue()]) data-follow-up>
                                            <flux:icon.arrow-uturn-right variant="micro" class="mt-px size-3.5 shrink-0 rtl:-scale-x-100 {{ $donor->isFollowUpOverdue() ? '' : 'text-secondary-light' }}" aria-hidden="true" />
                                            <span class="min-w-0">
                                                <span class="font-semibold">{{ $donor->next_step }}</span>
                                                @if ($donor->next_step_due_on !== null)
                                                    · <span class="tabular-nums">{{ $donor->isFollowUpOverdue() ? __('donors.follow_up_was_due', ['date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))]) : __('donors.follow_up_due', ['date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))]) }}</span>
                                                @endif
                                            </span>
                                        </p>
                                    @endif
                                    @if ($donor->last_contacted_on !== null)
                                        <p class="mt-1 text-[11px] {{ $mutedText }}">{{ __('donors.last_contacted', ['date' => $donor->last_contacted_on->translatedFormat(__('formats.date'))]) }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Money --}}
                            <div class="min-w-0" data-connection="money">
                                <p class="type-label mb-1 {{ $mutedText }} @4xl/directory:hidden">{{ __('donors.committed') }}</p>
                                @forelse ($money['lines'] as $currency => $line)
                                    <div class="[&+&]:mt-3" wire:key="donor-{{ $donor->id }}-money-{{ $currency }}">
                                        <p class="flex items-baseline justify-between gap-3">
                                            <span class="text-lg leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $amount($line['committed']) }} <span class="text-[11px] font-semibold tracking-normal {{ $mutedText }}">{{ $currency }}</span></span>
                                            <span class="text-xs font-bold tabular-nums text-funding">{{ $line['share'] }}%</span>
                                        </p>
                                        <div class="mt-2 flex h-1.5 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-secondary-medium" role="img" aria-label="{{ __('donors.money_bar_label', ['currency' => $currency, 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                                            <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $line['committed'] > 0 ? min(100, round($line['received'] / $line['committed'] * 100, 2)) : 0 }}%"></span>
                                            <span class="money-grow money-pending h-full" style="width: {{ $line['committed'] > 0 ? min(100, round($line['pending'] / $line['committed'] * 100, 2)) : 0 }}%"></span>
                                        </div>
                                        <p class="mt-1 text-[11px] tabular-nums {{ $mutedText }}">
                                            <span class="font-bold text-funding">{{ $shortAmount($line['received']) }}</span> {{ __('donors.received_lower') }}
                                            @if ($line['pending'] > 0)
                                                · <span class="font-bold text-status-warn">{{ $shortAmount($line['pending']) }}</span> {{ __('donors.pending_lower') }}
                                            @endif
                                        </p>
                                    </div>
                                @empty
                                    <p class="text-sm {{ $mutedText }}">{{ __('donors.prospect_no_agreement_yet') }}</p>
                                @endforelse
                            </div>

                            {{-- Proposals on a thread --}}
                            <div class="min-w-0" data-connection="proposals">
                                <p class="type-label mb-1 {{ $mutedText }} @4xl/directory:hidden">{{ __('donors.funds') }}</p>
                                @if ($shownAgreements->isEmpty())
                                    <p class="text-sm {{ $mutedText }}">{{ __('donors.nothing_funded_yet') }}</p>
                                @else
                                    <ul class="relative flex flex-col gap-2" role="list">
                                        <span class="absolute start-[0.1875rem] top-2 bottom-2 w-px bg-secondary-soft dark:bg-secondary-medium" aria-hidden="true"></span>
                                        @foreach ($shownAgreements as $agreement)
                                            <li class="relative flex items-start gap-3 ps-5" wire:key="donor-{{ $donor->id }}-agreement-{{ $agreement->id }}">
                                                <span class="absolute start-0 top-1.5 size-2 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>
                                                <span class="min-w-0 flex-1">
                                                    <a href="{{ route('proposals.show', $agreement->proposal_id) }}" wire:navigate class="block truncate text-sm leading-snug font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->proposal?->name }}</a>
                                                    <span class="block truncate text-[11px] {{ $mutedText }}">
                                                        {{ $agreement->proposal?->status->label() }}
                                                        @if ($agreement->proposal?->projects->isNotEmpty())
                                                            · {{ trans_choice('common.count_project', $agreement->proposal->projects->count(), ['count' => $agreement->proposal->projects->count()]) }}
                                                        @endif
                                                    </span>
                                                </span>
                                                <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="shrink-0 pt-px text-xs font-bold tabular-nums underline-offset-4 hover:underline {{ $inkText }}" aria-label="{{ __('donors.view_agreement_for', ['name' => $agreement->proposal?->name]) }}">{{ $shortAmount((float) $agreement->budget) }} <span class="font-semibold {{ $mutedText }}">{{ $agreement->currency->value }}</span></a>
                                            </li>
                                        @endforeach
                                        @if ($hiddenAgreements > 0)
                                            <li class="ps-5">
                                                <a href="{{ route('donors.show', $donor) }}" wire:navigate class="text-xs font-semibold underline-offset-4 hover:underline {{ $mutedText }}">{{ trans_choice('donors.and_count_more', $hiddenAgreements, ['count' => $hiddenAgreements]) }}</a>
                                            </li>
                                        @endif
                                    </ul>
                                @endif
                            </div>

                            {{-- Next transfer --}}
                            <div class="min-w-0" data-connection="transfers">
                                <p class="type-label mb-1 {{ $mutedText }} @4xl/directory:hidden">{{ __('donors.next_transfer') }}</p>
                                @if ($money['next'] !== null)
                                    <p @class(['text-sm font-bold', 'text-status-critical' => $money['next']['overdue'], $inkText => ! $money['next']['overdue']])>{{ $money['next']['date']->translatedFormat(__('formats.date')) }}</p>
                                    <p class="text-xs tabular-nums {{ $mutedText }}">{{ $money['next']['currency'] }} {{ $shortAmount($money['next']['amount']) }}</p>
                                    @if ($money['overdue'] > 0)
                                        <p class="mt-1 text-[11px] font-semibold text-status-critical">{{ trans_choice('donors.count_transfers_overdue', $money['overdue'], ['count' => $money['overdue']]) }}</p>
                                    @endif
                                @else
                                    <p class="text-sm {{ $mutedText }}">{{ __('donors.none_scheduled') }}</p>
                                @endif
                                @if ($money['lastReceived'] !== null)
                                    <p class="mt-1 text-[11px] {{ $mutedText }}">{{ __('donors.last_received_on', ['date' => $money['lastReceived']->translatedFormat(__('formats.date'))]) }}</p>
                                @endif
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-0.5 @4xl/directory:justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-9! touch-manipulation" :href="route('donors.show', $donor)" wire:navigate :aria-label="__('common.view_name', ['name' => $donor->name])" />
                                @can('donors.update')
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-9! touch-manipulation" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('common.edit_name', ['name' => $donor->name])" />
                                @endcan
                                @can('donors.delete')
                                @if ($isProspect)
                                    <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-9! touch-manipulation" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('common.delete_name', ['name' => $donor->name])" />
                                @else
                                    <flux:tooltip :content="__('donors.donors_holding_an_agreement_cannot_be_deleted')">
                                        <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                            <flux:icon.lock-closed class="size-5" />
                                        </span>
                                    </flux:tooltip>
                                @endif
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="mt-3">
                <flux:pagination :paginator="$this->donors" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#donor-directory-heading" />
            </div>
        </section>
    @endif

    <x-donors.form-modal :editing="$editingDonorId !== null" :type-options="$this->donorTypeOptions" />

    <flux:modal wire:model="showDeleteModal" wire:close="cancelDelete" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('donors.delete_this_donor') }}</flux:heading>
            <flux:subheading>{{ __('donors.only_a_donor_holding_no_funding_agreement_can_be_deleted') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="cancelDelete">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteDonor" data-test="confirm-delete-donor">{{ __('donors.delete_donor') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
