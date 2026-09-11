<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of funder a donor is.
 *
 * The value is what lands in `donors.type` (VARCHAR(50)). The column is
 * NULLABLE: a donor can be recorded before its classification is known, and
 * the five donors seeded before this column existed carry no type.
 *
 * Values are lower case and casing is part of the value.
 */
enum DonorType: string
{
    case Bilateral = 'bilateral';
    case Foundation = 'foundation';
    case Individual = 'individual';
    case Corporate = 'corporate';

    /**
     * All donor type values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
