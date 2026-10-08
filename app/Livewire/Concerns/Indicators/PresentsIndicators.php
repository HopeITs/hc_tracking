<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Enums\RagStatus;
use App\Enums\ReportStatus;

/**
 * How indicators/index draws a figure: number formatting, the tone of a
 * rating, the colour classes for that tone, the sparkline geometry and a
 * report's status chip.
 *
 * Used by indicators/index and its partials only. The page provides
 * nothing. The ResultType mark and accent maps stay plain variables on the
 * page, copied from the shared mapping (.ai/rules/components-ui.md).
 */
trait PresentsIndicators
{
    /** Trim the trailing zeros off a measurement without losing real precision. */
    protected function figure(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    }

    protected function ragTone(?RagStatus $case): string
    {
        return match ($case) {
            RagStatus::Green => 'good',
            RagStatus::Amber => 'warn',
            RagStatus::Red => 'critical',
            default => 'neutral',
        };
    }

    protected function toneText(string $tone): string
    {
        return match ($tone) {
            'good' => 'text-status-good',
            'warn' => 'text-status-warn',
            'critical' => 'text-primary dark:text-brand-text',
            default => 'text-secondary-medium dark:text-ink-muted',
        };
    }

    protected function toneFill(string $tone): string
    {
        return match ($tone) {
            'good' => 'bg-status-good',
            'warn' => 'bg-status-warn',
            'critical' => 'bg-primary dark:bg-status-critical',
            default => 'bg-secondary-medium dark:bg-ink-muted',
        };
    }

    /**
     * A reading series drawn against its own target.
     *
     * The vertical scale spans the readings, the baseline and the target
     * together, so the line is always read against the thing it is chasing
     * rather than against itself — a series that crawls flat below its target
     * must not look like a series that is nearly there.
     *
     * @param  array<int, float>  $series
     * @return array{line: string, area: string, last: array{float, float}, lastPct: array{float, float}, targetY: float|null, baselineY: float}|null
     */
    protected function spark(array $series, float $baseline, ?float $target): ?array
    {
        if (count($series) < 2) {
            return null;
        }

        $bounds = array_merge($series, [$baseline], $target === null ? [] : [$target]);
        $min = min($bounds);
        $max = max($bounds);
        $span = $max - $min;
        $width = 100.0;
        $height = 30.0;

        $y = fn (float $value): float => $span > 0.0
            ? round($height - (($value - $min) / $span) * $height, 2)
            : round($height / 2, 2);

        $points = [];
        foreach (array_values($series) as $index => $value) {
            $points[] = [round(($index / (count($series) - 1)) * $width, 2), $y((float) $value)];
        }

        $line = implode(' ', array_map(fn (array $point): string => $point[0].','.$point[1], $points));
        $last = $points[count($points) - 1];

        return [
            'line' => $line,
            'area' => 'M '.$points[0][0].','.$height.' L '.str_replace(' ', ' L ', $line).' L '.$last[0].','.$height.' Z',
            'last' => $last,
            // The end point as percentages of the box, so the "latest" marker
            // can be an HTML dot layered over the chart. Inside the SVG it
            // would inherit preserveAspectRatio="none" and render as a lozenge
            // whose shape changed with every card width.
            'lastPct' => [$last[0] / $width * 100, $last[1] / $height * 100],
            'targetY' => $target === null ? null : $y($target),
            'baselineY' => $y($baseline),
        ];
    }

    protected function reportStatusChip(ReportStatus $status): string
    {
        return match ($status) {
            ReportStatus::Approved => 'border-status-good/30 bg-status-good-soft text-status-good',
            ReportStatus::Reviewed => 'border-secondary-light text-secondary-medium dark:text-ink-muted',
            ReportStatus::Draft => 'border-secondary-light bg-secondary-soft text-secondary-medium dark:bg-surface-2 dark:text-ink-muted',
        };
    }
}
