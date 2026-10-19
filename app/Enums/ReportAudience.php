<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who reads a donor report (contracts §14.2). A donor report needs the
 * delivered file before it counts as submitted; an internal one does not.
 */
enum ReportAudience: string
{
    use HasTranslatedLabel;

    case Donor = 'donor';
    case Internal = 'internal';

    /**
     * All audience values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
