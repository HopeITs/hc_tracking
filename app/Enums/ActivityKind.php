<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a framework activity runs over time (contracts §11.2, D-51).
 *
 * A continuous activity spans a start-to-end run of months; an event happens
 * in one or more separate months. Stored in `results.activity_kind` (slice 3)
 * for activities only, defaulting to continuous.
 */
enum ActivityKind: string
{
    use HasTranslatedLabel;

    case Continuous = 'continuous';
    case Event = 'event';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
