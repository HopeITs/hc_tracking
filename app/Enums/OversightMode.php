<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who oversees a catalog event besides its direct recipients:
 * MEAL + General Management, General Management only, or nobody.
 */
enum OversightMode: string
{
    use HasTranslatedLabel;

    case All = 'all';
    case ManagementOnly = 'management_only';
    case None = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
