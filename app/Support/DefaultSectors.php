<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The six starting sectors of D-47, and the one idempotent rule that seeds
 * them, shared by the data migration and CoreDataSeeder.
 *
 * contracts.md §12.5 gives `sectors` no key column, so a default counts as
 * present when any sector carries its Arabic **or** its English name. An
 * admin who renames a default in one language, or deactivates it, therefore
 * never gets it back as a duplicate. Query builder only: no model events and
 * no activity.
 */
final class DefaultSectors
{
    /**
     * @return list<array{name_ar: string, name_en: string}>
     */
    public static function all(): array
    {
        return [
            ['name_ar' => 'الصحة', 'name_en' => 'Health'],
            ['name_ar' => 'الأمن الغذائي وسبل العيش', 'name_en' => 'Food security & livelihoods'],
            ['name_ar' => 'المياه والصرف الصحي والنظافة', 'name_en' => 'WASH'],
            ['name_ar' => 'المأوى والمواد غير الغذائية', 'name_en' => 'Shelter & NFIs'],
            ['name_ar' => 'الحماية', 'name_en' => 'Protection'],
            ['name_ar' => 'التعليم', 'name_en' => 'Education'],
        ];
    }

    /**
     * Insert the defaults that are missing, after the existing sectors.
     *
     * @return int how many were inserted
     */
    public static function ensure(): int
    {
        return DB::transaction(function (): int {
            $existing = DB::table('sectors')->lockForUpdate()->get(['name_ar', 'name_en']);
            $names = $existing->pluck('name_ar')->merge($existing->pluck('name_en'))->filter()->all();
            $position = (int) DB::table('sectors')->max('sort_order');
            $now = now();
            $inserted = 0;

            foreach (self::all() as $default) {
                if (in_array($default['name_ar'], $names, true) || in_array($default['name_en'], $names, true)) {
                    continue;
                }

                DB::table('sectors')->insert([
                    ...$default,
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
