<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How urgently a task needs to be picked up.
 *
 * The value is what lands in `tasks.priority` (VARCHAR(50)). Values are lower
 * case and casing is part of the value.
 */
enum TaskPriority: string
{
    use HasTranslatedLabel;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * All task priority values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
