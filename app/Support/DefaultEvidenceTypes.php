<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The five starting evidence types (D-42), and the one idempotent rule that
 * seeds them, shared by the data migration and EvidenceTypeSeeder.
 *
 * contracts.md §12.4 gives `evidence_types` no key column, so a default
 * counts as present when any type carries its Arabic **or** its English name.
 * An admin who renames a default in one language, or deactivates it,
 * therefore never gets it back as a duplicate. Query builder only: no model
 * events and no activity.
 */
final class DefaultEvidenceTypes
{
    /**
     * @return list<array{name_ar: string, name_en: string}>
     */
    public static function all(): array
    {
        return [
            ['name_ar' => 'كشوف الحضور', 'name_en' => 'Attendance sheets'],
            ['name_ar' => 'موافقات التصوير', 'name_en' => 'Photo consents'],
            ['name_ar' => 'قوائم المستفيدين', 'name_en' => 'Beneficiary lists'],
            ['name_ar' => 'صور النشاط', 'name_en' => 'Activity photos'],
            ['name_ar' => 'اختبارات قبلية وبعدية', 'name_en' => 'Pre/post test sheets'],
        ];
    }

    /**
     * Insert the defaults that are missing, after the existing types.
     *
     * @return int how many were inserted
     */
    public static function ensure(): int
    {
        return DB::transaction(function (): int {
            $existing = DB::table('evidence_types')->lockForUpdate()->get(['name_ar', 'name_en']);
            $names = $existing->pluck('name_ar')->merge($existing->pluck('name_en'))->filter()->all();
            $position = (int) DB::table('evidence_types')->max('sort_order');
            $now = now();
            $inserted = 0;

            foreach (self::all() as $default) {
                if (in_array($default['name_ar'], $names, true) || in_array($default['name_en'], $names, true)) {
                    continue;
                }

                DB::table('evidence_types')->insert([
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
