<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Enums\RagStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one rule for how a project is pacing: its delivery progress set
 * against how much of its own date window has elapsed.
 *
 * The program page's overview cards and attention list read it. Callers map
 * the key to their own label and tone.
 */
final class ProjectPace
{
    /** Keys a caller should treat as an alarm. */
    public const CRITICAL = ['past_end', 'behind'];

    /** Keys a caller should treat as a warning. */
    public const WARNING = ['slipping'];

    /**
     * Weighted progress as a whole percentage, or null when nothing carries
     * a weight to measure against.
     */
    public static function progress(float|int|string|null $weightReached, float|int|string|null $weightTotal): ?int
    {
        $total = (float) ($weightTotal ?? 0);

        return $total > 0 ? (int) min(100, round((float) $weightReached / $total * 100)) : null;
    }

    /**
     * Share of the project's date window already behind us, 0–100, or null
     * without a usable window.
     */
    public static function elapsed(?CarbonInterface $start, ?CarbonInterface $end, ?CarbonInterface $today = null): ?float
    {
        if ($start === null || $end === null || $end->lt($start)) {
            return null;
        }

        $today ??= CarbonImmutable::today();
        $span = max(1, $end->getTimestamp() - $start->getTimestamp());

        return min(100, max(0, ($today->getTimestamp() - $start->getTimestamp()) / $span * 100));
    }

    /**
     * One of: closed, past_end, not_started, unmeasured, complete, no_window,
     * on_track, slipping, behind.
     */
    public static function rate(ProjectStatus $status, ?CarbonInterface $start, ?CarbonInterface $end, ?int $progress, ?CarbonInterface $today = null): string
    {
        $today ??= CarbonImmutable::today();
        $hasDates = $start !== null && $end !== null && $end->gte($start);
        $elapsed = self::elapsed($start, $end, $today);
        $isFinished = in_array($status, [ProjectStatus::Closed, ProjectStatus::Archived], true);
        $isPreparing = $status !== ProjectStatus::Active && ! $isFinished;

        return match (true) {
            $isFinished => 'closed',
            $hasDates && $today->gt($end) && ($progress ?? 0) < 100 => 'past_end',
            $isPreparing => 'not_started',
            $progress === null => 'unmeasured',
            $progress >= 100 => 'complete',
            $elapsed === null => 'no_window',
            $today->lt($start) => 'not_started',
            default => match (RagStatus::rate((float) $progress, $elapsed)) {
                RagStatus::Green => 'on_track',
                RagStatus::Amber => 'slipping',
                RagStatus::Red => 'behind',
            },
        };
    }
}
