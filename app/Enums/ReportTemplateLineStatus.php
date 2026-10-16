<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where one report template line stands (`report_template_lines.status`,
 * contracts.md §12.4). Only `confirmed` lines are ever due; a `retired` line
 * keeps its history and is never deleted.
 *
 * Values are lower case and casing is part of the value.
 */
enum ReportTemplateLineStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Retired = 'retired';

    /**
     * All status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
