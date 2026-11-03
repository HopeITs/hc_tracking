<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Enums\ArStatus;
use App\Enums\ProjectStatus;
use App\Enums\RagStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Desk\ReadsDeskFlow;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\TransfersTracking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One live line per ready layout: what that layout would show right now,
 * read for the signed-in viewer through the same scopes and queries as the
 * panels (DeskWork, ReadsDeskFlow, TeamScope, TransfersTracking::inState,
 * Indicator::ragStatus), and the suggestion they lead to. Only the Desk's
 * readySignals action calls this, when the Customize sheet opens: never
 * during a render.
 *
 * A signal states the number its card shows by default to this viewer.
 *
 * count is the figure the tone is about: the problem items when the tone is
 * critical or warn, the headline total when it is neutral, 0 when all is well.
 *
 * critical is late work this viewer is responsible for: their own decisions,
 * tasks, donor reports and projects, or the organisation's figures only for
 * the role that holds them (CaredForBy). Anything else that is late, however
 * large, is warn. Only critical can take the suggestion, so the suggestion
 * never moves on organisation-wide figures.
 *
 * @phpstan-type Signal array{text: string, tone: 'critical'|'warn'|'good'|'neutral', count: int}
 */
class ReadyLayoutSignals
{
    use ReadsDeskFlow;

    /**
     * How many times the favourite's count another layout's critical count
     * must reach to take the suggestion from it.
     */
    private const int OverrideFactor = 3;

    /**
     * The role that answers for a layout's organisation-wide figures.
     */
    private const array CaredForBy = [
        'money' => 'Financial',
        'impact' => 'MEAL',
        'fundraising' => 'Public Relations Manager',
    ];

    /**
     * Days ahead a deadline reads as close.
     */
    private const int SoonDays = 7;

    private User $viewer;

    public function __construct(private readonly Panels $panels) {}

    /**
     * The signal of every layout the viewer is offered, by layout key. A layout
     * left out has nothing to show this viewer: the sheet then drops its card.
     *
     * @param  array{total: int, late: int}  $needs  the greeting's decision counts (the Desk's needsCount)
     * @return array<string, Signal>
     */
    public function for(User $user, array $needs): array
    {
        $this->viewer = $user;
        $signals = [];

        foreach ($this->panels->readyLayoutsFor($user) as $layout) {
            $panels = array_column($layout['entries'], 'panel');

            $signal = match ($layout['key']) {
                'today' => $this->today($needs),
                'money' => $this->money(),
                'donors' => $this->donors(in_array('duties', $panels, true)),
                'tasks' => $this->tasks(),
                'delivery' => $this->delivery(),
                'impact' => $this->impact(),
                'fundraising' => $this->fundraising(),
                'team' => $this->team(),
                default => $this->overview(),
            };

            if ($signal !== null) {
                $signals[$layout['key']] = $signal;
            }
        }

        return $signals;
    }

    /**
     * The suggested layout: the role's favourite, unless another layout is
     * critical (so: this viewer's own late work) with at least OverrideFactor
     * times the favourite's count; the most urgent such layout then wins and
     * the reason says so.
     *
     * @param  array<string, Signal>  $signals
     * @return array{key: string|null, reason: string|null}
     */
    public function suggestionFor(User $user, array $signals): array
    {
        $favourite = $this->panels->favouriteReadyLayoutFor($user, array_map('strval', array_keys($signals)));

        if ($favourite === null) {
            return ['key' => null, 'reason' => null];
        }

        $floor = max(1, self::OverrideFactor * ($signals[$favourite]['count'] ?? 0));

        $urgent = collect($signals)
            ->except($favourite)
            ->filter(fn (array $signal): bool => $signal['tone'] === 'critical' && $signal['count'] >= $floor)
            ->sortByDesc('count')
            ->keys()
            ->first();

        return $urgent === null
            ? ['key' => $favourite, 'reason' => null]
            : ['key' => (string) $urgent, 'reason' => (string) __('desk.ready.reason_critical', ['favourite' => __("desk.ready.layouts.{$favourite}.title")])];
    }

