<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RagStatus;
use App\Models\AnnualPlan;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\Risk;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each strategy's places add up to, for the strategies register: targets
 * on pace, funding agreed against requested, high risks and late work.
 *
 * Every figure reuses the app's own definition so it matches its register:
 * indicators belong through their result's program, a register risk through
 * its location row's program (Y-13, Y-16), everything else through its row's
 * program; targets are rated by Indicator::ragStatus() over this calendar year (as the
 * Desk rates them), a high risk scores 6 or more on the heat map
 * (Risk::heatScore()) and a late report is Report::overdue(). Currencies are never added together.
 *
 * A figure the viewer may not open is null, and its query never runs.
 *
 * @phpstan-type FundingLine array{currency: string, requested: float, agreed: float, percent: int|null}
 * @phpstan-type Rollup array{
 *     results: array{on: int, total: int, unrated: int, tone: 'good'|'warn'|'critical'|null, by_program: array<int, array{on: int, total: int}>}|null,
 *     funding: array{lines: list<FundingLine>, percent: int|null, tone: 'good'|'warn'|'critical'|null}|null,
 *     risks: int|null,
 *     late: array{reports: int|null, total: int}|null
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

    /** On pace for at least this share of rated targets reads "On track". */
    public const int OnTrackShare = 95;

    /** On pace for at least this share (below OnTrackShare) reads "Mostly on track"; less is "Off track". */
    public const int MostlyOnTrackShare = 80;

    /**
     * @param  list<int>  $strategyIds
     * @return array<int, Rollup>
     */
    public static function for(User $viewer, array $strategyIds): array
    {
        $locationStrategy = self::locationStrategy($strategyIds);
        $canView = fn (string $module): bool => $viewer->can("{$module}.view");

        $results = $canView('indicators') && $canView('targets') ? self::results($strategyIds) : null;
        $funding = $canView('funding-agreements') ? self::funding($locationStrategy) : null;
        $risks = $canView('risks') ? self::risks($locationStrategy) : null;
        $reports = $canView('reports') ? self::lateReports($strategyIds) : null;

        $rollups = [];

        foreach ($strategyIds as $id) {
            $lateReports = $reports === null ? null : (int) ($reports[$id] ?? 0);

            $rollups[$id] = [
                'results' => $results === null ? null : ($results[$id] ?? ['on' => 0, 'total' => 0, 'unrated' => 0, 'tone' => null, 'by_program' => []]),
                'funding' => $funding === null ? null : ($funding[$id] ?? ['lines' => [], 'percent' => null, 'tone' => null]),
                'risks' => $risks === null ? null : (int) ($risks[$id] ?? 0),
                'late' => $lateReports === null ? null : [
                    'reports' => $lateReports,
                    'total' => $lateReports,
                ],
            ];
        }

        return $rollups;
    }

    /**
     * The headline verdict on a count of rated targets: 95% or more on pace is
     * on track, 80% or more mostly on track, anything less off track. No rated
     * targets, no verdict. Targets with no figures yet ($unrated) never earn a
     * green "On track": while any is unmeasured the best verdict is
     * "Not fully measured" (page review, 6 Nov 2026: "39 of 39 on pace" was
     * shown in green while 22 more targets had no figures at all). `all`
     * counts every target, rated or not, for the sentence.
     *
     * @return array{key: 'on_track'|'incomplete'|'mostly_on_track'|'off_track', tone: 'good'|'warn'|'critical', on: int, total: int, behind: int, unrated: int, all: int}|null
     */
    public static function verdict(int $on, int $total, int $unrated = 0): ?array
    {
        if ($total === 0) {
            return null;
        }

        [$key, $tone] = match (true) {
            $on * 100 >= self::OnTrackShare * $total => $unrated > 0 ? ['incomplete', 'warn'] : ['on_track', 'good'],
            $on * 100 >= self::MostlyOnTrackShare * $total => ['mostly_on_track', 'warn'],
            default => ['off_track', 'critical'],
        };

        return ['key' => $key, 'tone' => $tone, 'on' => $on, 'total' => $total, 'behind' => $total - $on, 'unrated' => $unrated, 'all' => $total + $unrated];
    }

    /**
     * One program's pace from its `by_program` counts: behind below the
     * "Mostly on track" share, otherwise on pace; null with nothing rated.
     *
     * @param  array{on: int, total: int}|null  $counts
     * @return 'on_pace'|'behind'|null
     */
    public static function programPace(?array $counts): ?string
    {
        if ($counts === null || $counts['total'] === 0) {
            return null;
        }

        return self::MostlyOnTrackShare * $counts['total'] > $counts['on'] * 100 ? 'behind' : 'on_pace';
    }

    /**
     * How many of each strategy's programs have a plan for the strategy's
     * year (an annual plan with at least one plan indicator), given
     * [strategyId => year]. Null when the viewer may not see annual plans,
     * and then nothing is queried.
     *
     * @param  array<int, int>  $yearByStrategy
     * @return array<int, array{planned: int, programs: int, missing: int}>|null
     */
    public static function planReadiness(User $viewer, array $yearByStrategy): ?array
    {
        if (! $viewer->can('annual-plans.view')) {
            return null;
        }

        $programsByStrategy = Program::query()
            ->whereIn('strategy_id', array_keys($yearByStrategy))
            ->get(['id', 'strategy_id'])
            ->groupBy('strategy_id');

        $plannedKeys = AnnualPlan::query()
            ->whereIn('program_id', $programsByStrategy->flatten()->pluck('id'))
            ->whereIn('year', array_values(array_unique($yearByStrategy)))
            ->whereHas('planIndicators')
            ->get(['program_id', 'year'])
            ->mapWithKeys(fn (AnnualPlan $plan): array => [$plan->program_id.':'.$plan->year => true]);

        $readiness = [];

        foreach ($yearByStrategy as $strategyId => $year) {
            $programIds = $programsByStrategy->get($strategyId, collect())->pluck('id');
            $planned = $programIds->filter(fn (mixed $id): bool => $plannedKeys->has($id.':'.$year))->count();

            $readiness[$strategyId] = ['planned' => $planned, 'programs' => $programIds->count(), 'missing' => $programIds->count() - $planned];
        }

        return $readiness;
    }

    /**
     * An indicator belongs to the strategy of its result's program, as the
     * indicators register groups it.
     *
     * @param  list<int>  $strategyIds
     * @return array<int, array{on: int, total: int, unrated: int, tone: 'good'|'warn'|'critical'|null, by_program: array<int, array{on: int, total: int}>}>
     */
    private static function results(array $strategyIds): array
    {
        $today = CarbonImmutable::today();

        $targets = Indicator::query()
            ->join('results', 'results.id', '=', 'indicators.result_id')
            ->join('programs', 'programs.id', '=', 'results.program_id')
            ->whereIn('programs.strategy_id', $strategyIds)
            ->whereNotNull('indicators.target')
            ->with(['progress:id,indicator_id,date,value', 'approvedMonthlyMeasurements'])
            ->get(['indicators.id', 'indicators.baseline', 'indicators.target', 'indicators.measure', 'programs.strategy_id', 'programs.id as program_id'])
            ->map(fn (Indicator $indicator): array => [
                'strategy' => (int) $indicator->getAttribute('strategy_id'),
                'program' => (int) $indicator->getAttribute('program_id'),
                'rag' => $indicator->ragStatus($today->startOfYear(), $today->endOfYear()),
            ])
            ->groupBy('strategy');

        $results = [];

        foreach ($targets as $strategyId => $all) {
            $rows = $all->filter(fn (array $row): bool => $row['rag'] !== null);
            $unrated = $all->count() - $rows->count();
            $total = $rows->count();
            $on = $rows->where('rag', RagStatus::Green)->count();
            $red = $rows->where('rag', RagStatus::Red)->count();

            $byProgram = [];

            foreach ($rows->groupBy('program') as $programId => $programRows) {
                $byProgram[(int) $programId] = [
                    'on' => $programRows->where('rag', RagStatus::Green)->count(),
                    'total' => $programRows->count(),
                ];
            }

            $results[(int) $strategyId] = ['on' => $on, 'total' => $total, 'unrated' => $unrated, 'tone' => self::resultsTone($on, $red, $total), 'by_program' => $byProgram];
        }

        return $results;
    }

    /**
     * Requested is each linked proposal's requested budget; agreed is each of
     * those proposals' agreement budgets. One line per currency, largest
     * request first (then largest agreed); the headline percent is the first
     * line that has a request.
     *
     * A row is of exactly one proposal (D-93), so the proposals of a strategy
     * are read off the rows themselves and `project_proposal` is not touched
     * (Y-16).
     *
     * @param  Collection<int, int>  $locationStrategy
     * @return array<int, array{lines: list<FundingLine>, percent: int|null, tone: 'good'|'warn'|'critical'|null}>
     */
    private static function funding(Collection $locationStrategy): array
    {
        $proposalStrategies = ProposalLocation::query()
            ->whereKey($locationStrategy->keys())
            ->get(['id', 'proposal_id'])
            ->map(fn (ProposalLocation $row): array => ['proposal' => (int) $row->proposal_id, 'strategy' => $locationStrategy[$row->id]])
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
     * High risks per strategy. A register risk belongs to a location row
     * (Y-13, D-93), so it reaches a strategy through the row's program, not
     * through a project. Logframe risks carry no row and are not counted here.
     *
     * @param  Collection<int, int>  $locationStrategy
     * @return array<int, int>
     */
    private static function risks(Collection $locationStrategy): array
    {
        return self::countByStrategy(
            Risk::query()
                ->whereIn('proposal_location_id', $locationStrategy->keys())
                ->whereRaw('likelihood * severity >= ?', [self::HighRiskScore])
                ->pluck('proposal_location_id'),
            $locationStrategy,
        );
    }

    /**
     * Late reports per strategy, through the **program of the target's place**
     * (Y-08, Y-16).
     *
     * Not through the location row: an annual-plan target carries no row
     * (D-44) and its late reports must still count, which keying on the row
     * silently dropped. The place's program is on every placed target, so one
     * rule covers both sources.
     *
     * @param  list<int>  $strategyIds
     * @return array<int, int>
     */
    private static function lateReports(array $strategyIds): array
    {
        $programStrategy = DB::table('programs')
            ->whereIn('strategy_id', $strategyIds)
            ->pluck('strategy_id', 'id')
            ->map(fn (mixed $id): int => (int) $id);

        return self::countByStrategy(
            Report::query()
                ->overdue()
                ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
                ->whereIn('plan_indicators.program_id', $programStrategy->keys())
                ->pluck('plan_indicators.program_id'),
            $programStrategy,
        );
    }

    /**
     * Which strategy each location row belongs to, through its program
     * (Y-16, D-93). The one map behind funding, risks and late reports.
     *
     * @param  list<int>  $strategyIds
     * @return Collection<int, int> row id => strategy id
     */
    private static function locationStrategy(array $strategyIds): Collection
    {
        return ProposalLocation::query()
            ->join('programs', 'programs.id', '=', 'proposal_locations.program_id')
            ->whereIn('programs.strategy_id', $strategyIds)
            ->pluck('programs.strategy_id', 'proposal_locations.id')
            ->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * Count ids by the strategy they belong to: location row ids for risks
     * (Y-13) and funding (Y-16), program ids for late reports, which must also
     * count annual-plan targets that carry no row (D-44).
     *
     * @param  Collection<int, mixed>  $ids  one entry per counted row
     * @param  Collection<int, int>  $idStrategy
     * @return array<int, int>
     */
    private static function countByStrategy(Collection $ids, Collection $idStrategy): array
    {
        return $ids
            ->map(fn (mixed $id): int => $idStrategy[(int) $id])
            ->countBy()
            ->all();
    }
}
