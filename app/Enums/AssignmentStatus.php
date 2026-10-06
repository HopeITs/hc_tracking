<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where an assignment stands with the person it was made to.
 *
 * Pending: asked, no decision yet (seen_at says whether they opened it).
 * Acknowledged / Rejected: the assignee's decision. Superseded: the field
 * was reassigned before a decision, or after a rejection was resolved. Rows
 * are never deleted, so the set is the audit trail of who was asked.
 *
 * Values are lower case and stored in `assignments.status` (VARCHAR(20)).
 */
enum AssignmentStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /**
     * All status values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
