<?php

use App\Enums\ArStatus;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.proposal')] class extends Component {
    #[Locked]
    public int $proposalId;

    public bool $showStatusModal = false;
    public string $newStatus = '';
    public string $statusContext = '';
    public string $statusDate = '';

    public function mount(Proposal $proposal): void
    {
        $this->proposalId = $proposal->id;
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes', 'updated_at'])
            ->withCount(['donorProposals', 'projects', 'tasks' => fn ($query) => $query->withoutGlobalScope(TaskVisibilityScope::class), 'statusNotes', 'planIndicators'])
            ->findOrFail($this->proposalId);
    }

    /** @return EloquentCollection<int, ProposalStatusNote> */
    #[Computed]
    public function statusNotes(): EloquentCollection
    {
        return ProposalStatusNote::query()
            ->select(['id', 'proposal_id', 'status', 'context', 'date'])
            ->where('proposal_id', $this->proposalId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The first date each status was reached, and the note that recorded it.
     *
     * The lifecycle spine reads this to date its stops. First, not last: a
     * proposal that moved back and forth reached a stop the first time it
     * arrived there, and that is the date the audit trail defends.
     *
     * @return array<string, array{date: CarbonImmutable, noteId: int}>
     */
    #[Computed]
    public function statusDates(): array
    {
        return $this->statusNotes
            ->filter(fn (ProposalStatusNote $note): bool => $note->date !== null)
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->groupBy(fn (ProposalStatusNote $note): string => $note->status->value)
            ->map(fn (EloquentCollection $group): array => [
                'date' => $group->first()->date,
                'noteId' => (int) $group->first()->id,
            ])
            ->all();
    }

    /**
     * The funding agreements on this proposal, each with its tranches.
     *
     * Read through donorProposals(), never through donors()->pivot: the pivot
     * built by belongsToMany carries no casts, so its currency arrives as a raw
     * string while this relation's arrives as a Currency case.
     *
     * Tranches come back in the order money is expected — scheduled first,
     * undated last — because that is the order the cash thread draws them.
     *
     * @return EloquentCollection<int, DonorProposal>
     */
    #[Computed]
    public function agreements(): EloquentCollection
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'budget', 'currency', 'project_num', 'proposal_id'])
            ->where('proposal_id', $this->proposalId)
            ->with([
                'donor:id,name',
                'transfers' => fn ($query) => $query
                    ->select([
                        'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                        'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'exchange_rate',
                        'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
                    ])
                    ->orderByRaw('scheduled_date is null, scheduled_date')
                    ->orderBy('id'),
                'transfers.account:id,account_name,is_active',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Committed, received, pending and unscheduled money, one line per currency.
     *
     * Grouped, never summed across currencies: USD, EUR and SYP have no
     * conversion rate in this system, so one total would be an invented number.
     * Received counts net money in the currency it arrived in, pending counts
     * the scheduled payment in the currency it will be sent in — so a tranche
     * that crosses currencies lands on two different lines, deliberately.
     *
     * A tranche with a null net_amount_received contributes nothing to
     * received; NULL there means "not yet", never zero-and-therefore-arrived.
     *
     * @return list<array{currency: string, committed: float, received: float, pending: float, unscheduled: float, agreements: int, pendingCount: int}>
     */
    #[Computed]
    public function money(): array
    {
        $transfers = $this->agreements->flatMap(fn (DonorProposal $agreement): EloquentCollection => $agreement->transfers);

        $committed = $this->agreements->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value);

        $received = $transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->ar_status === ArStatus::Received
                && $transfer->net_amount_received !== null
                && $transfer->amount_received_currency !== null)
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value);

        $pending = $transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->ar_status === ArStatus::Pending
                && $transfer->amount_transfered_currency !== null)
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_transfered_currency->value);

        // What the tranches sent in each agreement's own currency already cover
        // of its budget, whatever became of them: a fee or a failed transfer
        // does not turn money back into "not yet scheduled".
        $scheduled = $this->agreements
            ->groupBy(fn (DonorProposal $agreement): string => $agreement->currency->value)
            ->map(fn (EloquentCollection $group): float => (float) $group->sum(fn (DonorProposal $agreement): float => (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency === $agreement->currency)
                ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount)));

        return $committed->keys()
            ->concat($received->keys())
            ->concat($pending->keys())
            ->map(fn ($currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $received, $pending, $scheduled): array {
                $committedLine = $committed->get($currency, new Collection);
                $receivedLine = $received->get($currency, new Collection);
                $pendingLine = $pending->get($currency, new Collection);

                $committedTotal = (float) $committedLine->sum(fn (DonorProposal $agreement): float => (float) $agreement->budget);
                $receivedTotal = (float) $receivedLine->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received);
                $pendingTotal = (float) $pendingLine->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount);

                return [
                    'currency' => $currency,
                    'committed' => $committedTotal,
                    'received' => $receivedTotal,
                    'pending' => $pendingTotal,
                    'unscheduled' => max(0, $committedTotal - (float) ($scheduled[$currency] ?? 0)),
                    'agreements' => $committedLine->count(),
                    'pendingCount' => $pendingLine->count(),
                ];
            })
            ->all();
    }

    /**
     * What one agreement has drawn down, in its own currency.
     *
     * Same definitions as the proposals index, so a figure means the same thing
     * on both pages. Money received in another currency is reported separately
     * rather than folded in, because there is no rate to fold it with.
     *
     * @return array{received: float, pending: float, unscheduled: float, share: int, nextDue: CarbonImmutable|null, otherCurrencies: array<string, float>}
     */
    public function agreementMoney(DonorProposal $agreement): array
    {
        $currency = $agreement->currency->value;
        $received = $agreement->transfers->where('ar_status', ArStatus::Received);
        $pending = $agreement->transfers->where('ar_status', ArStatus::Pending);

        $receivedHere = (float) $received
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
            ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received);
        $pendingHere = (float) $pending
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
            ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount);
        $budget = (float) $agreement->budget;

        return [
            'received' => $receivedHere,
            'pending' => $pendingHere,
            'unscheduled' => max(0, $budget - (float) $agreement->transfers
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->amount_transfered_currency?->value === $currency)
                ->sum(fn (TransfersTracking $transfer): float => (float) $transfer->payment_amount)),
            'share' => $budget > 0 ? (int) floor($receivedHere / $budget * 100) : 0,
            // Only money still to be sent has a due date; a sent tranche is in transit.
            'nextDue' => $pending->whereNull('transfer_date')->whereNotNull('scheduled_date')->sortBy('scheduled_date')->first()?->scheduled_date,
            'otherCurrencies' => $received
                ->reject(fn (TransfersTracking $transfer): bool => $transfer->amount_received_currency?->value === $currency)
                ->filter(fn (TransfersTracking $transfer): bool => $transfer->net_amount_received !== null)
                ->groupBy(fn (TransfersTracking $transfer): string => (string) $transfer->amount_received_currency?->value)
                ->map(fn (EloquentCollection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
                ->all(),
        ];
    }

    /**
     * Where one tranche stands, as the single word the cash thread draws.
     *
     * 'overdue' is not a stored state. It is a pending tranche whose scheduled
     * date has already passed — the one the fundraising manager has to chase —
     * and it is derived here rather than written anywhere, so it can never go
     * stale. 'unscheduled' is a tranche agreed with no date set yet.
     */
    public function trancheState(TransfersTracking $transfer): string
    {
        return match ($transfer->state()) {
            'received' => 'received',
            'failed' => 'failed',
            'transit' => 'transit',
            'late' => 'overdue',
            default => $transfer->scheduled_date === null ? 'unscheduled' : 'pending',
        };
    }

    /** @return EloquentCollection<int, Project> */
    #[Computed]
    public function projects(): EloquentCollection
    {
        return $this->proposal->projects()
            ->select(['projects.id', 'projects.name', 'projects.city_id', 'projects.program_id', 'projects.status'])
            ->with(['city:id,name_en,name_ar', 'program:id'])
            ->orderBy('projects.name')
            ->get();
    }

    /**
     * Tasks sourced directly from this proposal, plus tasks sourced from a
     * milestone that belongs to one of this proposal's funded projects.
     *
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function tasks(): EloquentCollection
    {
        $fundedProjectIds = $this->projects->modelKeys();
        $milestoneIds = Milestone::query()->whereIn('project_id', $fundedProjectIds)->pluck('id');

        return Task::query()
            ->select(['id', 'source_type', 'source_id', 'title', 'status', 'priority', 'due_date', 'progress_percentage'])
            ->with([
                'taskAssignees.employee:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([Milestone::class => ['project:id']]),
            ])
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('source_type', 'proposal')->where('source_id', $this->proposalId))
                ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', $milestoneIds)))
            ->orderByRaw('due_date is null, due_date')
            ->get();
    }

    /**
     * The delivery band and the worklist under it.
     *
     * `segments` is one segment per task while the band can still show them
     * apart; past that it collapses to one segment per status weighted by its
     * count, so the band stays a readable proportion instead of a comb. Both
     * shapes render through the same loop.
     *
     * The band reads finished-first so it fills from the start edge; the
     * worklist reads outstanding-first, because that is the part to act on.
     *
     * @return array{total: int, done: int, overdue: int, segments: list<array{status: TaskStatus, weight: int}>, groups: list<array{status: TaskStatus, tasks: EloquentCollection<int, Task>}>}
     */
    #[Computed]
    public function delivery(): array
    {
        $tasks = $this->tasks;
        $bandOrder = [TaskStatus::Done, TaskStatus::UnderReview, TaskStatus::InProgress, TaskStatus::Todo];
        $listOrder = [TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::UnderReview, TaskStatus::Done];
        $byStatus = $tasks->groupBy(fn (Task $task): string => $task->status->value);

        $segments = $tasks->count() <= 40
            ? collect($bandOrder)
                ->flatMap(fn (TaskStatus $status): Collection => $byStatus
                    ->get($status->value, new Collection)
                    ->map(fn (): array => ['status' => $status, 'weight' => 1]))
                ->values()
                ->all()
            : collect($bandOrder)
                ->map(fn (TaskStatus $status): array => ['status' => $status, 'weight' => $byStatus->get($status->value, new Collection)->count()])
                ->filter(fn (array $segment): bool => $segment['weight'] > 0)
                ->values()
                ->all();

        return [
            'total' => $tasks->count(),
            'done' => $byStatus->get(TaskStatus::Done->value, new Collection)->count(),
            'overdue' => $tasks
                ->filter(fn (Task $task): bool => $task->status !== TaskStatus::Done
                    && $task->due_date !== null
                    && $task->due_date->lt(today()))
                ->count(),
            'segments' => $segments,
            'groups' => collect($listOrder)
                ->map(fn (TaskStatus $status): array => ['status' => $status, 'tasks' => $byStatus->get($status->value, new EloquentCollection)])
                ->filter(fn (array $group): bool => $group['tasks']->isNotEmpty())
                ->values()
                ->all(),
        ];
    }

    /**
     * Targets committed in this proposal, as the results chain they sit in.
     *
     * Filtered on the morph map key 'proposal', never a class name. `reports`
     * and `indicator` are eager loaded because achievedValue() answers from
     * them when they are present, and would otherwise query once per target.
     *
     * @return list<array{result: \App\Models\Result, indicators: list<array{indicator: \App\Models\Indicator, targets: EloquentCollection<int, PlanIndicator>}>}>
     */
    #[Computed]
    public function targets(): array
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('source_type', 'proposal')
            ->where('source_id', $this->proposalId)
            ->with([
                'indicator:id,name,result_id,measure,unit',
                'indicator.result:id,statement,type',
                'project:id,name',
                'reports:id,plan_indicator_id,value,status,planned_date',
            ])
            ->get()
            ->filter(fn (PlanIndicator $target): bool => $target->indicator?->result !== null)
            ->groupBy(fn (PlanIndicator $target): int => $target->indicator->result->id)
            ->map(fn (EloquentCollection $group): array => [
                'result' => $group->first()->indicator->result,
                'indicators' => $group
                    ->groupBy(fn (PlanIndicator $target): int => $target->indicator_id)
                    ->map(fn (EloquentCollection $forIndicator): array => [
                        'indicator' => $forIndicator->first()->indicator,
                        'targets' => $forIndicator,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The planned window with today's place in it.
     *
     * Null when the window cannot be drawn — no dates, or an end that does not
     * come after its start — so the strip is absent rather than nonsensical.
     * Percentages are clamped, so an actual date outside the planned window
     * still lands on the rail instead of off the end of it.
     *
     * @return array{begin: CarbonImmutable, end: CarbonImmutable, actualBegin: CarbonImmutable|null, actualEnd: CarbonImmutable|null, totalDays: int, elapsedDays: int, remainingDays: int, daysToStart: int, todayPercent: float, actualBeginPercent: float|null, actualEndPercent: float|null, beforeStart: bool, afterEnd: bool}|null
     */
    #[Computed]
    public function window(): ?array
    {
        $begin = $this->proposal->planned_date_begin;
        $end = $this->proposal->planned_date_end;

        if ($begin === null || $end === null || $end->lessThanOrEqualTo($begin)) {
            return null;
        }

        $today = CarbonImmutable::today();
        $totalDays = max(1, (int) round($begin->diffInDays($end)));
        $position = fn (?CarbonImmutable $date): ?float => $date === null
            ? null
            : max(0.0, min(100.0, round($begin->diffInDays($date) / $totalDays * 100, 2)));

        return [
            'begin' => $begin,
            'end' => $end,
            'actualBegin' => $this->proposal->actual_date_begin,
            'actualEnd' => $this->proposal->actual_date_end,
            'totalDays' => $totalDays,
            'elapsedDays' => max(0, min($totalDays, (int) round($begin->diffInDays($today)))),
            'remainingDays' => (int) round($today->diffInDays($end)),
            'daysToStart' => (int) round($today->diffInDays($begin)),
            'todayPercent' => (float) $position($today),
            'actualBeginPercent' => $position($this->proposal->actual_date_begin),
            'actualEndPercent' => $position($this->proposal->actual_date_end),
            'beforeStart' => $today->lessThan($begin),
            'afterEnd' => $today->greaterThan($end),
        ];
    }

    /**
     * The sticky rail's stops, in the order the page tells its story.
     *
     * @return list<array{id: string, label: string, count: int}>
     */
    #[Computed]
    public function sections(): array
    {
        return [
            ['id' => 'money', 'label' => __('common.funding'), 'count' => $this->proposal->donor_proposals_count],
            ['id' => 'projects', 'label' => __('common.projects'), 'count' => $this->proposal->projects_count],
            ['id' => 'delivery', 'label' => __('common.tasks'), 'count' => $this->proposal->tasks_count],
            ['id' => 'targets', 'label' => __('common.targets'), 'count' => $this->proposal->plan_indicators_count],
            ['id' => 'record', 'label' => __('proposals.status_history'), 'count' => $this->proposal->status_notes_count],
        ];
    }

    public function openStatusModal(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        $this->newStatus = $this->proposal->status->value;
        $this->statusContext = '';
        $this->statusDate = now()->toDateString();
        $this->showStatusModal = true;
    }

    /**
     * Move the proposal to a new status and record why.
     *
     * Both writes happen in one transaction. The live status and its audit
     * trail are the same vocabulary — proposal_status_notes.status casts to the
     * same ProposalStatus enum — so they must never be allowed to disagree.
     */
    public function changeStatus(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->statusContext = trim($this->statusContext);

        $validated = $this->validate([
            'newStatus' => ['required', new Enum(ProposalStatus::class)],
            'statusContext' => ['required', 'string'],
            'statusDate' => ['required', 'date'],
        ], [
            'newStatus.required' => __('proposals.choose_the_new_status'),
            'statusContext.required' => __('proposals.record_why_the_status_changed'),
            'statusDate.required' => __('proposals.set_the_date_this_change_took_effect'),
        ]);

        DB::transaction(function () use ($validated): void {
            $proposal = Proposal::query()->findOrFail($this->proposalId);
            $proposal->fill(['status' => $validated['newStatus']])->save();

            ProposalStatusNote::query()->create([
                'proposal_id' => $this->proposalId,
                'status' => $validated['newStatus'],
                'context' => $validated['statusContext'],
                'date' => $validated['statusDate'],
            ]);
        });

        $this->showStatusModal = false;
        $this->statusContext = '';
        unset($this->proposal, $this->statusNotes, $this->statusDates, $this->sections);

        Flux::toast(variant: 'success', text: __('proposals.status_changed_and_recorded'));
    }
};
?>

@php
    use App\Enums\ResultType;

    $proposal = $this->proposal;
    $window = $this->window;
    $delivery = $this->delivery;
    $statusDates = $this->statusDates;

    $mutedText = 'text-secondary-medium dark:text-secondary-soft';
    $inkText = 'text-secondary-dark dark:text-white';
    $receivedText = 'text-funding';
    $hairline = 'border-secondary-soft dark:border-secondary-medium';
    $threadLine = 'bg-secondary-soft dark:bg-secondary-medium';

    $amount = fn (float $value): string => Number::format($value, maxPrecision: 0);
    $exact = fn (float $value): string => number_format($value, 2);
    $day = fn (?\Carbon\CarbonInterface $value): ?string => $value?->translatedFormat(__('formats.date'));

    $lifecycle = ProposalStatus::lifecycle();
    $isRejected = $proposal->status === ProposalStatus::Rejected;
    // A rejection answers a submission, so the spine stops at Submitted and marks the branch after it.
    $reachedStep = (int) array_search($isRejected ? ProposalStatus::Submitted : $proposal->status, $lifecycle, true);

    $resultMark = fn (ResultType $type): string => match ($type) {
        ResultType::Impact => 'bg-secondary-dark dark:bg-white',
        ResultType::Outcome => 'bg-primary',
        ResultType::Output => 'border-2 border-secondary-light bg-white dark:bg-secondary-dark',
    };

    $taskDot = fn (TaskStatus $status): string => match ($status) {
        TaskStatus::Done => 'bg-status-good',
        TaskStatus::UnderReview => 'bg-status-active',
        TaskStatus::InProgress => 'bg-funding',
        TaskStatus::Todo => 'bg-secondary-soft dark:bg-secondary-medium',
    };
@endphp

<section class="@container/detail flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('common.proposals'), 'url' => route('proposals.index')],
            ['label' => $proposal->name],
        ]"
        :title="$proposal->name"
        title-class="text-3xl! font-bold! leading-none!"
    >
        <x-slot:meta>
            <span class="font-semibold">{{ $proposal->phase->label() }}</span>
            <span>{{ __('common.updated_date', ['date' => $day($proposal->updated_at)]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$proposal->status" />
                @can('update', $proposal)
                    <x-ui.button tone="primary" size="sm" icon="arrow-path" class="min-h-10 touch-manipulation" wire:click="openStatusModal" data-test="open-status-modal">{{ __('proposals.change_status') }}</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    {{-- Where it stands: the lifecycle spine, then the window it has to run in. --}}
    <section aria-labelledby="standing-heading" class="pt-7">
        <h2 id="standing-heading" class="sr-only">{{ __('proposals.where_this_proposal_stands') }}</h2>

        <ol class="grid grid-cols-7" aria-label="{{ __('proposals.lifecycle') }}" data-lifecycle>
            @foreach ($lifecycle as $index => $step)
                @php
                    $isCurrent = ! $isRejected && $index === $reachedStep;
                    $isPast = $index < $reachedStep || ($isRejected && $index === $reachedStep);
                    $isRejectionPoint = $isRejected && $index === $reachedStep + 1;
                    $isAhead = ! $isPast && ! $isCurrent && ! $isRejectionPoint;
                    $stepStatus = $isRejectionPoint ? ProposalStatus::Rejected : $step;
                    $reached = $statusDates[$stepStatus->value] ?? null;
                @endphp
                <li class="relative flex min-w-0 flex-col items-center gap-2" @if ($isCurrent || $isRejectionPoint) aria-current="step" @endif wire:key="spine-{{ $step->value }}">
                    @unless ($loop->first)
                        <span @class([
                            'absolute end-1/2 top-[0.4375rem] h-0.5 w-full',
                            'bg-secondary-dark dark:bg-white' => $isPast || $isCurrent,
                            'bg-primary' => $isRejectionPoint,
                            $threadLine => $isAhead,
                        ]) aria-hidden="true"></span>
                    @endunless

                    <span @class([
                        'relative z-10 size-3.5 rounded-full',
                        'bg-primary ring-4 ring-primary/20' => $isCurrent || $isRejectionPoint,
                        'bg-secondary-dark dark:bg-white' => $isPast,
                        'border-2 border-secondary-soft bg-white dark:border-secondary-medium dark:bg-secondary-dark' => $isAhead,
                    ]) aria-hidden="true"></span>

                    <span @class([
                        'w-full px-0.5 text-center text-[11px] leading-tight @3xl/detail:text-xs',
                        'font-bold text-primary' => $isCurrent || $isRejectionPoint,
                        'hidden font-semibold @3xl/detail:block '.$inkText => $isPast,
                        'hidden font-medium text-secondary-light @3xl/detail:block' => $isAhead,
                    ])>{{ $stepStatus->label() }}</span>

                    @if ($reached !== null)
                        <a
                            href="#status-note-{{ $reached['noteId'] }}"
                            @class(['hidden text-[11px] tabular-nums underline-offset-4 hover:underline @3xl/detail:block', 'text-primary' => $isCurrent || $isRejectionPoint, $mutedText => ! $isCurrent && ! $isRejectionPoint])
                        >{{ $day($reached['date']) }}</a>
                    @else
                        <span class="hidden min-h-4 @3xl/detail:block" aria-hidden="true"></span>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($window !== null)
            <div class="mt-7" data-window>
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 text-xs tabular-nums {{ $mutedText }}">
                    <span>{{ __('proposals.planned_start') }} · <span class="font-semibold {{ $inkText }}">{{ $day($window['begin']) }}</span></span>
                    <span>{{ __('proposals.planned_end') }} · <span class="font-semibold {{ $inkText }}">{{ $day($window['end']) }}</span></span>
                </div>

                <div
                    class="relative mt-2.5 h-1.5 w-full rounded-full {{ $threadLine }}"
                    role="img"
                    aria-label="{{ __('proposals.window_label', ['begin' => $day($window['begin']), 'end' => $day($window['end']), 'elapsed' => $window['elapsedDays'], 'total' => $window['totalDays']]) }}"
                >
                    <span class="money-grow absolute inset-y-0 start-0 rounded-full bg-secondary-dark dark:bg-white" style="width: {{ $window['todayPercent'] }}%"></span>

                    @if ($window['actualBeginPercent'] !== null)
                        <span
                            class="absolute top-1/2 size-3 -translate-y-1/2 rounded-full bg-funding ring-2 ring-white dark:ring-secondary-dark"
                            style="inset-inline-start: calc({{ $window['actualBeginPercent'] }}% - 0.375rem)"
                            aria-hidden="true"
                        ></span>
                    @endif

                    @if ($window['actualEndPercent'] !== null)
                        <span
                            class="absolute top-1/2 size-3 -translate-y-1/2 rounded-full bg-funding ring-2 ring-white dark:ring-secondary-dark"
                            style="inset-inline-start: calc({{ $window['actualEndPercent'] }}% - 0.375rem)"
                            aria-hidden="true"
                        ></span>
                    @endif

                    @unless ($window['beforeStart'] || $window['afterEnd'])
                        <span
                            class="absolute -top-1.5 -bottom-1.5 w-0.5 rounded-full bg-primary"
                            style="inset-inline-start: {{ $window['todayPercent'] }}%"
                            aria-hidden="true"
                        ></span>
                    @endunless
                </div>

                <p class="mt-2.5 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-xs {{ $mutedText }}">
                    <span class="tabular-nums">
                        @if ($window['beforeStart'])
                            {{ trans_choice('proposals.count_days_until_it_starts', $window['daysToStart'], ['count' => $window['daysToStart']]) }}
                        @elseif ($window['afterEnd'])
                            <span class="font-semibold text-primary">{{ trans_choice('proposals.count_days_past_the_planned_end', abs($window['remainingDays']), ['count' => abs($window['remainingDays'])]) }}</span>
                        @else
                            {{ __('proposals.day_of_total', ['day' => $window['elapsedDays'], 'total' => $window['totalDays']]) }}
                            <span aria-hidden="true">·</span>
                            {{ trans_choice('proposals.count_days_left', $window['remainingDays'], ['count' => $window['remainingDays']]) }}
                        @endif
                    </span>

                    @if ($window['actualBegin'] !== null)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-funding" aria-hidden="true"></span>
                            {{ __('proposals.actual_begin', ['begin' => $day($window['actualBegin'])]) }}
                        </span>
                    @endif

                    @if ($window['actualEnd'] !== null)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-funding" aria-hidden="true"></span>
                            {{ __('proposals.actual_ended', ['end' => $day($window['actualEnd'])]) }}
                        </span>
                    @endif
                </p>
            </div>
        @endif

        @if (filled($proposal->notes))
            <div class="mt-7 max-w-[70ch] border-s-2 border-secondary-soft ps-4 dark:border-secondary-medium" data-notes>
                <p class="type-label {{ $mutedText }}">{{ __('common.notes') }}</p>
                <p class="mt-1.5 text-sm leading-relaxed whitespace-pre-line {{ $inkText }}">{{ $proposal->notes }}</p>
            </div>
        @endif
    </section>

    {{-- The rail carries the counts the tabs used to, without hiding anything. --}}
    <nav
        class="sticky top-16 z-10 -mx-1 mt-7 border-b bg-white px-1 lg:top-0 dark:bg-secondary-dark {{ $hairline }}"
        aria-label="{{ __('proposals.jump_to_a_section') }}"
        x-data="sectionRail(@js(collect($this->sections)->pluck('id')->all()))"
        data-section-rail
    >
        <ul class="flex flex-wrap items-center gap-x-1" role="list">
            @foreach ($this->sections as $section)
                <li wire:key="rail-{{ $section['id'] }}">
                    <a
                        href="#{{ $section['id'] }}"
                        x-on:click="current = @js($section['id'])"
                        :aria-current="current === @js($section['id']) ? 'true' : 'false'"
                        class="inline-flex min-h-11 touch-manipulation items-center gap-1.5 border-b-2 border-transparent px-3 text-sm font-medium transition-colors hover:text-secondary-dark aria-[current=true]:border-primary aria-[current=true]:text-secondary-dark dark:hover:text-white dark:aria-[current=true]:text-white {{ $mutedText }}"
                    >
                        {{ $section['label'] }}
                        <span class="inline-flex min-w-5 items-center justify-center rounded-md border border-secondary-light px-1 text-[11px] font-semibold tabular-nums">{{ $section['count'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- The money: the position per currency, then every agreement and when its cash lands. --}}
    <section id="money" aria-labelledby="money-heading" class="scroll-mt-32 pt-7">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <h2 id="money-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.where_the_money_stands') }}</h2>
            <p class="text-xs {{ $mutedText }}">{{ __('proposals.budgets_are_shown_per_currency_they_are_never_added_together') }}</p>
        </div>

        @forelse ($this->money as $line)
            @php
                $barBase = max($line['committed'], $line['received'] + $line['pending'], 1);
                $figures = [
                    ['key' => 'committed', 'label' => __('proposals.committed'), 'value' => $line['committed'], 'tone' => $inkText, 'note' => trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']])],
                    ['key' => 'received', 'label' => __('proposals.received'), 'value' => $line['received'], 'tone' => $receivedText, 'note' => __('proposals.percent_of_committed', ['percent' => App\Models\DonorProposal::percentLabel($line['received'], $line['committed'])])],
                    ['key' => 'pending', 'label' => __('proposals.pending'), 'value' => $line['pending'], 'tone' => 'text-status-warn', 'note' => trans_choice('proposals.count_transfers_scheduled', $line['pendingCount'], ['count' => $line['pendingCount']])],
                    ['key' => 'unscheduled', 'label' => __('proposals.not_yet_scheduled'), 'value' => $line['unscheduled'], 'tone' => $mutedText, 'note' => __('proposals.committed_with_no_transfer_yet')],
                ];
            @endphp
            <div class="mt-5 border-t pt-5 {{ $hairline }}" wire:key="money-{{ $line['currency'] }}" data-money-line="{{ $line['currency'] }}">
                <div class="grid grid-cols-2 gap-x-6 gap-y-6 @4xl/detail:grid-cols-[3.5rem_repeat(4,minmax(0,1fr))]">
                    <p class="col-span-2 text-sm font-extrabold tracking-wide @4xl/detail:col-span-1 @4xl/detail:pt-0.5 {{ $receivedText }}">{{ $line['currency'] }}</p>
                    @foreach ($figures as $figure)
                        <div class="min-w-0" data-figure="{{ $figure['key'] }}">
                            <p class="text-xs font-semibold {{ $mutedText }}">{{ $figure['label'] }}</p>
                            <p
                                class="mt-1.5 truncate text-3xl leading-none font-extrabold tracking-tight tabular-nums @6xl/detail:text-[2.625rem] {{ $figure['tone'] }}"
                                x-data="countUp({{ (int) round($figure['value']) }})"
                                x-text="new Intl.NumberFormat('en-US').format(display)"
                            >{{ $amount($figure['value']) }}</p>
                            <p class="mt-2 text-xs {{ $mutedText }}">{{ $figure['note'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-6 flex h-3 w-full gap-0.5 overflow-hidden rounded-full @4xl/detail:ms-[calc(3.5rem+1.5rem)] @4xl/detail:w-[calc(100%-5rem)] {{ $threadLine }}" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $line['currency'], 'received' => $amount($line['received']), 'pending' => $amount($line['pending']), 'committed' => $amount($line['committed'])]) }}">
                    <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ round($line['received'] / $barBase * 100, 2) }}%"></span>
                    <span class="money-grow money-pending h-full" style="width: {{ round($line['pending'] / $barBase * 100, 2) }}%"></span>
                </div>
            </div>
        @empty
            <x-ui.empty-state icon="banknotes" :heading="__('proposals.no_funding_agreements')" :body="__('proposals.agreements_signed_against_this_proposal_will_appear_here')" />
        @endforelse

        @foreach ($this->agreements as $agreement)
            @php
                $agreementMoney = $this->agreementMoney($agreement);
                $budget = (float) $agreement->budget;
                $tranches = $agreement->transfers;
                $arrived = $tranches->where('ar_status', ArStatus::Received)->count();
            @endphp
            <article class="mt-6 border-t pt-6 {{ $hairline }}" wire:key="agreement-{{ $agreement->id }}" data-agreement="{{ $agreement->id }}">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                    <p class="min-w-0">
                        <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline {{ $inkText }}">{{ $agreement->donor->name }}</a>
                        <a href="{{ route('funding-agreements.show', $agreement) }}" wire:navigate class="ms-2 text-xs underline-offset-4 hover:underline {{ $mutedText }}">{{ filled($agreement->project_num) ? __('proposals.agreement_number', ['number' => $agreement->project_num]) : __('proposals.view_agreement') }}</a>
                    </p>
                    <p class="text-2xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ $exact($budget) }} <span class="text-xs font-semibold tracking-normal {{ $mutedText }}">{{ $agreement->currency->value }}</span></p>
                </div>

                <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full {{ $threadLine }}" role="img" aria-label="{{ __('proposals.money_bar_label', ['currency' => $agreement->currency->value, 'received' => $amount($agreementMoney['received']), 'pending' => $amount($agreementMoney['pending']), 'committed' => $amount($budget)]) }}">
                    <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $budget > 0 ? min(100, round($agreementMoney['received'] / $budget * 100, 2)) : 0 }}%"></span>
                    <span class="money-grow money-pending h-full" style="width: {{ $budget > 0 ? min(100, round($agreementMoney['pending'] / $budget * 100, 2)) : 0 }}%"></span>
                </div>

                <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums {{ $mutedText }}">
                    <span><span class="font-bold {{ $receivedText }}">{{ $amount($agreementMoney['received']) }}</span> {{ __('proposals.received_percent', ['percent' => App\Models\DonorProposal::percentLabel($agreementMoney['received'], (float) $agreement->budget)]) }}</span>
                    <span><span class="font-bold text-status-warn">{{ $amount($agreementMoney['pending']) }}</span> {{ __('proposals.pending_lower') }}</span>
                    <span>{{ __('proposals.tranches_arrived_of', ['arrived' => $arrived, 'total' => $tranches->count()]) }}</span>
                    @foreach ($agreementMoney['otherCurrencies'] as $otherCurrency => $otherAmount)
                        <span>{{ __('proposals.also_received_in', ['amount' => $amount($otherAmount), 'currency' => $otherCurrency]) }}</span>
                    @endforeach
                </p>

                @if ($tranches->isEmpty())
                    <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_tranches_scheduled_on_this_agreement_yet') }}</p>
                @else
                    <ol class="cash-thread mt-4" style="--thread-order: {{ $loop->index }}" aria-label="{{ __('proposals.cash_thread') }}" role="list">
                        @foreach ($tranches as $tranche)
                            @php
                                $state = $this->trancheState($tranche);
                                $hasArrived = $state === 'received';
                                $fees = (float) $tranche->fees;
                                $daysOverdue = $state === 'overdue' ? (int) round($tranche->scheduled_date->diffInDays(today())) : 0;
                            @endphp
                            <li class="relative flex flex-col gap-1 py-3 ps-6 @2xl/detail:flex-row @2xl/detail:items-baseline @2xl/detail:gap-5" wire:key="tranche-{{ $tranche->id }}" data-tranche-state="{{ $state }}">
                                @unless ($loop->last)
                                    <span @class([
                                        'absolute start-[0.3125rem] top-[1.3125rem] h-full',
                                        'w-px bg-funding' => $hasArrived,
                                        'w-0 thread-pending' => ! $hasArrived,
                                    ]) aria-hidden="true"></span>
                                @endunless

                                <span @class([
                                    'absolute start-0 top-4 size-2.5 rounded-full',
                                    'bg-funding' => $state === 'received',
                                    'border-2 border-status-warn bg-white dark:bg-secondary-dark' => $state === 'pending',
                                    'border-2 border-status-warn bg-status-warn' => $state === 'transit',
                                    'border-2 border-primary bg-white ring-4 ring-primary/15 dark:bg-secondary-dark' => $state === 'overdue',
                                    'bg-primary' => $state === 'failed',
                                    'border-2 border-dashed border-secondary-light bg-white dark:bg-secondary-dark' => $state === 'unscheduled',
                                ]) aria-hidden="true"></span>

                                <div class="min-w-0 @2xl/detail:me-auto">
                                    <p class="text-sm font-semibold tabular-nums {{ $inkText }}">
                                        {{ $day($tranche->scheduled_date) ?? __('proposals.no_date_set') }}
                                        @if ($state === 'overdue')
                                            <span class="ms-1.5 text-xs font-bold text-primary">{{ trans_choice('proposals.count_days_overdue', $daysOverdue, ['count' => $daysOverdue]) }}</span>
                                        @elseif ($state === 'failed')
                                            <span class="ms-1.5 text-xs font-bold text-primary">{{ __('proposals.transfer_failed') }}</span>
                                        @elseif ($state === 'transit')
                                            <span class="ms-1.5 text-xs font-bold text-status-warn">{{ __('funding_agreements.tranche_state_transit') }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs {{ $mutedText }}">
                                        @if ($tranche->account === null)
                                            <span class="italic">{{ __('proposals.no_account_assigned') }}</span>
                                        @else
                                            <span>{{ $tranche->account->account_name }}@unless ($tranche->account->is_active) · {{ __('common.closed') }}@endunless</span>
                                        @endif
                                        @if ($tranche->receive_date !== null)
                                            <span class="tabular-nums">{{ __('proposals.arrived_on', ['date' => $day($tranche->receive_date)]) }}</span>
                                        @elseif ($tranche->transfer_date !== null)
                                            <span class="tabular-nums">{{ __('proposals.sent_on', ['date' => $day($tranche->transfer_date)]) }}</span>
                                        @endif
                                        @if ($fees > 0)
                                            <span class="tabular-nums">{{ __('proposals.fees_amount', ['amount' => $exact($fees), 'currency' => $tranche->fees_currency?->value]) }}</span>
                                        @endif
                                    </p>
                                </div>

                                <p class="shrink-0 text-base font-bold tabular-nums {{ $hasArrived ? $receivedText : $inkText }}">
                                    {{ $exact((float) $tranche->payment_amount) }}
                                    <span class="text-xs font-semibold {{ $mutedText }}">{{ $tranche->amount_transfered_currency?->value }}</span>
                                </p>

                                {{--
                                    Gated on the tranche having actually been received, not merely
                                    on net_amount_received being non-null: a pending tranche can
                                    carry a provisional net figure, and printing it here would
                                    report money that has not arrived.
                                --}}
                                <p class="shrink-0 text-xs tabular-nums @2xl/detail:w-44 @2xl/detail:text-end {{ $mutedText }}">
                                    @if ($hasArrived && $tranche->net_amount_received !== null)
                                        <span class="font-semibold {{ $receivedText }}">{{ $exact((float) $tranche->net_amount_received) }} {{ $tranche->amount_received_currency?->value }}</span>
                                        <span class="block">{{ __('proposals.net_at_rate', ['rate' => rtrim(rtrim((string) $tranche->exchange_rate, '0'), '.')]) }}</span>
                                    @else
                                        <span>{{ __('proposals.nothing_received_yet') }}</span>
                                    @endif
                                </p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </article>
        @endforeach
    </section>

    <div class="grid gap-x-10 gap-y-7 @4xl/detail:grid-cols-2">
        {{-- Projects this proposal funds. --}}
        <section id="projects" aria-labelledby="projects-heading" class="scroll-mt-32 border-t pt-6 {{ $hairline }}">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="projects-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.projects_covered') }}</h2>
                <p class="text-xs {{ $mutedText }}">{{ __('proposals.manage_these_links_from_the_project_page') }}</p>
            </div>

            @if ($this->projects->isEmpty())
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</p>
            @else
                <ul class="mt-3" role="list">
                    @foreach ($this->projects as $project)
                        <li class="relative py-2.5 ps-6" wire:key="project-{{ $project->id }}">
                            @unless ($loop->last)
                                <span class="absolute start-[0.3125rem] top-[1.125rem] h-full w-px {{ $threadLine }}" aria-hidden="true"></span>
                            @endunless
                            <span class="absolute start-0 top-3.5 size-2.5 rounded-full border-2 border-secondary-dark bg-white dark:border-white dark:bg-secondary-dark" aria-hidden="true"></span>

                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm leading-snug font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $project->name }}</a>
                            <p class="mt-0.5 text-xs {{ $mutedText }}">
                                {{ $project->city?->name ?? __('common.unknown_city') }}
                                <span aria-hidden="true">·</span> {{ $project->program->name }}
                                <span aria-hidden="true">·</span> {{ $project->status->label() }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Delivery: the band is the proportion, the list is the work. --}}
        <section id="delivery" aria-labelledby="delivery-heading" class="scroll-mt-32 border-t pt-6 {{ $hairline }}">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                <h2 id="delivery-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.delivery') }}</h2>
                @if ($delivery['total'] > 0)
                    <p class="text-xs tabular-nums {{ $mutedText }}">
                        {{ __('proposals.tasks_done_of', ['done' => $delivery['done'], 'total' => $delivery['total']]) }}
                        @if ($delivery['overdue'] > 0)
                            <span class="ms-1 font-bold text-primary">{{ trans_choice('proposals.count_overdue', $delivery['overdue'], ['count' => $delivery['overdue']]) }}</span>
                        @endif
                    </p>
                @endif
            </div>

            @if ($delivery['total'] === 0)
                <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.tasks_delivering_against_this_proposal_will_appear_here') }}</p>
            @else
                <div class="mt-4 flex h-2 w-full gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ __('proposals.delivery_band_label', ['done' => $delivery['done'], 'total' => $delivery['total']]) }}" data-delivery-band>
                    @foreach ($delivery['segments'] as $segment)
                        <span class="h-full rounded-full {{ $taskDot($segment['status']) }}" style="flex: {{ $segment['weight'] }} 1 0%" wire:key="band-{{ $loop->index }}"></span>
                    @endforeach
                </div>

                <div class="mt-5 flex flex-col gap-5">
                    @foreach ($delivery['groups'] as $group)
                        <div wire:key="task-group-{{ $group['status']->value }}">
                            <p class="type-label flex items-center gap-2 {{ $mutedText }}">
                                <span class="size-2 rounded-full {{ $taskDot($group['status']) }}" aria-hidden="true"></span>
                                {{ $group['status']->label() }}
                                <span class="tabular-nums">{{ $group['tasks']->count() }}</span>
                            </p>

                            <ul class="mt-1.5" role="list">
                                @foreach ($group['tasks'] as $task)
                                    @php
                                        $assignees = $task->taskAssignees->pluck('employee')->filter();
                                        $isOverdue = $task->status !== TaskStatus::Done && $task->due_date !== null && $task->due_date->lt(today());
                                    @endphp
                                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5" wire:key="task-{{ $task->id }}">
                                        <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-semibold underline-offset-4 hover:underline {{ $inkText }}">{{ $task->title }}</a>
                                        <span class="text-xs {{ $mutedText }}">{{ $assignees->isEmpty() ? __('common.not_assigned') : $assignees->map->name->implode(', ') }}</span>
                                        @if ($task->due_date !== null)
                                            <span @class(['ms-auto shrink-0 text-xs tabular-nums', 'font-bold text-primary' => $isOverdue, $mutedText => ! $isOverdue])>{{ $day($task->due_date) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- Targets: what the proposal promised, in the results chain it promised it against. --}}
    <section id="targets" aria-labelledby="targets-heading" class="scroll-mt-32 border-t pt-6 {{ $hairline }}">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <h2 id="targets-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.targets_committed_here') }}</h2>
            <p class="text-xs {{ $mutedText }}">{{ __('proposals.achievement_counts_approved_reports_only') }}</p>
        </div>

        @if ($this->targets === [])
            <p class="mt-4 text-sm {{ $mutedText }}">{{ __('proposals.indicator_targets_committed_in_this_proposal_will_appear_her') }}</p>
        @else
            <div class="mt-4 flex flex-col gap-6">
                @foreach ($this->targets as $group)
                    <div wire:key="result-{{ $group['result']->id }}" data-result="{{ $group['result']->id }}">
                        <div class="flex items-start gap-2.5">
                            <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $resultMark($group['result']->type) }}" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="type-label {{ $mutedText }}">{{ $group['result']->type->label() }}</p>
                                <p class="mt-0.5 max-w-[70ch] text-sm leading-snug font-semibold {{ $inkText }}">{{ $group['result']->statement }}</p>
                            </div>
                        </div>

                        <div class="ms-[0.3125rem] mt-2 flex flex-col border-s ps-6 {{ $hairline }}">
                            @foreach ($group['indicators'] as $indicatorGroup)
                                <div class="py-2.5" wire:key="indicator-{{ $indicatorGroup['indicator']->id }}">
                                    <p class="text-sm font-semibold {{ $inkText }}">{{ $indicatorGroup['indicator']->name }}</p>

                                    <ul class="mt-2 flex flex-col gap-3" role="list">
                                        @foreach ($indicatorGroup['targets'] as $target)
                                            @php
                                                $targetValue = (float) $target->target;
                                                $achieved = $target->achievedValue();
                                                $percent = $target->percentAchieved();
                                                $reached = $percent !== null && $percent >= 100;
                                            @endphp
                                            <li wire:key="target-{{ $target->id }}" data-target="{{ $target->id }}">
                                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                                    <x-ui.enum-badge :case="$target->period" />
                                                    <span class="min-w-0 text-xs {{ $mutedText }}">{{ $target->project?->name ?? __('common.organisation_wide') }}</span>
                                                    <span class="ms-auto shrink-0 text-sm tabular-nums {{ $mutedText }}">
                                                        <span @class(['text-base font-extrabold', $receivedText => $reached, $inkText => ! $reached])>{{ $amount($achieved) }}</span>
                                                        {{ __('proposals.of_target', ['target' => $amount($targetValue)]) }}
                                                        @if ($percent !== null)
                                                            <span class="ms-1 text-xs font-semibold">{{ __('proposals.percent_value', ['percent' => (int) floor($percent)]) }}</span>
                                                        @endif
                                                    </span>
                                                </div>

                                                @if ($targetValue > 0)
                                                    <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full {{ $threadLine }}" role="img" aria-label="{{ __('proposals.target_bar_label', ['achieved' => $amount($achieved), 'target' => $amount($targetValue)]) }}">
                                                        <span @class(['money-grow block h-full rounded-full', 'bg-funding' => $reached, 'bg-secondary-dark dark:bg-white' => ! $reached]) style="width: {{ min(100, round($achieved / $targetValue * 100, 2)) }}%"></span>
                                                    </div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{--
        ===================================================================
        STATUS HISTORY — OWNED BY THE STATUS-HISTORY REDESIGN SESSION.

        This is a plain, working placeholder so the page is complete on its
        own. It is deliberately the least opinionated section here. When
        merging, DISCARD everything between this banner and its closing
        banner and paste that session's version in its place.

        Two contracts the replacement must keep, because the rest of this
        page reaches into them:
          1. Each entry keeps `id="status-note-{id}"` — the lifecycle spine
             at the top of the page links to those anchors.
          2. The wrapper keeps `id="record"` and `scroll-mt-32` — the sticky
             jump rail targets it, and `sections()` counts it.
        ===================================================================
    --}}
    <section id="record" aria-labelledby="record-heading" class="scroll-mt-32 border-t py-6 {{ $hairline }}">
        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
            <h2 id="record-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.status_history') }}</h2>
            <p class="text-xs {{ $mutedText }}">{{ __('proposals.the_audit_trail_for_this_proposal_s_status_newest_first') }}</p>
        </div>

        @if ($this->statusNotes->isEmpty())
            <x-ui.empty-state icon="clock" :heading="__('proposals.no_status_history_yet')" :body="__('proposals.use_change_status_to_record_the_first_movement_and_why_it_ha')" />
        @else
            <ol class="mt-3 max-w-[80ch]" role="list">
                @foreach ($this->statusNotes as $note)
                    <li id="status-note-{{ $note->id }}" class="relative scroll-mt-32 py-3 ps-6 target:bg-board-head dark:target:bg-white/5" wire:key="status-note-{{ $note->id }}">
                        @unless ($loop->last)
                            <span class="absolute start-[0.3125rem] top-[1.1875rem] h-full w-px {{ $threadLine }}" aria-hidden="true"></span>
                        @endunless
                        <span @class([
                            'absolute start-0 top-3.5 size-2.5 rounded-full',
                            'bg-primary ring-4 ring-primary/20' => $loop->first,
                            'bg-secondary-light' => ! $loop->first,
                        ]) aria-hidden="true"></span>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <x-ui.enum-badge :case="$note->status" />
                            <time datetime="{{ $note->date?->toDateString() }}" class="text-xs tabular-nums {{ $mutedText }}">{{ $day($note->date) }}</time>
                        </div>
                        @if ($note->context !== null)
                            <p class="mt-1.5 text-sm leading-relaxed {{ $inkText }}">{{ $note->context }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
    {{-- =============== END STATUS HISTORY (replaceable) =============== --}}

    <x-ui.modal
        wire:model="showStatusModal"
        size="md"
        :title="__('proposals.change_status')"
        :description="__('proposals.the_new_status_and_your_reason_are_recorded_together_as_one')"
        submit="changeStatus"
        required-note
    >
        <div class="grid grid-cols-2 gap-x-4 gap-y-4">
            <x-ui.form-select wire:model="newStatus" :label="__('proposals.new_status')" required autofocus>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value">{{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}</flux:select.option>
                @endforeach
            </x-ui.form-select>

            <x-ui.form-input wire:model="statusDate" :label="__('proposals.effective_date')" type="date" required />

            <x-ui.form-textarea wire:model="statusContext" :label="__('proposals.why_did_it_change')" required class="col-span-2" />
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-status-button">{{ __('proposals.record_change') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
</section>
