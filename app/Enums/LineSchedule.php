<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which months a report template line is due in
 * (`report_template_lines.schedule`, contracts.md §12.4): the library
 * frequency, every month, the listed calendar months, or once N months after
 * the line's first month. See ReportTemplateLine::isDueIn().
 *
 * Values are lower case and casing is part of the value.
 */
enum LineSchedule: string
{
    use HasTranslatedLabel;

    case LibraryDefault = 'library_default';
    case Monthly = 'monthly';
    case SelectedMonths = 'selected_months';
    case OnceAfterMonths = 'once_after_months';

    /**
     * All schedule values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
