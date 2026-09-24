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
     * Every HR program slug by id.
     *
     * Kept in its own cache slot rather than RemembersHrLookup's, which
     * `namesById()` already occupies for this class — sharing one slot
     * between two different lookups would make whichever loads second
     * silently reuse the other's entries for up to a minute.
     *
     * @var array<int, string>|null
     */
    private static ?array $slugsById = null;

    private static float $slugsLoadedAt = 0.0;

    private static ?int $slugsApp = null;

    /**
     * Every HR program slug by id.
     *
     * Reloads on the same app-instance check RemembersHrLookup uses: the test
     * suite boots a fresh application per test, so without it this cache
     * would keep serving programs seeded by an earlier test and miss ones
     * created since.
     *
     * @return array<int, string>
     */
    public static function slugsById(): array
    {
        $app = spl_object_id(app());

        if (self::$slugsById === null || self::$slugsApp !== $app || microtime(true) - self::$slugsLoadedAt > 60) {
            self::$slugsById = self::loadSlugsById();
            self::$slugsLoadedAt = microtime(true);
            self::$slugsApp = $app;
        }

        return self::$slugsById;
    }

    /**
     * The slug of one HR program, empty when HR no longer has it.
     *
     * Forces one reload when the id is missing from the cached list, the same
     * way `rememberedHrLookupEntry()` does for `nameOf()` — otherwise a
     * program created after the cache was last filled (e.g. a second program
     * factory'd later in the same test) would read as slugless until the
     * cache's own 60-second window expires.
     */
    public static function slugOf(int $id): string
    {
        $slugs = self::slugsById();

        if (! array_key_exists($id, $slugs)) {
            self::$slugsById = $slugs = self::loadSlugsById();
            self::$slugsLoadedAt = microtime(true);
        }

        return $slugs[$id] ?? '';
    }

    /**
     * The id of the HR program with a given slug, or null when none matches.
     */
    public static function idBySlug(string $slug): ?int
    {
        $slugs = self::slugsById();
        $id = array_search($slug, $slugs, true);

        if ($id === false) {
            self::$slugsById = $slugs = self::loadSlugsById();
            self::$slugsLoadedAt = microtime(true);
            $id = array_search($slug, $slugs, true);
        }

        return $id === false ? null : $id;
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
     * @return array<int, string>
     */
    private static function loadSlugsById(): array
    {
        return static::query()
            ->pluck('slug', 'id')
            ->map(fn (?string $slug): string => trim((string) $slug))
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
