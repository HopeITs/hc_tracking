<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\ExecutiveSummary;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Report;
use App\Models\TransfersTracking;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The records behind the figures: the year-scoped queries the measures read,
 * the per-row decompositions a ledger row opens on to, and the small rules
 * that turn a share into a tone, a band or a delta.
 *
 * Used by executive-summary/index only. targetsByProgram(),
 * moneyByAgreement(), milestonesByProject() and reportsByIndicator() read the
 * page's #[Computed] fiscalYear and call percentageOf() / shareOfAmount() from
 * ReadsSummaryCoverage. ReadsSummaryCoverage calls projectsRunningIn(),
 * committedTargets(), plansOf() and committedThisYear(); ReadsSummaryLedger
 * calls the four decompositions, leadingCurrency(), bandsFor(), toneFor() and
 * deltaBetween().
 *
 * @property-read int $fiscalYear
 */
trait LoadsSummarySources
{
    /**
     * How many rows a decomposed gap shows before it stops.
     */
    private const BreakdownLimit = 8;

    /**
     * Projects whose period overlaps the fiscal year.
     *
     * @return Builder<Project>
     */
    private function projectsRunningIn(int $year): Builder
    {
        return Project::query()
            ->where(fn (Builder $query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', $year.'-12-31'))
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $year.'-01-01'));
    }

    /**
     * The targets committed in the year's plans, with everything the
     * achievement rule needs already loaded.
     *
     * @return Collection<int, PlanIndicator>
     */
    private function committedTargets(int $year): Collection
    {
        return PlanIndicator::query()
            ->with([
                'indicator:id,name,measure',
                'reports:id,plan_indicator_id,status,value,planned_date',
            ])
            ->where('source_type', 'annual_plan')
            ->whereIn('source_id', $this->plansOf($year)->pluck('id'))
            ->get();
    }

    /**
     * The year's annual plans, with the program each one covers.
     *
     * @return Collection<int, AnnualPlan>
     */
    private function plansOf(int $year): Collection
    {
        return AnnualPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->with('program:id')
            ->where('year', $year)
            ->get();
    }

    /**
     * The targets gap, decomposed into the programs that own it.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function targetsByProgram(): array
    {
        $plans = $this->plansOf($this->fiscalYear)->keyBy('id');
        $targets = $this->committedTargets($this->fiscalYear);

        return $targets
            ->groupBy(fn (PlanIndicator $target): int => (int) ($plans->get($target->source_id)?->program_id ?? 0))
            ->map(function (Collection $group, int $programId) use ($plans): array {
                $program = $plans->firstWhere('program_id', $programId)?->program;
                $achieved = $group->filter(fn (PlanIndicator $target): bool => $target->achievementStatus() === 'achieved')->count();
                $share = $this->percentageOf($achieved, $group->count());

                return [
                    'key' => 'program-'.$programId,
                    'name' => $program?->name ?? __('executive_summary.unassigned_program'),
                    'context' => trans_choice('executive_summary.count_indicators', $group->pluck('indicator_id')->unique()->count(), [
                        'count' => $group->pluck('indicator_id')->unique()->count(),
                    ]),
                    'committed' => $group->count(),
                    'delivered' => $achieved,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => $program === null ? null : route('programs.show', $program),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * One currency's gap, decomposed into the agreements that owe it.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function moneyByAgreement(string $code): array
    {
        $year = $this->fiscalYear;

        return TransfersTracking::query()
            ->with(['donorProposal:id,donor_id,proposal_id,external_project_number', 'donorProposal.donor:id,name', 'donorProposal.proposal:id,name,internal_project_number'])
            ->where(function (Builder $query) use ($code, $year): void {
                $query
                    ->where(fn (Builder $scheduled) => $scheduled->where('amount_transfered_currency', $code)->whereYear('scheduled_date', $year))
                    ->orWhere(fn (Builder $landed) => $landed->where('amount_received_currency', $code)->whereYear('receive_date', $year));
            })
            ->get()
            ->groupBy('donor_proposal_id')
            ->map(function (Collection $tranches) use ($code, $year): array {
                $agreement = $tranches->first()->donorProposal;
                $scheduled = (float) $tranches
                    ->filter(fn (TransfersTracking $tranche): bool => $tranche->amount_transfered_currency?->value === $code
                        && (int) $tranche->scheduled_date?->year === $year)
                    ->sum(fn (TransfersTracking $tranche): float => (float) $tranche->payment_amount);
                $landed = (float) $tranches
                    ->filter(fn (TransfersTracking $tranche): bool => $tranche->ar_status === ArStatus::Received
                        && $tranche->net_amount_received !== null
                        && $tranche->amount_received_currency?->value === $code
                        && (int) $tranche->receive_date?->year === $year)
                    ->sum(fn (TransfersTracking $tranche): float => (float) $tranche->net_amount_received);
                $share = $this->shareOfAmount($landed, $scheduled);

                return [
                    'key' => 'agreement-'.($agreement?->id ?? 0),
                    'name' => $agreement?->donor?->name ?? __('executive_summary.unassigned_agreement'),
                    'context' => $agreement?->proposal === null ? '—' : $agreement->proposal->name.' · '.$agreement->proposal->internal_project_number,
                    'committed' => $scheduled,
                    'delivered' => $landed,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => $agreement === null ? null : route('funding-agreements.show', $agreement),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * The milestone gap, decomposed into the projects that own it.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function milestonesByProject(): array
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'status'])
            ->with('project:id,name')
            ->whereRaw('year(coalesce(planned_end_date, planned_start_date)) = ?', [$this->fiscalYear])
            ->where('status', '!=', MilestoneStatus::Cancelled)
            ->get()
            ->groupBy('project_id')
            ->map(function (Collection $milestones): array {
                $project = $milestones->first()->project;
                $reached = $milestones->where('status', MilestoneStatus::Reached)->count();
                $share = $this->percentageOf($reached, $milestones->count());

                return [
                    'key' => 'project-'.($project?->id ?? 0),
                    'name' => $project?->name ?? __('executive_summary.unassigned_project'),
                    'context' => trans_choice('executive_summary.count_milestones', $milestones->count(), ['count' => $milestones->count()]),
                    'committed' => $milestones->count(),
                    'delivered' => $reached,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => $project === null ? null : route('projects.show', $project),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * The reporting gap, decomposed into the indicators being reported on.
     *
     * @return array<int, array{key: string, name: string, context: string, committed: int|float, delivered: int|float, share: int|null, tone: string, href: string|null}>
     */
    private function reportsByIndicator(): array
    {
        return Report::query()
            ->select(['id', 'plan_indicator_id', 'status'])
            ->with(['planIndicator:id,indicator_id', 'planIndicator.indicator:id,name'])
            ->whereYear('planned_date', $this->fiscalYear)
            ->get()
            ->groupBy(fn (Report $report): int => (int) ($report->planIndicator?->indicator_id ?? 0))
            ->map(function (Collection $reports): array {
                $indicator = $reports->first()->planIndicator?->indicator;
                $approved = $reports->where('status', ReportStatus::Approved)->count();
                $share = $this->percentageOf($approved, $reports->count());

                return [
                    'key' => 'indicator-'.($indicator?->id ?? 0),
                    'name' => $indicator?->name ?? __('executive_summary.unassigned_indicator'),
                    'context' => trans_choice('executive_summary.count_reports', $reports->count(), ['count' => $reports->count()]),
                    'committed' => $reports->count(),
                    'delivered' => $approved,
                    'share' => $share,
                    'tone' => $this->toneFor($share),
                    'href' => route('reports.index'),
                ];
            })
            ->sortBy([['share', 'asc'], ['name', 'asc']])
            ->take(self::BreakdownLimit)
            ->values()
            ->all();
    }

