<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DonorProposal;

/**
 * Where an agreement stands as a person reads it: one word per agreement.
 *
 * The three states are disjoint and cover every agreement, so the headline
 * counts always add up to the register's own count. The PHP here and the SQL
 * in ReadsAgreementRegister::applyHealth() test the same three facts — a late
 * tranche, a failed tranche, an overdue donor report — so a row's badge and
 * the filter that selects it can never disagree.
 */
final class AgreementHealth
{
    /** Something is late, failed or overdue: this agreement wants a person today. */
    public const ATTENTION = 'attention';

    /** Money is scheduled and nothing is late: it is running on its own. */
    public const MOVING = 'moving';

    /** Signed, but no tranche has been scheduled under it yet. */
    public const NOT_STARTED = 'not_started';

    /** @var list<string> */
    public const CASES = [self::ATTENTION, self::MOVING, self::NOT_STARTED];

    /**
     * Which state a register row is in.
     *
     * Reads the counts registerQuery() selects, never a loaded relation, so
     * it costs no query per row.
     */
    public static function of(DonorProposal $agreement): string
    {
        if (self::needsAttention($agreement)) {
            return self::ATTENTION;
        }

        return (int) $agreement->getAttribute('money_tranches') > 0 ? self::MOVING : self::NOT_STARTED;
    }

    /**
     * A tranche past its date and unsent, a tranche that failed, or a donor
     * report past its due date and unsubmitted.
     */
    public static function needsAttention(DonorProposal $agreement): bool
    {
        return (int) $agreement->getAttribute('late_transfers_count') > 0
            || (int) $agreement->getAttribute('failed_transfers_count') > 0
            || (int) $agreement->getAttribute('overdue_reports_count') > 0;
    }

    /**
     * The translation key for a state's word.
     */
    public static function label(string $health): string
    {
        return __('funding_agreements.health_'.$health);
    }

    /**
     * How a state is coloured: critical, neutral ink, or muted.
     */
    public static function tone(string $health): string
    {
        return match ($health) {
            self::ATTENTION => 'critical',
            self::MOVING => 'good',
            default => 'muted',
        };
    }
}
