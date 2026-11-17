<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * A slot holder's review of a change request (contracts.md §7, Y-09,
 * D-89a). `skipped` is written by the system for a slot with no active
 * holder; a person records only `no_objection` or `remarks`.
 */
enum ChangeReviewVerdict: string
{
    use HasTranslatedLabel;

    case NoObjection = 'no_objection';
    case Remarks = 'remarks';
    case Skipped = 'skipped';
}
