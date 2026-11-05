<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a `proposal_status_notes` row records, so the Status timeline can tell
 * a move apart from a hold, a correction or a note the donor prompted.
 *
 * Added by W-06 with the definition W-07's contract gives it, since W-06
 * needs OnHold and OffHold first: W-07 writes Negotiation and W-08 writes
 * Extension onto the same column rather than adding one of their own.
 *
 * Transition is the default, so every row written before this column existed
 * reads as a plain move. Values are lower case and stored in
 * `proposal_status_notes.kind` (VARCHAR(20)).
 */
enum StatusNoteKind: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Transition = 'transition';
    case Correction = 'correction';
    case Negotiation = 'negotiation';
    case OnHold = 'on_hold';
    case OffHold = 'off_hold';
    case Extension = 'extension';

    /**
     * All kind values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The shared visual tone for this case (W-28); exhaustive, no default arm.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Transition, self::OffHold => StatusTone::Neutral,
            self::Negotiation, self::Extension => StatusTone::Active,
            self::Correction, self::OnHold => StatusTone::Warn,
        };
    }
}
