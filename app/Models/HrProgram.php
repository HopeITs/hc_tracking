<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * A program from the HR database (hchr_programs).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property string $code
 * @property string $slug
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrProgram extends HrModel
{
    protected $table = 'programs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
