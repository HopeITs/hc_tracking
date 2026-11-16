<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a program's strategic plan stands. The MEAL manager writes a draft
 * and sends it to the program manager, who approves it or sends it back to a
 * draft with a reason. The MEAL manager may reopen an approved plan for a
 * revision, which needs approving again.
 */
enum StrategicPlanStatus: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Neutral,
            self::Submitted => StatusTone::Active,
            self::Approved => StatusTone::Good,
        };
    }
}
