<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a task sits on its board.
 *
 * The value is what lands in `tasks.status` (VARCHAR(50)). Values are lower
 * case and casing is part of the value: the column default was 'TODO' before
 * this enum existed, which made it the one column in the schema cased
 * differently from its neighbours.
 */
enum TaskStatus: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Todo = 'todo';
    case InProgress = 'in progress';
    case UnderReview = 'under review';
    case Done = 'done';

    /**
     * All task status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The shared visual tone for this case (W-28); exhaustive, no default arm.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Todo => StatusTone::Neutral,
            self::InProgress => StatusTone::Active,
            self::UnderReview => StatusTone::Warn,
            self::Done => StatusTone::Good,
        };
    }
}
