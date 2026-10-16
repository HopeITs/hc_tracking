<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a project's monthly report template stands (`report_templates.status`,
 * contracts.md §12.4): pre-filled and waiting for MEAL, or confirmed. The
 * first confirmation activates an approved project (ConfirmReportTemplate).
 *
 * Values are lower case and casing is part of the value.
 */
enum ReportTemplateStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Confirmed = 'confirmed';

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
