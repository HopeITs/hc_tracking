<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Whether an assumption of a proposal's logical framework still holds.
 * Stored in `assumptions.status`.
 */
enum AssumptionStatus: string
{
    use HasTranslatedLabel;

    case Holds = 'holds';
    case AtRisk = 'at_risk';
    case Broken = 'broken';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
