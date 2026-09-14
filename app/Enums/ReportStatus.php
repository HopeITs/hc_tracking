<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The review states a narrative report moves through.
 *
 * The value is what lands in `reports.status` (VARCHAR(50)), which is NOT NULL
 * and defaults to 'draft'.
 *
 * This column REPLACED the `is_approved`/`approved_date` and
 * `is_editable`/`edited_date` pairs. Those four columns were dropped because a
 * boolean and its paired date can disagree, and because `is_approved` and a
 * status of 'approved' would have been two sources of one truth.
 * `is_seen_by_meal` survives: MEAL visibility is a separate review track, not a
 * stage of this one.
 *
 * A report is editable while it is a draft; see Report::isEditable().
 *
 * Values are lower case and casing is part of the value.
 */
enum ReportStatus: string
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Approved = 'approved';

    /**
     * Whether a report in this state may still be edited.
     *
     * The match has no default arm, so a state added without an answer raises
     * UnhandledMatchError rather than silently becoming editable.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft => true,
            self::Reviewed, self::Approved => false,
        };
    }

    /**
     * All report status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
