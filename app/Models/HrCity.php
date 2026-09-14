<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city from the HR database (hchr_cities).
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ar
 * @property string $slug
 * @property string $code
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrCity extends HrModel
{
    protected $table = 'cities';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The employees living in this city.
     *
     * @return HasMany<HrEmployee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(HrEmployee::class, 'city_id');
    }
}
