<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Closure;

/**
 * Keeps a whole-table HR lookup (e.g. every program name by id) in memory, so
 * tracking can show HR data it does not copy without one HR query per row.
 *
 * The lookup belongs to the running application instance and is reloaded
 * after a minute, so a long-lived worker does not keep serving old HR data.
 * An id missing from it triggers one reload, which picks up a row HR added
 * since; an id still missing after that is remembered as absent.
 */
trait RemembersHrLookup
{
    /**
     * @var array{app: int|null, loaded_at: float, entries: array<int, mixed>, absent: array<int, true>}
     */
    private static array $hrLookup = ['app' => null, 'loaded_at' => 0.0, 'entries' => [], 'absent' => []];

    /**
     * Every entry of the lookup, keyed by HR id.
     *
     * @param  Closure(): array<int, mixed>  $load
     * @return array<int, mixed>
     */
    protected static function rememberedHrLookup(Closure $load): array
    {
        $app = spl_object_id(app());

        if (self::$hrLookup['app'] !== $app || microtime(true) - self::$hrLookup['loaded_at'] > 60) {
            self::$hrLookup = ['app' => $app, 'loaded_at' => microtime(true), 'entries' => $load(), 'absent' => []];
        }

        return self::$hrLookup['entries'];
    }

    /**
     * One entry of the lookup, or null when HR has no such row.
     *
     * @param  Closure(): array<int, mixed>  $load
     */
    protected static function rememberedHrLookupEntry(int $id, Closure $load): mixed
    {
        $entries = self::rememberedHrLookup($load);

        if (! array_key_exists($id, $entries) && ! isset(self::$hrLookup['absent'][$id])) {
            self::$hrLookup['entries'] = $entries = $load();
            self::$hrLookup['loaded_at'] = microtime(true);

            if (! array_key_exists($id, $entries)) {
                self::$hrLookup['absent'][$id] = true;
            }
        }

        return $entries[$id] ?? null;
    }
}
