<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How an indicator is tracking against its target, given how much of the
 * reporting window has elapsed.
 *
 * This enum has NO column of its own, exactly as `ProposalPhase` has none. It
 * is derived on read from two percentages, so a stored value can never drift
 * from the figures it claims to summarise.
 *
 * The judgement is relative, not absolute: 40% achieved is Green four months
 * into a year and Red eleven months in. Comparing achievement to elapsed time
 * is the whole point — a bare "% achieved" traffic light would call every
 * indicator Red in January.
 *
 * Values are lower case and casing is part of the value.
 */
enum RagStatus: string
{
    use HasTranslatedLabel;

    case Red = 'red';
    case Amber = 'amber';
    case Green = 'green';

    /**
     * The share of elapsed progress below which an indicator is Amber rather
     * than Green, and below three quarters of which it is Red.
     */
    private const AMBER_THRESHOLD = 0.9;

    private const RED_THRESHOLD = 0.7;

    /**
     * Rate an indicator from its achievement and the time elapsed.
     *
     * Both arguments are percentages, not fractions. A window that has not
     * started (`percentElapsed` of zero or less) cannot be behind schedule, so
     * it rates Green.
     */
    public static function rate(float $percentAchieved, float $percentElapsed): self
    {
        if ($percentElapsed <= 0.0) {
            return self::Green;
        }

        $ratio = $percentAchieved / $percentElapsed;

        return match (true) {
            $ratio >= self::AMBER_THRESHOLD => self::Green,
            $ratio >= self::RED_THRESHOLD => self::Amber,
            default => self::Red,
        };
    }

    /**
     * All RAG values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
