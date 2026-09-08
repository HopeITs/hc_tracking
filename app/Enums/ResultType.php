<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The level a result sits at in the results chain.
 *
 * The value is what lands in `results.type` (VARCHAR(50)). Results nest
 * through `parent_id`, and the type records which rung of the chain a
 * statement occupies: an output is what the project delivers, an outcome the
 * change that delivery produces, an impact the long-term change it contributes
 * to.
 *
 * The column is NOT NULL with no database default — a result is always stated
 * at a known level.
 *
 * Values are lower case and casing is part of the value.
 */
enum ResultType: string
{
    case Impact = 'impact';
    case Outcome = 'outcome';
    case Output = 'output';

    /**
     * All result type values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
