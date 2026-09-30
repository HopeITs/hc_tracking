<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Like;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes expired rows from the database cache store.
 *
 * The database store only removes an expired row when that same key is read
 * again. Rate limiter keys are named after whatever the caller sent (an
 * email, an IP), so keys a guessing run created are never read again and
 * would stay in the table for good. Sweeping them keeps the cache table, and
 * every rate limit check that queries it, small.
 */
class PruneExpiredCache extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cache:prune-expired {--dry-run : Count what would be deleted without deleting anything}';

    /**
     * @var string
     */
    protected $description = 'Delete expired rows from the database cache store';

    public function handle(): int
    {
        $store = config('cache.stores.'.config('cache.default'));

        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            $this->components->info('The default cache store is not the database: nothing to prune.');

            return self::SUCCESS;
        }

        $now = now()->getTimestamp();
        $ownKeys = Like::escape((string) config('cache.prefix')).'%';

        $entries = DB::connection($store['connection'] ?? null)
            ->table($store['table'])
            ->where('key', 'like', $ownKeys)
            ->where('expiration', '<=', $now);

        $locks = DB::connection($store['lock_connection'] ?? $store['connection'] ?? null)
            ->table($store['lock_table'] ?? 'cache_locks')
            ->where('key', 'like', $ownKeys)
            ->where('expiration', '<=', $now);

        if ($this->option('dry-run')) {
            $this->components->info($entries->count().' expired cache row(s) and '.$locks->count().' expired lock(s) would be deleted.');

            return self::SUCCESS;
        }

        $this->components->info('Deleted '.$entries->delete().' expired cache row(s) and '.$locks->delete().' expired lock(s).');

        return self::SUCCESS;
    }
}
