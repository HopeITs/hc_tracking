<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The accounts-receivable state of a scheduled payment tranche.
 *
 * The value is what lands in `transfers_tracking.ar_status` (VARCHAR(50)). A
 * tranche is pending until the money lands; `failed` records a transfer that
 * was attempted and did not arrive, which is not the same as one still due.
 */
enum ArStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case Failed = 'failed';

    /**
     * All AR status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
