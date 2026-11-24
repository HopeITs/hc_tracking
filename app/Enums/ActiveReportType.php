<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Which kind of report a row of the Active Reports table is (M-02).
 *
 * Stored nowhere: it names the source a row was read from, so the table can
 * say what each report is and filter by it.
 */
enum ActiveReportType: string
{
    use HasTranslatedLabel;

    case Monthly = 'monthly';
    case Donor = 'donor';
    case Report = 'report';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
