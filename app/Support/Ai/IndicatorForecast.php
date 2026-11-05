<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\IndicatorProgress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where an indicator is heading: a straight-line trend through its readings
 * (least squares over time), read at the end of the window and against the
 * target. Plain arithmetic, no model: the same readings always give the same
 * forecast, and it explains itself.
 */
final class IndicatorForecast
{
    /**
     * Fewer readings than this give no trend worth showing.
     */
    public const int MIN_READINGS = 3;

    /**
     * @param  Collection<int, IndicatorProgress>  $readings  Oldest first.
     * @return array{state: 'reaches'|'falls_short'|'moving_away'|'flat', projected: float, projected_percent: float|null, reaches_on: CarbonImmutable|null, window_end: CarbonImmutable, per_month: float}|null
     */
    public static function for(Collection $readings, float $baseline, ?float $target, CarbonImmutable $windowEnd): ?array
    {
        $points = $readings
            ->map(fn (IndicatorProgress $reading): array => [(float) ($reading->date->getTimestamp() / 86400), (float) $reading->value])
            ->values();

        if ($target === null || $points->count() < self::MIN_READINGS || $points->pluck(0)->unique()->count() < 2) {
            return null;
        }

        [$slope, $intercept] = self::line(array_values($points->all()));
        $projected = $slope * ($windowEnd->getTimestamp() / 86400) + $intercept;
        $span = $target - $baseline;
        $towardTarget = $span === 0.0 ? 0.0 : $slope * ($span <=> 0);
        $reachesOn = null;

        if ($slope !== 0.0) {
            $day = ($target - $intercept) / $slope;
            $lastDay = (float) $points->last()[0];
            $reachesOn = $towardTarget > 0 && $day >= $lastDay ? CarbonImmutable::createFromTimestamp((int) round($day * 86400))->startOfDay() : null;
        }

        $state = match (true) {
            abs($slope) < 1e-9 => 'flat',
            $towardTarget < 0 => 'moving_away',
            $reachesOn !== null && $reachesOn->lte($windowEnd) => 'reaches',
            default => 'falls_short',
        };

        return [
            'state' => $state,
            'projected' => round($projected, 1),
            'projected_percent' => $span === 0.0 ? null : round((($projected - $baseline) / $span) * 100, 1),
            'reaches_on' => $state === 'reaches' ? $reachesOn : null,
            'window_end' => $windowEnd,
            'per_month' => round($slope * 30.44, 1),
        ];
    }

    /**
     * Least-squares line through (day, value) points.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return array{0: float, 1: float} slope per day, intercept
     */
    private static function line(array $points): array
    {
        $count = count($points);
        $meanX = array_sum(array_column($points, 0)) / $count;
        $meanY = array_sum(array_column($points, 1)) / $count;
        $covariance = 0.0;
        $variance = 0.0;

        foreach ($points as [$x, $y]) {
            $covariance += ($x - $meanX) * ($y - $meanY);
            $variance += ($x - $meanX) ** 2;
        }

        $slope = $variance === 0.0 ? 0.0 : $covariance / $variance;

        return [$slope, $meanY - $slope * $meanX];
    }
}
