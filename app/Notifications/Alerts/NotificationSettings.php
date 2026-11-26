<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin-edited values from notification_settings, falling back to
 * config('notifications.defaults'). Read through the cache; saving forgets it.
 */
final class NotificationSettings
{
    private const CacheKey = 'notifications.settings';

    /**
     * The stored values, read once per request: the class is bound scoped, and
     * due-date maths asks for a setting per working day, so going to the cache
     * store each time cost hundreds of queries on the Report Tracker.
     *
     * @var array<string, mixed>|null
     */
    private ?array $loaded = null;

    public function get(string $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : config("notifications.defaults.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace(config('notifications.defaults'), $this->stored());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $now = now();

        DB::table('notification_settings')->upsert(
            array_map(fn (string $key): array => ['key' => $key, 'value' => json_encode($values[$key]), 'created_at' => $now, 'updated_at' => $now], array_keys($values)),
            ['key'],
            ['value', 'updated_at'],
        );

        Cache::forget(self::CacheKey);
        $this->loaded = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return $this->loaded ??= Cache::rememberForever(self::CacheKey, fn (): array => DB::table('notification_settings')
            ->pluck('value', 'key')
            ->map(fn (string $value): mixed => json_decode($value, true))
            ->all());
    }
}
