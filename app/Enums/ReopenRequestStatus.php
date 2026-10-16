<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The decision on a request to reopen a validated monthly report (contracts
 * §13, D-09a). A request is never deleted.
 */
enum ReopenRequestStatus: string
{
    use HasTranslatedLabel;

    case Pending = 'pending';
    case Granted = 'granted';
    case Denied = 'denied';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