    /**
     * @param  array{total: int, late: int}  $needs
     * @return Signal
     */
    private function today(array $needs): array
    {
        if ($needs['total'] === 0) {
            return $this->signal(__('desk.ready.signal.needs_none'), 'good', 0);
        }

        $waiting = trans_choice('desk.ready.signal.needs_wait', $needs['total'], ['count' => $needs['total']]);

        return $needs['late'] > 0
            ? $this->signal($this->join($waiting, trans_choice('desk.ready.signal.late', $needs['late'], ['count' => $needs['late']])), 'critical', $needs['late'])
            : $this->signal($waiting, 'warn', $needs['total']);
    }

    /**
     * Late as on the Transfers panel; what is still to arrive as on the
     * funding position, one amount per currency, never summed.
     *
     * @return Signal
     */
    private function money(): array
    {
        $late = TransfersTracking::query()->inState('late')->count();
        $pending = TransfersTracking::query()
            ->where('ar_status', ArStatus::Pending)
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total')
            ->groupBy('amount_transfered_currency')
            ->get()
            ->map(fn (Model $row): array => ['currency' => $this->currencyOf($row->getAttribute('currency')), 'total' => (float) $row->getAttribute('total')])
            ->sortByDesc('total')
            ->values();

        $toArrive = $pending->isEmpty() ? null : (string) __('desk.ready.signal.to_arrive', ['amount' => $this->amounts($pending)]);

        return match (true) {
            $late > 0 => $this->signal($this->join(trans_choice('desk.ready.signal.transfers_late', $late, ['count' => $late]), $toArrive), $this->lateTone('money'), $late),
            $toArrive !== null => $this->signal($this->join(__('desk.ready.signal.transfers_ok'), $toArrive), 'good', 0),
            default => $this->signal(__('desk.ready.signal.money_none'), 'good', 0),
        };
    }

    /**
     * Donors as on the Donors panel (those we wrote a proposal for); reports
     * as on the Donor reporting panel, only when the viewer has that panel.
     *
     * @return Signal
     */
    private function donors(bool $withReports): array
    {
        $donors = $this->visible(Donor::query(), 'donors')->whereHas('proposalsWrittenFor')->count();
        $headline = trans_choice('desk.ready.signal.donors', $donors, ['count' => $donors]);

        if (! $withReports) {
            return $this->signal($headline, 'neutral', $donors);
        }

        $today = CarbonImmutable::today();
        $reports = DeskWork::newestVersions(DeskWork::openDonorReports($this->viewer)
            ->select(['id', 'donor_proposal_id', 'type', 'period_start', 'period_end', 'version', 'due_on', 'responsible_employee_id'])
            ->orderByDesc('version')
            ->get());
        $late = $reports->filter(fn (DonorReport $report): bool => $report->due_on->lt($today));
        $overdue = $late->count();
        $mine = $late->where('responsible_employee_id', $this->viewer->employee_id ?? 0)->count();
        $thisMonth = $reports->filter(fn (DonorReport $report): bool => $report->due_on->betweenIncluded($today, $today->endOfMonth()))->count();

        return match (true) {
            $overdue > 0 => $this->signal($this->join($headline, trans_choice('desk.ready.signal.reports_overdue', $overdue, ['count' => $overdue])), $mine > 0 ? 'critical' : 'warn', $mine > 0 ? $mine : $overdue),
            $thisMonth > 0 => $this->signal($this->join($headline, trans_choice('desk.ready.signal.reports_month', $thisMonth, ['count' => $thisMonth])), 'warn', $thisMonth),
            default => $this->signal($this->join($headline, __('desk.ready.signal.reports_none')), 'good', 0),
        };
    }

    /**
     * The viewer's own tasks past due (the Tasks panel's "mine") and what is
     * waiting on others (the Waiting on others panel's rows).
     *
     * @return Signal
     */
    private function tasks(): array
    {
        $pastDue = DeskWork::myTasks($this->viewer)
            ->where('status', '!=', TaskStatus::Done)
            ->whereDate('due_date', '<', CarbonImmutable::today())
            ->count();
        $waiting = DeskWork::waitingTasks($this->viewer)->count()
            + DeskWork::waitingReports($this->viewer)->count()
            + DeskWork::waitingReviews($this->viewer)->count()
            + DeskWork::waitingDeliverables($this->viewer)->count();
        $waitingText = $waiting > 0 ? trans_choice('desk.ready.signal.tasks_waiting', $waiting, ['count' => $waiting]) : null;

        return match (true) {
            $pastDue > 0 => $this->signal($this->join(trans_choice('desk.ready.signal.tasks_late', $pastDue, ['count' => $pastDue]), $waitingText), 'critical', $pastDue),
            $waiting > 0 => $this->signal($this->join(__('desk.ready.signal.tasks_ok'), $waitingText), 'warn', $waiting),
            default => $this->signal(__('desk.ready.signal.tasks_none'), 'good', 0),
        };
    }

