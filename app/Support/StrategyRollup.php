<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MilestoneStatus;
use App\Enums\RagStatus;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Risk;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each strategy's projects add up to, for the strategies register: targets
 * on pace, funding agreed against requested, high risks and late work.
 *
 * Every figure reuses the app's own definition so it matches its register:
 * indicators belong through their result's program, everything else through
 * its project's program; targets are rated by Indicator::ragStatus() over this calendar year (as the
 * Desk rates them), a high risk scores 6 or more on the heat map
 * (Risk::heatScore()), a late milestone is Milestone::isMissed() and a late
 * report is Report::overdue(). Currencies are never added together.
 *
 * A figure the viewer may not open is null, and its query never runs.
 *
 * @phpstan-type FundingLine array{currency: string, requested: float, agreed: float, percent: int|null}
 * @phpstan-type Rollup array{
 *     results: array{on: int, total: int, tone: 'good'|'warn'|'critical'|null}|null,
 *     funding: array{lines: list<FundingLine>, percent: int|null, tone: 'good'|'warn'|'critical'|null}|null,
 *     risks: int|null,
 *     late: array{milestones: int|null, reports: int|null, total: int}|null
 * }
 */
final class StrategyRollup
{
    /** A risk at or above this heat score (likelihood × severity, 1–9) is high. */
    public const int HighRiskScore = 6;

    /** Funding at or above this share of the request reads good. */
    private const int FundedGood = 80;

    /** Funding below this share of the request reads critical. */
    private const int FundedLow = 50;

    /**
     * @param  list<int>  $strategyIds
     * @return array<int, Rollup>
     */
    public static function for(User $viewer, array $strategyIds): array
    {
        $projectStrategy = Project::query()
            ->join('programs', 'programs.id', '=', 'projects.program_id')
            ->whereIn('programs.strategy_id', $strategyIds)
            ->pluck('programs.strategy_id', 'projects.id')
            ->map(fn (mixed $id): int => (int) $id);

        $projectIds = array_values($projectStrategy->keys()->map(fn (mixed $id): int => (int) $id)->all());
        $canView = fn (string $module): bool => $viewer->can("{$module}.view");

        $results = $canView('indicators') && $canView('targets') ? self::results($strategyIds) : null;
        $funding = $canView('funding-agreements') ? self::funding($projectIds, $projectStrategy) : null;
        $risks = $canView('risks') ? self::risks($projectIds, $projectStrategy) : null;
        $milestones = $canView('milestones') ? self::lateMilestones($projectIds, $projectStrategy) : null;
        $reports = $canView('reports') ? self::lateReports($projectIds, $projectStrategy) : null;

        $rollups = [];

        foreach ($strategyIds as $id) {
            $lateMilestones = $milestones === null ? null : (int) ($milestones[$id] ?? 0);
            $lateReports = $reports === null ? null : (int) ($reports[$id] ?? 0);

            $rollups[$id] = [
                'results' => $results === null ? null : ($results[$id] ?? ['on' => 0, 'total' => 0, 'tone' => null]),
                'funding' => $funding === null ? null : ($funding[$id] ?? ['lines' => [], 'percent' => null, 'tone' => null]),
                'risks' => $risks === null ? null : (int) ($risks[$id] ?? 0),
                'late' => $lateMilestones === null && $lateReports === null ? null : [
                    'milestones' => $lateMilestones,
                    'reports' => $lateReports,
                    'total' => ($lateMilestones ?? 0) + ($lateReports ?? 0),
                ],
            ];
        }

        return $rollups;
    }

    /**
     * An indicator belongs to the strategy of its result's program, as the
     * indicators register groups it.
     *
     * @param  list<int>  $strategyIds
     * @return array<int, array{on: int, total: int, tone: 'good'|'warn'|'critical'|null}>
     */
    private static function results(array $strategyIds): array
    {
        $today = CarbonImmutable::today();

        $rated = Indicator::query()
            ->join('results', 'results.id', '=', 'indicators.result_id')
            ->join('programs', 'programs.id', '=', 'results.program_id')
            ->whereIn('programs.strategy_id', $strategyIds)
            ->whereNotNull('indicators.target')
            ->with('progress:id,indicator_id,date,value')
            ->get(['indicators.id', 'indicators.baseline', 'indicators.target', 'programs.strategy_id'])
            ->map(fn (Indicator $indicator): array => [
                'strategy' => (int) $indicator->getAttribute('strategy_id'),
                'rag' => $indicator->ragStatus($today->startOfYear(), $today->endOfYear()),
            ])
            ->filter(fn (array $row): bool => $row['rag'] !== null)
            ->groupBy('strategy');

        $results = [];

        foreach ($rated as $strategyId => $rows) {
            $total = $rows->count();
            $on = $rows->where('rag', RagStatus::Green)->count();
            $red = $rows->where('rag', RagStatus::Red)->count();

            $results[(int) $strategyId] = ['on' => $on, 'total' => $total, 'tone' => self::resultsTone($on, $red, $total)];
        }

        return $results;
    }

