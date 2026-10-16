<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The sex a monthly report figure is broken down by (contracts §13, D-50).
 */
enum Gender: string
{
    use HasTranslatedLabel;

    case Male = 'male';
    case Female = 'female';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
