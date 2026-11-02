<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The one visual vocabulary every status-like enum maps onto (W-28).
 *
 * A status enum implements HasStatusTone and answers tone() with one of
 * these; the badge, pill and board/calendar dots then take their colours
 * from here instead of each keeping a per-enum colour map. The classes use
 * the status tokens in resources/css/tokens.css, which carry their own
 * `.dark` values, so one class list reads correctly in both modes.
 *
 * Tailwind scans this file through an `@source` line in app.css: a class
 * written here is compiled even when no view repeats it.
 */
enum StatusTone: string
{
    /** Finished or accepted: done, reached, approved, validated, green. */
    case Good = 'good';

    /** Needs attention but is not failed: pending, on hold, postponed, amber. */
    case Warn = 'warn';

    /** Failed or refused: rejected, failed, denied, red, high priority. */
    case Critical = 'critical';

    /** In flight: in progress, ongoing, submitted, delivered, active. */
    case Active = 'active';

    /** Not started or out of play: todo, draft, planned, cancelled, low. */
    case Neutral = 'neutral';

    /**
     * Tinted badge: soft background, tone-coloured text, a faint border.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Good => 'border-status-good/30 bg-status-good-soft text-status-good',
            self::Warn => 'border-status-warn/30 bg-status-warn-soft text-status-warn',
            self::Critical => 'border-status-critical/30 bg-status-critical-soft text-status-critical',
            self::Active => 'border-status-active/30 bg-status-active-soft text-status-active',
            self::Neutral => 'border-secondary-light bg-secondary-soft/60 text-secondary-medium dark:border-line dark:bg-surface-2 dark:text-ink-muted',
        };
    }

    /**
     * Solid marker: the dot or stripe beside a group, column or calendar entry.
     */
    public function dotClass(): string
    {
        return match ($this) {
            self::Good => 'bg-status-good',
            self::Warn => 'bg-status-warn',
            self::Critical => 'bg-status-critical',
            self::Active => 'bg-status-active',
            self::Neutral => 'bg-secondary-light dark:bg-ink-muted',
        };
    }
}
