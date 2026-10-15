<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a media deliverable stands (contracts.md §12.2), stored in `tasks.delivery_state`: requested → delivered → accepted | returned; returned → delivered again.
 */
enum DeliveryState: string
{
    use HasTranslatedLabel;

    case Requested = 'requested';
    case Delivered = 'delivered';
    case Accepted = 'accepted';
    case Returned = 'returned';

    /**
     * All values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
