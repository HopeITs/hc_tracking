<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Models\Concerns\RemembersHrLookup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A center from the HR database (hchr_centers): a building in a city that
 * hosts one or more projects (D-30).
 *
 * Tracking keeps only `projects.center_id`; names and the city are read from
 * here through an in-memory lookup (see RemembersHrLookup), so a page listing
 * many projects costs one HR query.
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ar
 * @property-read string $name
 * @property-read string $secondary_name
 * @property int $city_id
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrCenter extends HrModel
{
    use RemembersHrLookup;

    /**
     * The columns the lookup reads; opening hours and addresses are not needed.
     *
     * @var list<string>
     */
    private const LookupColumns = ['id', 'name_en', 'name_ar', 'city_id', 'is_active'];

    protected $table = 'centers';

    /**
     * Active centers for a picker, ordered by their name in the interface language.
     *
     * @return Collection<int, self>
     */
    public static function options(): Collection
    {
        $centers = static::hydrate(array_values(self::rememberedHrLookup(fn (): array => self::loadLookup())))
            ->filter(fn (self $center): bool => $center->is_active);

        return $centers
            ->sortBy([fn (self $a, self $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]])
            ->values();
    }

    /**
     * One center from the lookup, active or not, or null when HR has no such center.
     */
    public static function remembered(int $id): ?self
    {
        /** @var array<string, mixed>|null $row */
        $row = self::rememberedHrLookupEntry($id, fn (): array => self::loadLookup());

        return $row === null ? null : static::hydrate([$row])->first();
    }

    /**
     * The city this center stands in.
     *
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * The center's name in the interface language, falling back to English.
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
     * The center's name in the other language, shown beside the main name.
     *
     * @return Attribute<string, never>
     */
    protected function secondaryName(): Attribute
    {
        return Attribute::get(fn (): string => trim(Locale::current() === Locale::Arabic ? $this->name_en : $this->name_ar));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'city_id' => 'integer',
            'is_active' => 'boolean',
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
