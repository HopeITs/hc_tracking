<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a task is for (contracts.md §12.2), stored in `tasks.kind`. A media deliverable follows the delivery trail; a financial task is Finance-only.
 */
enum TaskKind: string
{
    use HasTranslatedLabel;

    case General = 'general';
    case MediaDeliverable = 'media_deliverable';
    case Financial = 'financial';

    /**
     * All values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