    /**
     * The projects card's default count (every project, shared through
     * DeskWork) and the Project flow panel's late figure (ReadsDeskFlow).
     * Critical only for projects the viewer owns.
     *
     * @return Signal
     */
    private function delivery(): array
    {
        $projects = DeskWork::projectsOnCard($this->viewer)->get(['id', 'user_id']);

        if ($projects->isEmpty()) {
            return $this->signal(__('desk.ready.signal.projects_none'), 'neutral', 0);
        }

        $flow = $this->flow();
        $headline = trans_choice('desk.ready.signal.projects', $projects->count(), ['count' => $projects->count()]);

        return $flow['late'] > 0
            ? $this->signal($this->join($headline, trans_choice('desk.ready.signal.late', $flow['late'], ['count' => $flow['late']])), $flow['mine'] > 0 ? 'critical' : 'warn', $flow['mine'] > 0 ? $flow['mine'] : $flow['late'])
            : $this->signal($this->join($headline, __('desk.ready.signal.projects_ok')), 'good', 0);
    }

    /**
     * Targets on pace this year, read as At a glance reads them: only what
     * Indicator::ragStatus() can rate.
     *
     * @return Signal
     */
    private function impact(): array
    {
        if (! $this->canView('targets') || ! $this->canView('indicators')) {
            return $this->signal(__('desk.ready.signal.targets_none'), 'neutral', 0);
        }

        $today = CarbonImmutable::today();
        $rated = Indicator::query()->whereNotNull('target')->with('progress:id,indicator_id,date,value')->get(['id', 'baseline', 'target'])
            ->map(fn (Indicator $indicator): ?RagStatus => $indicator->ragStatus($today->startOfYear(), $today->endOfYear()))
            ->filter();
        $total = $rated->count();
        $onPace = $rated->filter(fn (RagStatus $rag): bool => $rag === RagStatus::Green)->count();
        $red = $rated->filter(fn (RagStatus $rag): bool => $rag === RagStatus::Red)->count();
        $text = __('desk.ready.signal.targets', ['on' => $onPace, 'total' => $total]);

        return match (true) {
            $total === 0 => $this->signal(__('desk.ready.signal.targets_none'), 'neutral', 0),
            $onPace === $total => $this->signal(trans_choice('desk.ready.signal.targets_all', $total, ['count' => $total]), 'good', 0),
            $red * 2 > $total => $this->signal($text, $this->lateTone('impact'), $red),
            default => $this->signal($text, 'warn', $total - $onPace),
        };
    }

    /**
     * Every proposal on the Pipeline panel (DeskWork), the figure its header
     * shows; deadlines from those still open, read as the panel reads them:
     * the earliest open milestone, else the on-hold review date.
     *
     * @return Signal
     */
    private function fundraising(): array
    {
        $total = DeskWork::pipelineProposals($this->viewer)->count();

        if ($total === 0) {
            return $this->signal(__('desk.ready.signal.pipeline_none'), 'neutral', 0);
        }

        $today = CarbonImmutable::today();
        $pipeline = trans_choice('desk.ready.signal.pipeline', $total, ['count' => $total]);
        $deadlines = DeskWork::pipelineProposals($this->viewer)
            ->select(['id', 'status_remind_on'])
            ->whereNotIn('status', DeskWork::ClosedProposalStatuses)
            ->withMin(['milestones' => fn (Builder $query) => DeskWork::deadlineMilestones($query)], 'planned_end_date')
            ->get()
            ->map(fn (Proposal $proposal): ?CarbonImmutable => ($date = $proposal->getAttribute('milestones_min_planned_end_date') ?? $proposal->status_remind_on) === null ? null : CarbonImmutable::parse($date)->startOfDay())
            ->filter();
        $passed = $deadlines->filter(fn (CarbonImmutable $date): bool => $date->lt($today))->count();
        $next = $deadlines->filter(fn (CarbonImmutable $date): bool => $date->gte($today))->min();
        $days = $next === null ? null : (int) $today->diffInDays($next);

        return match (true) {
            $passed > 0 => $this->signal($this->join($pipeline, trans_choice('desk.ready.signal.deadlines_passed', $passed, ['count' => $passed])), $this->lateTone('fundraising'), $passed),
            $days === null => $this->signal($pipeline, 'neutral', $total),
            default => $this->signal($this->join($pipeline, trans_choice('desk.ready.signal.deadline_next', $days, ['count' => $days])), $days <= self::SoonDays ? 'warn' : 'neutral', $days <= self::SoonDays ? 1 : $total),
        };
    }

