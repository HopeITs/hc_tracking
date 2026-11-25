<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The starting risk library and the one idempotent rule that seeds it,
 * shared by the data migrations and the seeder (W-09, D-61).
 *
 * Since 2026-11-25 the starting list is empty: the library fills itself from
 * the risk titles MEAL types on a program or a proposal, and suggests them
 * when a risk is typed later. `ensure()` stays so the seeder and the older
 * data migrations remain idempotent; with an empty list it inserts nothing.
 *
 * `category` is a single free-text column (no per-language pair), so any
 * entry that is added is named in one language and MEAL may rename it on
 * /admin/risk-library.
 *
 * An entry counts as present when any row carries its English **or** its
 * Arabic title, so a renamed or deactivated entry never comes back as a
 * duplicate. Query builder only: no model events.
 */
final class DefaultRiskLibrary
{
    /**
     * @return list<array{title_en: string, title_ar: string, category?: ?string}>
     */
    public static function all(): array
    {
        return [];
    }

    /**
     * Insert the entries that are missing, after the existing ones.
     *
     * @param  list<array{title_en: string, title_ar: string, category?: ?string}>|null  $list  defaults to all()
     * @return int how many were inserted
     */
    public static function ensure(?array $list = null): int
    {
        $list ??= self::all();

        if ($list === []) {
            return 0;
        }

        return DB::transaction(function () use ($list): int {
            $existing = DB::table('risk_library')->lockForUpdate()->get(['title_en', 'title_ar']);
            $titles = $existing->pluck('title_en')->merge($existing->pluck('title_ar'))->filter()->all();
            $position = (int) DB::table('risk_library')->max('sort_order');
            $now = now();
            $inserted = 0;

            foreach ($list as $default) {
                if (in_array($default['title_en'], $titles, true) || in_array($default['title_ar'], $titles, true)) {
                    continue;
                }

                DB::table('risk_library')->insert([
                    'title_en' => $default['title_en'],
                    'title_ar' => $default['title_ar'],
                    'category' => $default['category'] ?? null,
                    'is_active' => true,
                    'sort_order' => ++$position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            }

            return $inserted;
        });
    }
}
