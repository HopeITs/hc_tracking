<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a task is for (contracts.md §12.2), stored in `tasks.kind`. A media deliverable follows the delivery trail; a financial task is Finance-only; a report task (W-13, D-64) follows a donor report and is never picked by hand.
 */
enum TaskKind: string
{
    use HasTranslatedLabel;

    case General = 'general';
    case MediaDeliverable = 'media_deliverable';
    case Financial = 'financial';
    case Report = 'report';

    /**
     * All values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The kinds a person may pick on a department task. A report task is created
     * only by SyncReportTask.
     *
     * @return list<self>
     */
    public static function department(): array
    {
        return array_filter(self::cases(), fn (self $kind): bool => $kind !== self::Report);
    }

    /**
     * @return list<string>
     */
    public static function departmentValues(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::department());
    }
}
