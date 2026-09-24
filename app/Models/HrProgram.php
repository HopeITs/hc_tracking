<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RemembersHrLookup;
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
    use RemembersHrLookup;

    protected $table = 'programs';

    /**
     * Every HR program name by id, kept in memory (see RemembersHrLookup).
     *
     * Tracking keeps no copy of the name; Program::name reads it from here, so
     * a page listing many programs costs one HR query, not one per program.
     *
     * @return array<int, string>
     */
    public static function namesById(): array
    {
        return self::rememberedHrLookup(fn (): array => self::loadNamesById());
    }

    /**
     * The name of one HR program, empty when HR no longer has it.
     */
    public static function nameOf(int $id): string
    {
        return self::rememberedHrLookupEntry($id, fn (): array => self::loadNamesById()) ?? '';
    }

    /**
     * The ids of the HR programs whose name contains the search.
     *
     * @return list<int>
     */
    public static function idsMatchingName(string $search): array
    {
        $search = mb_strtolower(trim($search));

        return array_keys(array_filter(
            self::namesById(),
            fn (string $name): bool => $search === '' || str_contains(mb_strtolower($name), $search),
        ));
    }

    /**
     * Every HR program id, ordered by name (then id).
     *
     * @return list<int>
     */
    public static function idsOrderedByName(): array
    {
        $names = self::namesById();

        uksort($names, fn (int $a, int $b): int => [mb_strtolower($names[$a]), $a] <=> [mb_strtolower($names[$b]), $b]);

        return array_keys($names);
    }

    /**
     * @return array<int, string>
     */
    private static function loadNamesById(): array
    {
        return static::query()
            ->pluck('name', 'id')
            ->map(fn (?string $name): string => trim((string) $name))
            ->all();
    }

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
