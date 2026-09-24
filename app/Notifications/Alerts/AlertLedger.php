<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Illuminate\Support\Facades\DB;

/**
 * The once-per-stage memory of scheduled alerts. The unique key makes a
 * claim atomic, so overlapping scanner runs can never double-send.
 */
final class AlertLedger
{
    public function claim(string $rule, string $subjectType, int $subjectId, string $stage, int $userId): bool
    {
        $now = now();

        return DB::table('notification_alert_ledger')->insertOrIgnore([
            'rule' => $rule,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'stage' => $stage,
            'user_id' => $userId,
            'fired_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Re-arm a rule for a record (every stage, or one).
     */
    public function release(string $rule, string $subjectType, int $subjectId, ?string $stage = null): int
    {
        return DB::table('notification_alert_ledger')
            ->where('rule', $rule)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->when($stage !== null, fn ($query) => $query->where('stage', $stage))
            ->delete();
    }
}