    /**
     * The currency carrying the most money this year, or null when none moved.
     *
     * @param  array<string, array{code: string, scheduled: float, landed: float, scheduledTranches: int, landedTranches: int}>  $money
     * @return array{code: string, scheduled: float, landed: float, scheduledTranches: int, landedTranches: int}|null
     */
    private function leadingCurrency(array $money): ?array
    {
        return collect($money)
            ->sortByDesc(fn (array $line): float => max($line['scheduled'], $line['landed']))
            ->first();
    }

    /**
     * The measure's segments, as whole percentages of the commitment.
     *
     * @param  array<int, array{0: int|float, 1: string}>  $parts
     * @return array<int, array{share: int, tone: string}>
     */
    private function bandsFor(array $parts, int|float $total): array
    {
        if ($total <= 0) {
            return [];
        }

        $bands = [];
        $used = 0;

        foreach ($parts as [$value, $tone]) {
            $share = min(100 - $used, (int) floor((float) $value / (float) $total * 100));

            if ($share > 0) {
                $bands[] = ['share' => $share, 'tone' => $tone];
                $used += $share;
            }
        }

        return $bands;
    }

    /**
     * How a share reads: met, part-met, or nothing delivered at all.
     *
     * Red is reserved for a commitment nothing has answered, so that an alarm
     * on this page always means the same thing.
     */
    private function toneFor(?int $share): string
    {
        return match (true) {
            $share === null => 'neutral',
            $share >= 100 => 'good',
            $share > 0 => 'warn',
            default => 'critical',
        };
    }

    /**
     * The movement between two shares in percentage points, or null when
     * either year has nothing to compare.
     */
    private function deltaBetween(?int $now, ?int $before): ?int
    {
        if ($now === null || $before === null) {
            return null;
        }

        return $now - $before;
    }

    /**
     * Constrain plan indicators to the targets committed in a year's plans.
     *
     * @return Closure(Builder<PlanIndicator>): void
     */
    private function committedThisYear(int $year): callable
    {
        return function (Builder $query) use ($year): void {
            $query
                ->where('source_type', 'annual_plan')
                ->whereIn('source_id', AnnualPlan::query()->select('id')->where('year', $year));
        };
    }
}
