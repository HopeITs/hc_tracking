<?php

use App\Enums\ArStatus;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesDonorForm;
use App\Livewire\Concerns\SwitchesFundingView;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Report;
use App\Models\TransfersTracking;
use App\Support\CsvExport;
use App\Support\Tranches;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('titles.donor')] class extends Component {
    use ManagesAgreementForm;
    use ManagesDonorForm;
    use SwitchesFundingView;

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
            ->select(['id', 'slug', 'donor_id', 'proposal_id', 'budget', 'currency', 'external_project_number'])
            ->where('donor_id', $this->donorId)
            ->with([
                'proposal:id,slug,name,internal_project_number,status',
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
     * The same agreements as table rows: each with its money read by the one
     * SQL definition every agreement table shares, and its donor reports
     * counted. Empty for a viewer without funding-agreements.view; without
     * transfers.view the money is not queried at all and the rows carry the
     * budget only.
     *
     * @return Collection<int, DonorProposal>
     */
    #[Computed]
    public function agreementRows(): Collection
    {
        if (! auth()->user()?->can('funding-agreements.view')) {
            return new Collection;
        }

        $today = CarbonImmutable::today()->toDateString();

        return DonorProposal::query()
            ->select('donor_proposal.*')
            ->where('donor_proposal.donor_id', $this->donorId)
            ->when(auth()->user()->can('transfers.view'), fn (Builder $query) => $query->withMoney())
            ->with(['proposal:id,slug,name,internal_project_number,status'])
            ->withCount(['reports as overdue_reports_count' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '<', $today)])
            ->withMin(['reports as next_report_due' => fn (Builder $query) => $query
                ->whereNull('submitted_on')
                ->whereDate('due_on', '>=', $today)], 'due_on')
            ->orderBy('donor_proposal.id')
            ->get();
    }

    /**
     * Every tranche of every agreement, in schedule order with undated ones
     * last, each knowing the agreement it belongs to. Empty without
     * transfers.view, because agreements() loads no tranche then.
     *
     * @return Collection<int, TransfersTracking>
     */
    #[Computed]
    public function allTranches(): Collection
    {
        return new Collection($this->agreements
            ->flatMap(fn (DonorProposal $agreement) => $agreement->transfers
                ->each(fn (TransfersTracking $transfer) => $transfer->setRelation('donorProposal', $agreement)))
            ->sortBy([
                fn (TransfersTracking $a, TransfersTracking $b): int => ($a->scheduled_date === null) <=> ($b->scheduled_date === null),
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->scheduled_date <=> $b->scheduled_date,
                fn (TransfersTracking $a, TransfersTracking $b): int => $a->id <=> $b->id,
            ])
            ->values()
            ->all());
    }

    /**
     * Every tranche of this donor as a CSV file.
     */
    public function exportTranchesCsv(): StreamedResponse
    {
        Gate::authorize('funding-agreements.view');
        Gate::authorize('transfers.view');

        $donor = $this->donor;

        $rows = $this->allTranches->map(fn (TransfersTracking $transfer): array => Tranches::csvRow(
            $transfer,
            $donor->name,
            $transfer->donorProposal->proposal->name,
            $transfer->donorProposal->external_project_number,
            $transfer->account?->account_name,
        ));

        return CsvExport::download('donor-tranches-'.CarbonImmutable::today()->toDateString().'.csv', Tranches::csvHeadings(), $rows);
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
        unset($this->donor, $this->agreements, $this->agreementRows, $this->allTranches, $this->moneyByCurrency, $this->receivedElsewhere, $this->overdueTranches, $this->nextTranche, $this->projects, $this->projectCounts, $this->reportsByProposal, $this->reportsSubmittedCount, $this->calendar);
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
            @can('funding-agreements.view')
                <x-ui.view-toggle
                    class="@3xl/detail:order-last"
                    :label="__('finance_tables.view_label')"
                    wire-model="view"
                    :active="$this->view"
                    :options="[
                        ['key' => 'table', 'label' => __('finance_tables.view_table'), 'icon' => 'table-cells'],
                        ['key' => 'overview', 'label' => __('finance_tables.view_overview'), 'icon' => 'chart-bar'],
                    ]"
                />
            @endcan
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

        @if ($this->view === 'overview')
            @include('pages.donors._show-overview')
        @else
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
        @endif
    @endif
    @endcan

    <x-donors.form-modal :open="$showDonorModal" :editing="true" :type-options="$this->donorTypeOptions" />

    <x-funding.agreement-modal
        :open="$showAgreementModal"
        :editing="$editingAgreementId !== null"
        :locked-donor-name="$donor->name"
        :proposal-options="$showAgreementModal ? $this->proposalOptions : []"
        :warning="$showAgreementModal ? $this->agreementProposalWarning : null"
        :delete-blocker="$showAgreementModal ? $this->agreementDeleteBlocker : null"
        :locked-currency="$showAgreementModal && $this->agreementCurrencyLocked ? $agreementCurrency : null"
        :confirming-delete="$confirmingAgreementDelete"
    />
</section>
