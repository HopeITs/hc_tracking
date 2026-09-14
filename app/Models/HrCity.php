<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A city from the HR database (hchr_cities).
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ar
 * @property-read string $name
 * @property-read string $secondary_name
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
     * The city's name in the interface language, falling back to English.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => Locale::current() === Locale::Arabic
            ? (trim($this->name_ar) !== '' ? trim($this->name_ar) : trim($this->name_en))
            : trim($this->name_en));
    }

    /**
     * The city's name in the other language, shown beside the main name.
     *
     * @return Attribute<string, never>
     */
    protected function secondaryName(): Attribute
    {
        return Attribute::get(fn (): string => trim(Locale::current() === Locale::Arabic ? $this->name_en : $this->name_ar));
    }

    /**
     * The name column to sort by in the interface language.
     */
    public static function nameColumn(): string
    {
        return Locale::current() === Locale::Arabic ? 'name_ar' : 'name_en';
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
