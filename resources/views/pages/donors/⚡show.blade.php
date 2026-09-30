<?php

use App\Enums\ArStatus;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesDonorForm;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Report;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.donor')] class extends Component {
    use ManagesAgreementForm;
    use ManagesDonorForm;

    /** How many reports each agreement lists before pointing at the rest. */
    public const LISTED_REPORTS = 5;

    #[Locked]
    public int $donorId;

    public function mount(Donor $donor): void
    {
        $this->donorId = $donor->id;
    }

    #[Computed]
    public function donor(): Donor
    {
        return Donor::query()
            ->select(['id', 'name', 'type', 'contact_person', 'email', 'phone', 'country', 'notes', 'last_contacted_on', 'next_step', 'next_step_due_on', 'updated_at'])
            ->withCount('donorProposals')
            ->findOrFail($this->donorId);
    }

    /**
     * Every agreement this donor holds, with the proposal behind it, the
     * projects that proposal funds and every tranche paid against it.
     *
     * Empty for a viewer without funding-agreements.view, and every money
     * reading below is derived from it, so none of them is ever computed.
     * A viewer without transfers.view gets the agreements with no tranches.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): Collection
    {
        if (! auth()->user()?->can('funding-agreements.view')) {
            return new Collection;
        }

        $canViewTransfers = (bool) auth()->user()->can('transfers.view');

        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num'])
            ->where('donor_id', $this->donorId)
            ->with([
                'proposal:id,name,status',
                'proposal.projects:id,name,status',
                'transfers' => fn ($query) => $query
                    ->select(['id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received', 'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status'])
                    ->when(! $canViewTransfers, fn ($query) => $query->whereRaw('1 = 0'))
                    ->orderByRaw('coalesce(receive_date, scheduled_date) is null')
                    ->orderByRaw('coalesce(receive_date, scheduled_date)')
                    ->orderBy('id'),
                'transfers.account:id,account_name',
                // The reports this donor is still owed under each agreement.
                'reports' => fn ($query) => $query
                    ->select(['id', 'donor_proposal_id', 'title', 'type', 'due_on', 'submitted_on'])
                    ->whereNull('submitted_on')
                    ->orderBy('due_on')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Where one tranche stands, read from the vocabulary every funding page
     * shares (TransfersTracking::state()): money the donor has sent is in
     * transit however far past its date, and only unsent money is overdue.
     *
     * @return 'received'|'failed'|'transit'|'overdue'|'due'|'undated'
     */
    protected function transferState(TransfersTracking $transfer): string
    {
        return match ($transfer->state()) {
            'received' => 'received',
            'failed' => 'failed',
            'transit' => 'transit',
            'late' => 'overdue',
            default => $transfer->scheduled_date === null ? 'undated' : 'due',
        };
    }

    /**
     * The money position of one agreement in its own currency.
     *
     * Received counts only tranches with status "received" that landed in the
     * agreement's currency; a pending tranche already carries a net amount, so
     * that column alone is not proof of money in. A received tranche with no
     * net recorded counts as nothing landed and is counted in `missingNet`.
     * Money in transit is still due, never overdue. Failed money is its own
     * figure: it was scheduled, so it is not "unscheduled", and it did not
     * land. Only tranches sent in the agreement's own currency are set against
     * its budget; unscheduled is the part of the budget no tranche covers yet.
     *
     * @return array{committed: float, received: float, due: float, overdue: float, failed: float, unscheduled: float, missingNet: int}
     */
    protected function agreementMoney(DonorProposal $agreement): array
    {
        $committed = (float) $agreement->budget;
        $money = ['committed' => $committed, 'received' => 0.0, 'due' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 0.0, 'missingNet' => 0];
        $covered = 0.0;

        foreach ($agreement->transfers as $transfer) {
            $state = $this->transferState($transfer);

            if ($transfer->amount_transfered_currency !== $agreement->currency) {
                continue;
            }

            $covered += (float) $transfer->payment_amount;

            if ($state === 'failed') {
                $money['failed'] += (float) $transfer->payment_amount;
            } elseif ($state === 'received') {
                if ($transfer->isMissingNetAmount()) {
                    $money['missingNet']++;
                } elseif ($transfer->amount_received_currency === $agreement->currency) {
                    $money['received'] += (float) $transfer->net_amount_received;
                }
            } elseif ($state === 'overdue') {
                $money['overdue'] += (float) $transfer->payment_amount;
            } else {
                $money['due'] += (float) $transfer->payment_amount;
            }
        }

        $money['unscheduled'] = max(0.0, $committed - $covered);

        return $money;
    }

    /**
     * The money position per currency. Grouped, never summed across currencies.
     *
     * @return array<string, array{committed: float, received: float, due: float, overdue: float, failed: float, unscheduled: float, missingNet: int}>
     */
    #[Computed]
    public function moneyByCurrency(): array
    {
        $totals = [];

        foreach ($this->agreements as $agreement) {
            $currency = $agreement->currency->value;
            $totals[$currency] ??= ['committed' => 0.0, 'received' => 0.0, 'due' => 0.0, 'overdue' => 0.0, 'failed' => 0.0, 'unscheduled' => 0.0, 'missingNet' => 0];

            foreach ($this->agreementMoney($agreement) as $key => $value) {
                $totals[$currency][$key] += $value;
            }
        }

        return $totals;
    }

    /**
     * Money that landed in a currency other than its agreement's, per landing
     * currency. Shown beside the position rather than converted into it.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function receivedElsewhere(): array
    {
        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->ar_status === ArStatus::Received
                    && $transfer->amount_received_currency !== null
                    && $transfer->amount_received_currency !== $agreement->currency))
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
            ->map(fn ($group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
            ->all();
    }

    /**
     * Unsent tranches whose scheduled date has passed, oldest first.
     *
     * @return list<array{agreement: DonorProposal, transfer: TransfersTracking, days: int}>
     */
    #[Computed]
    public function overdueTranches(): array
    {
        $today = CarbonImmutable::today();

        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $this->transferState($transfer) === 'overdue')
                ->map(fn (TransfersTracking $transfer): array => [
                    'agreement' => $agreement,
                    'transfer' => $transfer,
                    'days' => (int) $transfer->scheduled_date->diffInDays($today),
                ]))
            ->sortBy(fn (array $item) => $item['transfer']->scheduled_date)
            ->values()
            ->all();
    }

    /**
     * The next unsent tranche due today or later.
     *
     * @return array{agreement: DonorProposal, transfer: TransfersTracking}|null
     */
    #[Computed]
    public function nextTranche(): ?array
    {
        return $this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $this->transferState($transfer) === 'due')
                ->map(fn (TransfersTracking $transfer): array => ['agreement' => $agreement, 'transfer' => $transfer]))
            ->sortBy(fn (array $item) => $item['transfer']->scheduled_date)
            ->first();
    }

    /**
     * Distinct projects funded through this donor's proposals. A project two
     * of the donor's proposals fund is counted once.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projects(): Collection
    {
        return new Collection($this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->proposal->projects)
            ->unique('id')
            ->values()
            ->all());
    }

    /**
     * @return array{open: int, closed: int}
     */
    #[Computed]
    public function projectCounts(): array
    {
        $open = $this->projects->filter(fn (Project $project): bool => $project->status->isOpen())->count();

        return ['open' => $open, 'closed' => $this->projects->count() - $open];
    }

    /**
     * Reports filed against targets committed under each of this donor's
     * proposals, newest first, keyed by proposal id.
     *
     * @return array<int, Collection<int, Report>>
     */
    #[Computed]
    public function reportsByProposal(): array
    {
        $proposalIds = $this->agreements->pluck('proposal_id')->unique();

        if ($proposalIds->isEmpty()) {
            return [];
        }

        return Report::query()
            ->select(['reports.id', 'reports.plan_indicator_id', 'reports.status', 'reports.planned_date', 'reports.submit_date', 'plan_indicators.source_id as proposal_id'])
            ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
            ->where('plan_indicators.source_type', 'proposal')
            ->whereIn('plan_indicators.source_id', $proposalIds)
            ->with('planIndicator:id,indicator_id', 'planIndicator.indicator:id,name')
            ->orderByDesc('reports.planned_date')
            ->orderByDesc('reports.id')
            ->get()
            ->groupBy('proposal_id')
            ->all();
    }

    #[Computed]
    public function reportsSubmittedCount(): int
    {
        return collect($this->reportsByProposal)->sum(fn ($reports): int => $reports->count());
    }

    /**
     * Every dated tranche placed on one shared time axis, one lane per
     * agreement, with today marked. Positions are percentages of the span.
     *
     * @return array{lanes: list<array{agreement: DonorProposal, points: list<array<string, mixed>>}>, ticks: list<array{label: string, position: float}>, today: float}|null
     */
    #[Computed]
    public function calendar(): ?array
    {
        $today = CarbonImmutable::today();
        $dated = $this->agreements->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
            ->map(fn (TransfersTracking $transfer) => $transfer->receive_date ?? $transfer->scheduled_date)
            ->filter());

        if ($dated->isEmpty()) {
            return null;
        }

        $start = CarbonImmutable::parse($dated->push($today)->min())->startOfMonth();
        $end = CarbonImmutable::parse($dated->push($today)->max())->endOfMonth();
        $span = max(1, $start->diffInSeconds($end));
        $position = fn (CarbonImmutable $date): float => round($start->diffInSeconds($date) / $span * 100, 3);
        $largest = max(1.0, (float) $this->agreements->flatMap(fn (DonorProposal $agreement) => $agreement->transfers)->max('payment_amount'));

        $lanes = $this->agreements->map(fn (DonorProposal $agreement): array => [
            'agreement' => $agreement,
            'points' => $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => ($transfer->receive_date ?? $transfer->scheduled_date) !== null)
                ->map(function (TransfersTracking $transfer) use ($agreement, $position, $largest): array {
                    $state = $this->transferState($transfer);
                    $isReceived = $state === 'received';
                    $date = CarbonImmutable::parse($transfer->receive_date ?? $transfer->scheduled_date);

                    return [
                        'id' => $transfer->id,
                        'state' => $state,
                        'date' => $date,
                        'position' => $position($date),
                        'amount' => (float) ($isReceived ? $transfer->net_amount_received : $transfer->payment_amount),
                        'currency' => ($isReceived ? $transfer->amount_received_currency : $transfer->amount_transfered_currency)?->value ?? $agreement->currency->value,
                        'size' => round(0.625 + ((float) $transfer->payment_amount / $largest) * 0.75, 3),
                    ];
                })
                ->sortBy('position')
                ->values()
                ->all(),
        ])->values()->all();

        $months = (int) ceil($start->diffInMonths($end));
        $step = max(1, (int) ceil($months / 8));
        $ticks = [];

        for ($tick = $start; $tick->lte($end); $tick = $tick->addMonths($step)) {
            $ticks[] = ['label' => $tick->translatedFormat(__('formats.month_year')), 'position' => $position($tick)];
        }

        return ['lanes' => $lanes, 'ticks' => $ticks, 'today' => $position($today)];
    }

    /**
     * This page edits its own donor and no other, whatever the payload says.
     */
    protected function donorFormScope(): ?int
    {
        return $this->donorId;
    }

    /**
     * Agreements recorded here belong to this donor, whatever the payload says.
     */
    protected function agreementDonorScope(): ?int
    {
        return $this->donorId;
    }

    /**
     * Open the agreement form with this donor already chosen: the fundraiser
     * who just closed a deal records it from the donor they closed it with.
     */
    public function recordAgreementForDonor(): void
    {
        $this->openAgreementCreate();
    }

    protected function agreementSaved(DonorProposal $agreement, bool $isCreate): void
    {
        unset($this->donor, $this->agreements, $this->moneyByCurrency, $this->receivedElsewhere, $this->overdueTranches, $this->nextTranche, $this->projects, $this->projectCounts, $this->reportsByProposal, $this->reportsSubmittedCount, $this->calendar);
    }

    protected function agreementDeleted(int $agreementId): void
    {
        $this->agreementSaved(new DonorProposal, false);
    }

    protected function donorSaved(Donor $donor, bool $isCreate): void
    {
        unset($this->donor);

        Flux::toast(variant: 'success', text: __('donors.donor_updated'));
    }
};
?>

