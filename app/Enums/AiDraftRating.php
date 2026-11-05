<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the officer said about one AI draft, stored in `ai_drafts.rating`.
 */
enum AiDraftRating: string
{
    case Helpful = 'helpful';
    case NotHelpful = 'not_helpful';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