    /**
     * How many of the viewer's team read as overloaded on the My team panel
     * (TeamScope::load), counting tasks through the viewer's own visibility.
     * Null for someone with no team: the layout has nothing to show them.
     *
     * @return Signal|null
     */
    private function team(): ?array
    {
        $ids = HrEmployee::query()->whereKey(array_keys(TeamScope::for($this->viewer)))->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($ids === []) {
            return null;
        }

        $openTasks = fn (): Builder => $this->visible(Task::query(), 'tasks')->select('id')->where('status', '!=', TaskStatus::Done);
        $perPerson = fn (Builder $tasks): Collection => TaskAssignee::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('task_id', $tasks)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, count(*) as total')
            ->pluck('total', 'employee_id');
        $open = $perPerson($openTasks());
        $overdue = $perPerson($openTasks()->whereDate('due_date', '<', CarbonImmutable::today()));
        $total = count($ids);
        $overloaded = count(array_filter($ids, fn (int $id): bool => TeamScope::load((int) ($open[$id] ?? 0), (int) ($overdue[$id] ?? 0)) === 'overloaded'));

        return $overloaded > 0
            ? $this->signal(__('desk.ready.signal.team', ['overloaded' => $overloaded, 'total' => $total]), 'warn', $overloaded)
            : $this->signal(__('desk.ready.signal.team_ok', ['total' => $total]), 'good', 0);
    }

    /**
     * Running projects, as At a glance counts them.
     *
     * @return Signal
     */
    private function overview(): array
    {
        if (! $this->canView('projects')) {
            return $this->signal(__('desk.ready.signal.overview_none'), 'neutral', 0);
        }

        $running = $this->visible(Project::query(), 'projects')->where('status', ProjectStatus::Active)->count();

        return $this->signal(trans_choice('desk.ready.signal.running', $running, ['count' => $running]), 'neutral', $running);
    }

    /**
     * @param  Collection<int, array{currency: string, total: float}>  $pending  largest first
     */
    private function amounts(Collection $pending): string
    {
        $shown = $pending->take(2)->map(fn (array $row): string => $this->isolated(Money::format($row['total'], $row['currency'])))->implode(' + ');

        return $pending->count() > 2 ? $shown.' +'.($pending->count() - 2) : $shown;
    }

    /**
     * An amount inside Arabic text keeps its own left-to-right order, as the panels show it.
     */
    private function isolated(string $amount): string
    {
        return app()->getLocale() === 'ar' ? "\u{2066}{$amount}\u{2069}" : $amount;
    }

    /**
     * Late work reads critical only for the role that answers for this layout.
     *
     * @return 'critical'|'warn'
     */
    private function lateTone(string $layout): string
    {
        return isset(self::CaredForBy[$layout]) && $this->viewer->hasRole(self::CaredForBy[$layout]) ? 'critical' : 'warn';
    }

    private function currencyOf(mixed $currency): string
    {
        return $currency instanceof \BackedEnum ? (string) $currency->value : (string) $currency;
    }

    private function join(string $first, ?string $second): string
    {
        return $second === null ? $first : $first.' · '.$second;
    }

    /**
     * @param  'critical'|'warn'|'good'|'neutral'  $tone
     * @return Signal
     */
    private function signal(string $text, string $tone, int $count): array
    {
        return ['text' => $text, 'tone' => $tone, 'count' => $count];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function visible(Builder $query, string $module): Builder
    {
        return $this->canView($module) ? $query : $query->whereRaw('1 = 0');
    }

    protected function canView(string $module): bool
    {
        return $this->viewer->can("{$module}.view");
    }

    protected function canViewFundingPosition(): bool
    {
        return $this->canView('funding-agreements') && $this->canView('transfers');
    }
}
