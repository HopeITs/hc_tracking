<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where an annual activity plan stands. The program manager fills a draft
 * and sends it to MEAL; the MEAL manager approves it or sends it back to a
 * draft with a reason. Only an approved plan is live.
 */
enum ActivityPlanStatus: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';

    /**
     * Whether the program manager may still change the plan.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

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
            self::InReview => StatusTone::Active,
            self::Approved => StatusTone::Good,
        };
    }
}