    /**
     * Requested is each linked proposal's requested budget; agreed is each of
     * those proposals' agreement budgets. One line per currency, largest
     * request first (then largest agreed); the headline percent is the first
     * line that has a request.
     *
     * @param  list<int>  $projectIds
     * @param  Collection<int, int>  $projectStrategy
     * @return array<int, array{lines: list<FundingLine>, percent: int|null, tone: 'good'|'warn'|'critical'|null}>
     */
    private static function funding(array $projectIds, Collection $projectStrategy): array
    {
        $proposalStrategies = DB::table('project_proposal')
            ->whereIn('project_id', $projectIds)
            ->get(['project_id', 'proposal_id'])
            ->map(fn (object $row): array => ['proposal' => (int) $row->proposal_id, 'strategy' => $projectStrategy[(int) $row->project_id]])
            ->unique(fn (array $row): string => $row['proposal'].'-'.$row['strategy']);

        $proposalIds = $proposalStrategies->pluck('proposal')->unique()->values()->all();

        $requested = Proposal::query()->whereKey($proposalIds)->whereNotNull('requested_budget')->get(['id', 'requested_budget', 'requested_currency'])->keyBy('id');
        $agreements = DonorProposal::query()->whereIn('proposal_id', $proposalIds)->get(['id', 'proposal_id', 'budget', 'currency'])->groupBy('proposal_id');

        $code = fn (mixed $currency): string => $currency instanceof BackedEnum ? (string) $currency->value : (string) $currency;

        $funding = [];

        foreach ($proposalStrategies->groupBy('strategy') as $strategyId => $rows) {
            $totals = [];

            foreach ($rows->pluck('proposal') as $proposalId) {
                if (($proposal = $requested->get($proposalId)) !== null) {
                    $currency = $code($proposal->requested_currency);
                    $totals[$currency]['requested'] = ($totals[$currency]['requested'] ?? 0.0) + (float) $proposal->requested_budget;
                }

                foreach ($agreements->get($proposalId, collect()) as $agreement) {
                    $currency = $code($agreement->currency);
                    $totals[$currency]['agreed'] = ($totals[$currency]['agreed'] ?? 0.0) + (float) $agreement->budget;
                }
            }

            $lines = collect($totals)
                ->map(function (array $sums, string $currency): array {
                    $requested = $sums['requested'] ?? 0.0;
                    $agreed = $sums['agreed'] ?? 0.0;

                    return [
                        'currency' => $currency,
                        'requested' => $requested,
                        'agreed' => $agreed,
                        'percent' => $requested > 0 ? (int) min(100, round($agreed / $requested * 100)) : null,
                    ];
                })
                ->sortBy([['requested', 'desc'], ['agreed', 'desc']])
                ->values();

            $percent = $lines->first(fn (array $line): bool => $line['percent'] !== null)['percent'] ?? null;

            $funding[(int) $strategyId] = ['lines' => array_values($lines->all()), 'percent' => $percent, 'tone' => self::fundingTone($percent)];
        }

        return $funding;
    }

    /**
     * All on pace is good; more than half off track is critical; else warn, as the Desk rates targets.
     *
     * @return 'good'|'warn'|'critical'
     */
    private static function resultsTone(int $on, int $red, int $total): string
    {
        return match (true) {
            $on === $total => 'good',
            $red * 2 > $total => 'critical',
            default => 'warn',
        };
    }

    /**
     * @return 'good'|'warn'|'critical'|null
     */
    private static function fundingTone(?int $percent): ?string
    {
        return match (true) {
            $percent === null => null,
            $percent >= self::FundedGood => 'good',
            $percent < self::FundedLow => 'critical',
            default => 'warn',
        };
    }

    /**
     * @param  list<int>  $projectIds
     * @param  Collection<int, int>  $projectStrategy
     * @return array<int, int>
     */
    private static function risks(array $projectIds, Collection $projectStrategy): array
    {
        return self::countByStrategy(
            Risk::query()->whereIn('project_id', $projectIds)->whereRaw('likelihood * severity >= ?', [self::HighRiskScore])->pluck('project_id'),
            $projectStrategy,
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @param  Collection<int, int>  $projectStrategy
     * @return array<int, int>
     */
    private static function lateMilestones(array $projectIds, Collection $projectStrategy): array
    {
        return self::countByStrategy(
            Milestone::query()
                ->whereIn('project_id', $projectIds)
                ->where('status', MilestoneStatus::Planned)
                ->whereDate('planned_end_date', '<', CarbonImmutable::today())
                ->pluck('project_id'),
            $projectStrategy,
        );
    }

    /**
     * @param  list<int>  $projectIds
     * @param  Collection<int, int>  $projectStrategy
     * @return array<int, int>
     */
    private static function lateReports(array $projectIds, Collection $projectStrategy): array
    {
        return self::countByStrategy(
            Report::query()
                ->overdue()
                ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
                ->whereIn('plan_indicators.project_id', $projectIds)
                ->pluck('plan_indicators.project_id'),
            $projectStrategy,
        );
    }

    /**
     * @param  Collection<int, mixed>  $projectIds  one entry per counted row
     * @param  Collection<int, int>  $projectStrategy
     * @return array<int, int>
     */
    private static function countByStrategy(Collection $projectIds, Collection $projectStrategy): array
    {
        return $projectIds
            ->map(fn (mixed $id): int => $projectStrategy[(int) $id])
            ->countBy()
            ->all();
    }
}