@php
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $amount = fn (float $value): string => number_format($value, 2);
    $formatDate = fn (?\DateTimeInterface $date): string => $date?->translatedFormat(__('formats.date')) ?? '—';
    $percentOf = fn (float $part, float $whole): float => $whole > 0 ? min(100, round($part / $whole * 100, 2)) : 0.0;
    $donor = $this->donor;
    $stateLabels = [
        'received' => __('donors.received'),
        'due' => __('donors.due_ahead'),
        'overdue' => __('donors.overdue'),
        'failed' => ArStatus::Failed->label(),
        'undated' => __('common.unscheduled'),
        'transit' => __('funding_agreements.tranche_state_transit'),
    ];
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" data-donor-dossier>
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.donors'), 'url' => route('donors.index')],
            ['label' => $donor->name],
        ]"
        :title="$donor->name"
        title-class="text-3xl! font-bold! leading-none! tracking-tight! @3xl/detail:text-[2.5rem]!"
    >
        <x-slot:meta>
            @if ($donor->type !== null)
                <x-ui.enum-badge :case="$donor->type" />
            @else
                <span>{{ __('donors.not_classified') }}</span>
            @endif
            @if (filled($donor->country))
                <span class="inline-flex items-center gap-1"><flux:icon.map-pin variant="micro" class="size-3.5" aria-hidden="true" />{{ $donor->country }}</span>
            @endif
            @can('funding-agreements.view')
                <span>{{ trans_choice('common.count_agreement', $donor->donor_proposals_count, ['count' => $donor->donor_proposals_count]) }}</span>
            @endcan
        </x-slot:meta>

        <x-slot:actions>
            @can('donors.update')
            <x-ui.button tone="primary" icon="pencil-square" class="min-h-11 shrink-0 touch-manipulation @3xl/detail:order-last @3xl/detail:min-h-10" wire:click="openEditModal({{ $donor->id }})" data-test="edit-donor-button">
                {{ __('donors.edit_donor') }}
            </x-ui.button>
            @endcan
            <address class="flex flex-col gap-1 text-sm not-italic @3xl/detail:items-end" data-donor-contact>
                @if (filled($donor->contact_person) || filled($donor->email) || filled($donor->phone))
                    @if (filled($donor->contact_person))
                        <span class="inline-flex items-center gap-1.5 font-semibold {{ $inkText }}"><flux:icon.user variant="micro" class="size-4 {{ $mutedText }}" aria-hidden="true" />{{ $donor->contact_person }}</span>
                    @endif
                    <span class="flex flex-wrap items-center gap-x-4 gap-y-1 {{ $mutedText }}">
                        @if (filled($donor->email))
                            <a href="mailto:{{ $donor->email }}" class="inline-flex items-center gap-1.5 underline-offset-4 hover:underline hover:text-secondary-dark dark:hover:text-ink"><flux:icon.envelope variant="micro" class="size-4" aria-hidden="true" />{{ $donor->email }}</a>
                        @endif
                        @if (filled($donor->phone))
                            <a href="tel:{{ preg_replace('/[^\d+]/', '', $donor->phone) }}" dir="ltr" class="inline-flex items-center gap-1.5 tabular-nums underline-offset-4 hover:underline hover:text-secondary-dark dark:hover:text-ink"><flux:icon.phone variant="micro" class="size-4" aria-hidden="true" />{{ $donor->phone }}</a>
                        @endif
                    </span>
                @else
                    <span class="{{ $mutedText }}">{{ __('donors.no_contact_on_file') }}</span>
                @endif
            </address>
        </x-slot:actions>
    </x-ui.detail-header>

    @if (filled($donor->notes))
        <p class="mt-4 max-w-[70ch] text-sm leading-relaxed {{ $mutedText }}">{{ $donor->notes }}</p>
    @endif

    {{-- The follow-up: when we last spoke, and what we owe this donor next. --}}
    <div @class(['mt-5 flex flex-wrap items-baseline gap-x-6 gap-y-2 border-s-2 ps-4 text-sm', 'border-primary' => $donor->isFollowUpOverdue(), $hairline => ! $donor->isFollowUpOverdue()]) data-follow-up>
        @if (filled($donor->next_step))
            <p class="min-w-0">
                <span class="type-label me-2 {{ $mutedText }}">{{ __('donors.next_step') }}</span>
                <span class="font-semibold {{ $inkText }}">{{ $donor->next_step }}</span>
                @if ($donor->next_step_due_on !== null)
                    <span @class(['ms-1 tabular-nums', 'font-semibold text-status-critical' => $donor->isFollowUpOverdue(), $mutedText => ! $donor->isFollowUpOverdue()])>
                        · {{ $donor->isFollowUpOverdue() ? __('donors.follow_up_was_due', ['date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))]) : __('donors.follow_up_due', ['date' => $donor->next_step_due_on->translatedFormat(__('formats.date'))]) }}
                    </span>
                @endif
            </p>
        @else
            <p class="{{ $mutedText }}">{{ __('donors.no_next_step_planned') }}</p>
        @endif
        <p class="text-xs tabular-nums {{ $mutedText }}">
            {{ $donor->last_contacted_on !== null ? __('donors.last_contacted', ['date' => $donor->last_contacted_on->translatedFormat(__('formats.date'))]) : __('donors.never_contacted') }}
        </p>
    </div>

    @can('funding-agreements.view')
    @if ($this->agreements->isEmpty())
        <div class="mt-10">
            <x-ui.empty-state icon="banknotes" :heading="__('common.no_agreements_yet')" :body="__('donors.agreements_signed_by_this_donor_will_appear_here')" />
            @can('funding-agreements.create')
                <div class="mt-4 flex justify-center">
                    <x-ui.button tone="primary" size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="recordAgreementForDonor" data-test="record-agreement-for-donor">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                </div>
            @endcan
        </div>
    @else
        {{-- Late money first: every overdue tranche, each opening the agreement that holds it. --}}
        @if ($this->overdueTranches !== [])
            <section class="mt-7 border-s-2 border-primary ps-4" aria-labelledby="overdue-heading" data-overdue>
                <h2 id="overdue-heading" class="type-label text-status-critical">{{ trans_choice('donors.overdue_heading', count($this->overdueTranches), ['count' => count($this->overdueTranches)]) }}</h2>
                <ul class="mt-2 flex flex-col gap-1">
                    @foreach ($this->overdueTranches as $item)
                        <li wire:key="overdue-{{ $item['transfer']->id }}" data-overdue-tranche="{{ $item['transfer']->id }}">
                            <a href="{{ route('funding-agreements.show', $item['agreement']) }}" wire:navigate class="group flex min-h-8 flex-wrap items-baseline gap-x-3 gap-y-0.5 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                <span class="font-bold tabular-nums {{ $inkText }}">{{ $amount((float) $item['transfer']->payment_amount) }} <span class="text-xs font-semibold {{ $mutedText }}">{{ $item['transfer']->amount_transfered_currency?->value ?? $item['agreement']->currency->value }}</span></span>
                                <span class="min-w-0 underline-offset-4 group-hover:underline {{ $inkText }}">{{ __('donors.overdue_line', ['proposal' => $item['agreement']->proposal->name, 'date' => $item['transfer']->scheduled_date->translatedFormat(__('formats.date'))]) }}</span>
                                <span class="text-xs font-semibold tabular-nums text-status-critical">{{ trans_choice('donors.days_late', $item['days'], ['count' => $item['days']]) }}</span>
                                <flux:icon.arrow-right variant="micro" class="size-4 self-center {{ $mutedText }} rtl:rotate-180" aria-hidden="true" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- The money river: one per currency, committed split into what landed, what is coming, what is late and what nobody has scheduled. --}}
        <section class="mt-9 flex flex-col gap-10" aria-label="{{ __('donors.money_position') }}" data-money-river>
            @foreach ($this->moneyByCurrency as $currency => $line)
                @php
                    $landedPercent = App\Models\DonorProposal::percentLabel($line['received'], $line['committed']);
                    $parts = [
                        ['key' => 'received', 'label' => __('donors.received'), 'value' => $line['received'], 'bar' => 'bg-funding', 'dot' => 'bg-funding', 'text' => 'text-funding'],
                        ['key' => 'due', 'label' => __('donors.due_ahead'), 'value' => $line['due'], 'bar' => 'money-pending', 'dot' => 'money-pending', 'text' => 'text-status-warn'],
                        ['key' => 'overdue', 'label' => __('donors.overdue'), 'value' => $line['overdue'], 'bar' => 'bg-primary dark:bg-status-critical', 'dot' => 'bg-primary dark:bg-status-critical', 'text' => 'text-status-critical'],
                        ...($line['failed'] > 0 ? [['key' => 'failed', 'label' => ArStatus::Failed->label(), 'value' => $line['failed'], 'bar' => 'money-failed', 'dot' => 'money-failed', 'text' => 'text-status-critical']] : []),
                        ['key' => 'unscheduled', 'label' => __('donors.not_yet_scheduled'), 'value' => $line['unscheduled'], 'bar' => '', 'dot' => 'bg-secondary-soft dark:bg-line-strong', 'text' => $mutedText],
                    ];
                @endphp
                <div class="min-w-0" wire:key="river-{{ $currency }}" data-river="{{ $currency }}">
                    <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-3">
                        <div class="min-w-0">
                            <p class="type-label {{ $mutedText }}">{{ __('donors.committed_currency', ['currency' => $currency]) }}</p>
                            <p class="mt-2 flex flex-wrap items-baseline gap-x-2 text-5xl leading-none font-extrabold tracking-tight tabular-nums @3xl/detail:text-6xl {{ $inkText }}">
                                <span>{{ $amount($line['committed']) }}</span>
                                <span class="text-xl font-bold tracking-normal {{ $mutedText }}">{{ $currency }}</span>
                            </p>
                        </div>
                        <p class="flex items-baseline gap-2 text-funding">
                            <span class="text-5xl leading-none font-extrabold tracking-tight tabular-nums @3xl/detail:text-6xl" data-landed-percent>{{ $landedPercent }}<span class="text-2xl">%</span></span>
                            <span class="text-sm font-semibold">{{ __('donors.landed') }}</span>
                        </p>
                    </div>

                    <div
                        class="mt-5 flex h-4 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2"
                        role="img"
                        aria-label="{{ __('donors.river_label', ['currency' => $currency, 'received' => $amount($line['received']), 'due' => $amount($line['due']), 'overdue' => $amount($line['overdue']), 'committed' => $amount($line['committed'])]) }}"
                    >
                        @foreach ($parts as $part)
                            @if ($part['bar'] !== '' && $part['value'] > 0)
                                <span class="money-grow h-full first:rounded-s-full {{ $part['bar'] }}" style="width: {{ $percentOf($part['value'], $line['committed']) }}%"></span>
                            @endif
                        @endforeach
                    </div>

                    <dl @class(['mt-5 grid grid-cols-2 gap-x-6 gap-y-5', '@3xl/detail:grid-cols-4' => count($parts) === 4, '@3xl/detail:grid-cols-5' => count($parts) === 5])>
                        @foreach ($parts as $part)
                            <div class="min-w-0 border-t pt-3 {{ $hairline }}" data-river-part="{{ $part['key'] }}">
                                <dt class="flex items-center gap-2 text-xs font-semibold {{ $mutedText }}">
                                    <span class="size-2.5 shrink-0 rounded-full {{ $part['dot'] }}" aria-hidden="true"></span>
                                    {{ $part['label'] }}
                                </dt>
                                <dd @class(['mt-1.5 truncate text-xl font-bold tabular-nums @5xl/detail:text-2xl', $part['value'] > 0 ? $part['text'] : $mutedText])>{{ $amount($part['value']) }}</dd>
                                <dd class="mt-0.5 text-xs tabular-nums {{ $mutedText }}">{{ __('donors.share_of_committed', ['percent' => round($percentOf($part['value'], $line['committed']))]) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach

            @foreach ($this->receivedElsewhere as $currency => $total)
                <p class="-mt-6 text-xs {{ $mutedText }}" wire:key="elsewhere-{{ $currency }}">{{ __('donors.received_in_other_currency', ['currency' => $currency, 'amount' => $amount($total)]) }}</p>
            @endforeach

            @if (count($this->moneyByCurrency) > 1)
                <p class="-mt-6 text-xs {{ $mutedText }}">{{ __('donors.budgets_are_shown_per_currency_and_never_added_together') }}</p>
            @endif
        </section>

        {{-- Three readings of what the money reaches. --}}
        <dl class="mt-10 grid gap-x-8 gap-y-6 border-y py-6 @2xl/detail:grid-cols-3 {{ $hairline }}" data-readings>
            <div class="min-w-0">
                <dt class="type-label {{ $mutedText }}">{{ __('donors.projects_funded') }}</dt>
                <dd class="mt-2 text-4xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}" data-projects-funded>{{ $this->projects->count() }}</dd>
                <dd class="mt-2 text-xs tabular-nums {{ $mutedText }}">{{ __('donors.count_active_count_closed', ['active' => $this->projectCounts['open'], 'closed' => $this->projectCounts['closed']]) }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="type-label {{ $mutedText }}">{{ __('donors.reports_submitted') }}</dt>
                <dd class="mt-2 text-4xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}" data-reports-submitted>{{ $this->reportsSubmittedCount }}</dd>
                <dd class="mt-2 text-xs {{ $mutedText }}">{{ __('donors.against_targets_these_grants_committed') }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="type-label {{ $mutedText }}">{{ __('donors.next_money_in') }}</dt>
                @if ($this->nextTranche !== null)
                    @php
                        $next = $this->nextTranche['transfer'];
                    @endphp
                    <dd class="mt-2 flex flex-wrap items-baseline gap-x-1.5 text-4xl leading-none font-extrabold tracking-tight tabular-nums text-status-warn" data-next-tranche>
                        {{ $amount((float) $next->payment_amount) }}
                        <span class="text-base font-bold tracking-normal {{ $mutedText }}">{{ $next->amount_transfered_currency?->value ?? $this->nextTranche['agreement']->currency->value }}</span>
                    </dd>
                    <dd class="mt-2 text-xs tabular-nums {{ $mutedText }}">{{ __('donors.next_money_line', ['proposal' => $this->nextTranche['agreement']->proposal->name, 'date' => $next->scheduled_date->translatedFormat(__('formats.date'))]) }}</dd>
                @else
                    <dd class="mt-2 text-4xl leading-none font-extrabold {{ $mutedText }}">—</dd>
                    <dd class="mt-2 text-xs {{ $mutedText }}">{{ __('donors.no_transfer_scheduled_ahead') }}</dd>
                @endif
            </div>
        </dl>

        {{-- The money calendar: every dated tranche on one time axis, one lane per agreement, today marked. --}}
        <section class="mt-10" aria-labelledby="calendar-heading" data-money-calendar>
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                <h2 id="calendar-heading" class="text-lg font-bold {{ $inkText }}">{{ __('donors.money_calendar') }}</h2>
                <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs {{ $mutedText }}">
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-funding" aria-hidden="true"></span>{{ __('donors.received') }}</li>
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full border-2 border-status-warn" aria-hidden="true"></span>{{ __('donors.due_ahead') }}</li>
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-primary dark:bg-status-critical" aria-hidden="true"></span>{{ __('donors.overdue') }}</li>
                    <li class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full border-2 border-dashed border-status-critical" aria-hidden="true"></span>{{ $stateLabels['failed'] }}</li>
                </ul>
            </div>

            @if ($this->calendar === null)
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('donors.no_dated_tranches') }}</p>
            @else
                <div class="mt-5 overflow-x-auto pb-2">
                    <div class="min-w-[44rem]">
                        @foreach ($this->calendar['lanes'] as $lane)
                            <div class="grid grid-cols-[11rem_minmax(0,1fr)] items-center gap-4 border-b {{ $hairline }}" wire:key="lane-{{ $lane['agreement']->id }}" data-lane="{{ $lane['agreement']->id }}">
                                <a href="{{ route('funding-agreements.show', $lane['agreement']) }}" wire:navigate class="min-w-0 py-3 text-xs underline-offset-4 hover:underline">
                                    <span class="block truncate font-semibold {{ $inkText }}">{{ $lane['agreement']->proposal->name }}</span>
                                    <span class="block truncate {{ $mutedText }}">{{ $lane['agreement']->project_num ?? __('common.no_donor_reference') }}</span>
                                </a>
                                <div class="relative h-14">
                                    <span class="absolute inset-x-0 top-1/2 h-px bg-secondary-soft dark:bg-line" aria-hidden="true"></span>
                                    <span class="absolute inset-y-0 w-px bg-secondary-dark/70 dark:bg-white/70" style="inset-inline-start: {{ $this->calendar['today'] }}%" aria-hidden="true"></span>
                                    @foreach ($lane['points'] as $point)
                                        <a
                                            href="{{ route('funding-agreements.show', $lane['agreement']) }}"
                                            wire:navigate
                                            class="group absolute top-1/2 z-10 -translate-x-1/2 hover:z-30 focus-visible:z-30 -translate-y-1/2 rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary rtl:translate-x-1/2"
                                            style="inset-inline-start: {{ $point['position'] }}%"
                                            aria-label="{{ __('donors.tranche_point_label', ['state' => $stateLabels[$point['state']], 'amount' => $amount($point['amount']), 'currency' => $point['currency'], 'date' => $point['date']->translatedFormat(__('formats.date')), 'proposal' => $lane['agreement']->proposal->name]) }}"
                                            wire:key="point-{{ $point['id'] }}"
                                            data-point="{{ $point['state'] }}"
                                        >
                                            <span @class([
                                                'block rounded-full transition-transform duration-200 ease-out group-hover:scale-125',
                                                'bg-funding' => $point['state'] === 'received',
                                                'bg-primary dark:bg-status-critical ring-4 ring-primary/15' => $point['state'] === 'overdue',
                                                'border-2 border-dashed border-status-critical bg-white dark:bg-surface-1' => $point['state'] === 'failed',
                                                'border-[3px] border-status-warn bg-white dark:bg-surface-1' => in_array($point['state'], ['due', 'transit', 'undated'], true),
                                            ]) style="width: {{ $point['size'] }}rem; height: {{ $point['size'] }}rem"></span>
                                            <span @class(['pointer-events-none absolute hidden', 'top-full mt-2' => $loop->parent->first, 'bottom-full mb-2' => ! $loop->parent->first, 'start-0' => $point['position'] < 20, 'end-0' => $point['position'] > 80, 'start-1/2 -translate-x-1/2 rtl:translate-x-1/2' => $point['position'] >= 20 && $point['position'] <= 80, 'rounded-md bg-secondary-dark px-2.5 py-1.5 text-start text-[11px] whitespace-nowrap text-white shadow-[0_6px_16px_-6px_rgb(0_0_0/0.4)] group-hover:block group-focus-visible:block dark:bg-surface-3 dark:shadow-raised dark:text-ink'])>
                                                <span class="block font-bold tabular-nums">{{ $amount($point['amount']) }} {{ $point['currency'] }}</span>
                                                <span class="block tabular-nums opacity-80">{{ $stateLabels[$point['state']] }} · {{ $point['date']->translatedFormat(__('formats.date')) }}</span>
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <div class="grid grid-cols-[11rem_minmax(0,1fr)] gap-4">
                            <span></span>
                            <div class="relative h-12" aria-hidden="true">
                                @foreach ($this->calendar['ticks'] as $tick)
                                    <span class="absolute top-2 text-[10px] whitespace-nowrap tabular-nums {{ $mutedText }}" style="inset-inline-start: {{ $tick['position'] }}%">{{ $tick['label'] }}</span>
                                @endforeach
                                <span class="absolute top-6 -translate-x-1/2 rounded-sm bg-secondary-dark px-1.5 py-0.5 text-[10px] font-bold text-white rtl:translate-x-1/2 dark:bg-ink dark:text-surface-0" style="inset-inline-start: {{ $this->calendar['today'] }}%">{{ __('donors.today') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        {{-- Each agreement threaded to the projects it funds and the reports filed against its targets. --}}
        <section class="mt-10 pb-10" aria-labelledby="agreements-heading" data-agreements>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="agreements-heading" class="text-lg font-bold {{ $inkText }}">{{ __('donors.agreements_and_what_they_fund') }}</h2>
                @can('funding-agreements.create')
                <x-ui.button size="sm" icon="plus" class="min-h-10 touch-manipulation" wire:click="recordAgreementForDonor" data-test="record-agreement-for-donor">{{ __('funding_agreements.record_agreement') }}</x-ui.button>
                @endcan
            </div>

            <div class="mt-2 flex flex-col">
                @foreach ($this->agreements as $agreement)
                    @php
                        $money = $this->agreementMoney($agreement);
                        $currency = $agreement->currency->value;
                        $reports = $this->reportsByProposal[$agreement->proposal_id] ?? collect();
                        $reportCount = $reports->count();
                    @endphp
                    <article class="grid gap-6 border-b py-7 last:border-b-0 @4xl/detail:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] @4xl/detail:gap-12 {{ $hairline }}" wire:key="donor-agreement-{{ $agreement->id }}" data-agreement="{{ $agreement->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline @3xl/detail:text-lg {{ $inkText }}">{{ $agreement->proposal->name }}</a>
                                <x-ui.enum-badge :case="$agreement->proposal->status" />
                            </div>
                            <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs underline-offset-4 hover:underline {{ $mutedText }}">
                                {{ filled($agreement->project_num) ? __('donors.agreement_ref', ['number' => $agreement->project_num]) : __('donors.view_agreement') }}
                                · {{ trans_choice('common.count_tranche', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                <flux:icon.arrow-up-right variant="micro" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                            </a>

                            <p class="mt-4 flex flex-wrap items-baseline gap-x-1.5 text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $inkText }}">
                                {{ $amount($money['committed']) }}
                                <span class="text-sm font-semibold tracking-normal {{ $mutedText }}">{{ $currency }}</span>
                            </p>

                            <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('donors.river_label', ['currency' => $currency, 'received' => $amount($money['received']), 'due' => $amount($money['due']), 'overdue' => $amount($money['overdue']), 'committed' => $amount($money['committed'])]) }}">
                                @if ($money['received'] > 0)
                                    <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $percentOf($money['received'], $money['committed']) }}%"></span>
                                @endif
                                @if ($money['due'] > 0)
                                    <span class="money-grow money-pending h-full first:rounded-s-full" style="width: {{ $percentOf($money['due'], $money['committed']) }}%"></span>
                                @endif
                                @if ($money['overdue'] > 0)
                                    <span class="money-grow h-full bg-primary dark:bg-status-critical first:rounded-s-full" style="width: {{ $percentOf($money['overdue'], $money['committed']) }}%"></span>
                                @endif
                            </div>

                            <p class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums {{ $mutedText }}">
                                <span><span class="font-bold text-funding">{{ $amount($money['received']) }}</span> {{ __('donors.received_lower') }}</span>
                                @if ($money['due'] > 0)
                                    <span><span class="font-bold text-status-warn">{{ $amount($money['due']) }}</span> {{ __('donors.due_lower') }}</span>
                                @endif
                                @if ($money['overdue'] > 0)
                                    <span><span class="font-bold text-status-critical">{{ $amount($money['overdue']) }}</span> {{ __('donors.overdue_lower') }}</span>
                                @endif
                                @if ($money['missingNet'] > 0)
                                    <span class="font-semibold text-status-warn" data-missing-net>{{ trans_choice('funding_agreements.received_tranches_missing_net', $money['missingNet'], ['count' => $money['missingNet']]) }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="min-w-0">
                            <h3 class="type-label {{ $mutedText }}">{{ __('donors.funds') }}</h3>
                            @if ($agreement->proposal->projects->isEmpty())
                                <p class="mt-3 text-sm {{ $mutedText }}">{{ __('donors.no_projects_linked') }}</p>
                            @else
                                <ol class="mt-3 flex flex-col">
                                    @foreach ($agreement->proposal->projects as $project)
                                        <li class="relative flex min-w-0 gap-3 pb-3 last:pb-0" wire:key="agreement-{{ $agreement->id }}-project-{{ $project->id }}" data-funded-project="{{ $project->id }}">
                                            <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                                @unless ($loop->last)
                                                    <span class="absolute top-3 bottom-[-0.75rem] w-px bg-secondary-soft dark:bg-line-strong"></span>
                                                @endunless
                                                <span @class([
                                                    'relative z-10 mt-1.5 size-2.5 rounded-full',
                                                    'bg-secondary-dark dark:bg-ink-muted' => $project->status->isOpen(),
                                                    'border-2 border-secondary-light bg-white dark:bg-surface-1' => ! $project->status->isOpen(),
                                                ])></span>
                                            </span>
                                            <div class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                                <a href="{{ route('projects.show', $project) }}" wire:navigate @class(['min-w-0 text-sm underline-offset-4 hover:underline', 'font-semibold '.$inkText => $project->status->isOpen(), $mutedText => ! $project->status->isOpen()])>{{ $project->name }}</a>
                                                <x-ui.enum-badge :case="$project->status" />
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                            @if ($agreement->reports->isNotEmpty())
                                <div class="mt-5" data-owed-donor-reports>
                                    <h3 class="type-label inline-flex items-center gap-1.5 {{ $mutedText }}">
                                        <flux:icon.paper-airplane variant="micro" class="size-4 rtl:-scale-x-100" aria-hidden="true" />
                                        {{ trans_choice('donors.count_reports_owed_to_donor', $agreement->reports->count(), ['count' => $agreement->reports->count()]) }}
                                    </h3>
                                    <ul class="mt-2 flex flex-col" role="list">
                                        @foreach ($agreement->reports as $owedReport)
                                            <li wire:key="agreement-{{ $agreement->id }}-owed-{{ $owedReport->id }}" data-owed-donor-report="{{ $owedReport->id }}">
                                                <a href="{{ route('funding-agreements.show', $agreement) }}#reports-heading" wire:navigate class="group flex min-h-9 items-center justify-between gap-3 border-b py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-primary {{ $hairline }}">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-medium underline-offset-4 group-hover:underline {{ $inkText }}">{{ $owedReport->title }}</span>
                                                        <span class="block text-[11px] {{ $mutedText }}">{{ $owedReport->type->label() }}</span>
                                                    </span>
                                                    <span @class(['shrink-0 text-xs font-semibold tabular-nums', 'text-status-critical' => $owedReport->isOverdue(), $mutedText => ! $owedReport->isOverdue()])>
                                                        {{ $owedReport->isOverdue() ? __('donors.report_overdue_since', ['date' => $formatDate($owedReport->due_on)]) : __('donors.report_due_on', ['date' => $formatDate($owedReport->due_on)]) }}
                                                    </span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <div class="mt-5" data-agreement-reports>
                                <h3 class="type-label inline-flex items-center gap-1.5 {{ $mutedText }}">
                                    <flux:icon.document-text variant="micro" class="size-4" aria-hidden="true" />
                                    {{ trans_choice('donors.count_reports_against_targets', $reportCount, ['count' => $reportCount]) }}
                                </h3>
                                @if ($reportCount > 0)
                                    <ul class="mt-2 flex flex-col" role="list">
                                        @foreach ($reports->take($this::LISTED_REPORTS) as $report)
                                            <li wire:key="agreement-{{ $agreement->id }}-report-{{ $report->id }}" data-agreement-report="{{ $report->id }}">
                                                <a href="{{ route('reports.show', $report) }}" wire:navigate class="group flex min-h-9 items-center justify-between gap-3 border-b py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-primary {{ $hairline }}">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-medium underline-offset-4 group-hover:underline {{ $inkText }}">{{ $report->planIndicator?->indicator?->name ?? __('donors.report_number', ['number' => $report->id]) }}</span>
                                                        <span class="block text-[11px] tabular-nums {{ $mutedText }}">{{ $report->planned_date->translatedFormat(__('formats.date')) }}</span>
                                                    </span>
                                                    <x-ui.enum-badge :case="$report->status" />
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @if ($reportCount > $this::LISTED_REPORTS)
                                        <p class="mt-1.5 text-xs {{ $mutedText }}">{{ trans_choice('donors.and_count_older_reports', $reportCount - $this::LISTED_REPORTS, ['count' => $reportCount - $this::LISTED_REPORTS]) }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($agreement->transfers->isNotEmpty())
                            <details class="group min-w-0 @4xl/detail:col-span-2" data-tranche-ledger="{{ $agreement->id }}">
                                <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-1.5 text-xs font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-primary {{ $inkText }} [&::-webkit-details-marker]:hidden">
                                    <flux:icon.chevron-right variant="micro" class="size-4 transition-transform group-open:rotate-90 rtl:-scale-x-100" aria-hidden="true" />
                                    {{ trans_choice('donors.every_tranche_in_detail', $agreement->transfers->count(), ['count' => $agreement->transfers->count()]) }}
                                </summary>
                                <div class="@container mt-3">
                                    <table class="w-full text-start text-xs tabular-nums @max-3xl:block">
                                        <thead class="@max-3xl:hidden">
                                            <tr class="border-b {{ $hairline }}">
                                                @foreach ([__('common.status'), __('transfers.scheduled'), __('transfers.sent_on'), __('transfers.payment_amount'), __('transfers.fees'), __('transfers.exchange_rate'), __('transfers.amount_received'), __('transfers.received_on'), __('transfers.account')] as $heading)
                                                    <th scope="col" class="type-label py-2 pe-4 text-start font-semibold {{ $mutedText }}">{{ $heading }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody class="[&>tr>td]:@max-3xl:block [&>tr>td]:@max-3xl:whitespace-normal [&>tr>td]:@max-3xl:before:block [&>tr>td]:@max-3xl:before:text-[11px] [&>tr>td]:@max-3xl:before:font-semibold [&>tr>td]:@max-3xl:before:content-[attr(data-label)] [&>tr>td]:@max-3xl:before:text-secondary-medium dark:[&>tr>td]:@max-3xl:before:text-secondary-soft [&>tr>td]:@max-3xl:p-0 [&>tr]:@max-3xl:grid [&>tr]:@max-3xl:grid-cols-2 [&>tr]:@max-3xl:gap-x-4 [&>tr]:@max-3xl:gap-y-2 [&>tr]:@max-3xl:py-3 @max-3xl:block">
                                            @foreach ($agreement->transfers as $transfer)
                                                @php
                                                    $state = $this->transferState($transfer);
                                                @endphp
                                                <tr class="border-b last:border-b-0 {{ $hairline }}" wire:key="tranche-row-{{ $transfer->id }}" data-tranche-row="{{ $state }}">
                                                    <td data-label="{{ __('common.status') }}" @class(['py-2 pe-4 font-semibold whitespace-nowrap', 'text-funding' => $state === 'received', 'text-status-warn' => in_array($state, ['due', 'transit'], true), 'text-status-critical' => in_array($state, ['overdue', 'failed'], true), $mutedText => $state === 'undated'])>{{ $stateLabels[$state] }}</td>
                                                    <td data-label="{{ __('transfers.scheduled') }}" class="py-2 pe-4 whitespace-nowrap">{{ $formatDate($transfer->scheduled_date) }}</td>
                                                    <td data-label="{{ __('transfers.sent_on') }}" class="py-2 pe-4 whitespace-nowrap">{{ $formatDate($transfer->transfer_date) }}</td>
                                                    <td data-label="{{ __('transfers.payment_amount') }}" class="py-2 pe-4 font-semibold whitespace-nowrap {{ $inkText }}">{{ $amount((float) $transfer->payment_amount) }} <span class="font-normal {{ $mutedText }}">{{ $transfer->amount_transfered_currency?->value }}</span></td>
                                                    <td data-label="{{ __('transfers.fees') }}" class="py-2 pe-4 whitespace-nowrap">{{ (float) $transfer->fees > 0 ? $amount((float) $transfer->fees).' '.$transfer->fees_currency?->value : '—' }}</td>
                                                    <td data-label="{{ __('transfers.exchange_rate') }}" class="py-2 pe-4 whitespace-nowrap">{{ (float) $transfer->exchange_rate !== 1.0 ? rtrim(rtrim(number_format((float) $transfer->exchange_rate, 6), '0'), '.') : '—' }}</td>
                                                    <td data-label="{{ __('transfers.amount_received') }}" class="py-2 pe-4 whitespace-nowrap">{{ $transfer->net_amount_received !== null ? $amount((float) $transfer->net_amount_received).' '.$transfer->amount_received_currency?->value : '—' }}</td>
                                                    <td data-label="{{ __('transfers.received_on') }}" class="py-2 pe-4 whitespace-nowrap">{{ $formatDate($transfer->receive_date) }}</td>
                                                    <td data-label="{{ __('transfers.account') }}" class="py-2 pe-4 whitespace-nowrap">{{ $transfer->account?->account_name ?? __('transfers.not_yet_assigned') }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endif
    @endcan

    <x-donors.form-modal :editing="true" :type-options="$this->donorTypeOptions" />

    <x-funding.agreement-modal
        :editing="$editingAgreementId !== null"
        :locked-donor-name="$donor->name"
        :proposal-options="$showAgreementModal ? $this->proposalOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
    />
</section>
