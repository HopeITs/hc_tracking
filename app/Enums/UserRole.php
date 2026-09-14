<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The roles a user can hold.
 *
 * The value is what lands in `users.role` (VARCHAR(50)), so casing is part of
 * the value: MEAL is an acronym and stays upper case, the rest are title case.
 * Before this enum existed the column was free text and 'Officer', 'manager'
 * and 'meal' were all in use, which meant 'MEAL' and 'meal' were two roles.
 */
enum UserRole: string
{
    use HasTranslatedLabel;

    case Officer = 'Officer';
    case Manager = 'Manager';
    case Meal = 'MEAL';
    case Admin = 'Admin';

    /**
     * All role values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
