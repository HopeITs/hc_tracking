<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasStatusTone;
use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a change request on an approved proposal stands (contracts.md §7,
 * Y-09, D-89a). A withdrawn request is `rejected` with the requester's note.
 */
enum ProposalChangeRequestStatus: string implements HasStatusTone
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Waiting reads as a warning, approved as good, rejected as neutral (a
     * withdrawn or refused change is not an alarm).
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Pending => StatusTone::Warn,
            self::Approved => StatusTone::Good,
            self::Rejected => StatusTone::Neutral,
        };
    }
}
