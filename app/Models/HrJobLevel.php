<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Models\Concerns\RemembersHrLookup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;

/**
 * A job level from the HR database (hchr_job_levels), e.g. Senior, grade 4.
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ar
 * @property-read string $name
 * @property int|null $grade
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrJobLevel extends HrModel
{
    use RemembersHrLookup;

    /**
     * @var list<string>
     */
    private const LookupColumns = ['id', 'name_en', 'name_ar', 'grade'];

    protected $table = 'job_levels';

    /**
     * Every job level, ordered by grade (then id), kept in memory (see RemembersHrLookup).
     *
     * @return Collection<int, self>
     */
    public static function options(): Collection
    {
        return static::hydrate(array_values(self::rememberedHrLookup(fn (): array => self::loadLookup())))
            ->sortBy([fn (self $a, self $b): int => [$a->grade ?? PHP_INT_MAX, $a->id] <=> [$b->grade ?? PHP_INT_MAX, $b->id]])
            ->values();
    }

    /**
     * The level's name in the interface language, falling back to English.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => Locale::current() === Locale::Arabic && trim($this->name_ar) !== ''
            ? trim($this->name_ar)
            : trim($this->name_en));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadLookup(): array
    {
        return static::query()
            ->toBase()
            ->get(self::LookupColumns)
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (array) $row])
            ->all();
    }
}
