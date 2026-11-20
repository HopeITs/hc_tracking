<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

enum TemplatePlacementStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Joined = 'joined';
    case Created = 'created';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
