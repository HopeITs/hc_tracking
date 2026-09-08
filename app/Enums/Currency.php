<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The currencies money may be held or moved in.
 *
 * Casts `donor_proposal.currency` and the three currency columns on
 * `transfers_tracking`, all VARCHAR(10).
 *
 * These values are UPPER case, unlike every other enum in this namespace.
 * They are ISO 4217 codes, which are upper case by the standard, and the
 * columns already stored 'USD'. Casing is still part of the value, so 'usd'
 * throws on read exactly as 'meal' does for UserRole.
 *
 * The list is deliberately short. Adding a currency is a code change, which is
 * the point: a transfer in an unexpected currency should stop and be looked at
 * rather than quietly widening the ledger.
 */
enum Currency: string
{
    case Usd = 'USD';
    case Eur = 'EUR';
    case Syp = 'SYP';

    /**
     * All currency values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
