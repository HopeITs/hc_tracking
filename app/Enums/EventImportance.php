<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How soon a catalog event reaches oversight: immediate = now; digest =
 * direct recipients now, oversight in the next daily oversight summary.
 */
enum EventImportance: string
{
    use HasTranslatedLabel;

    case Immediate = 'immediate';
    case Digest = 'digest';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
