<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a notification came from: somebody's action, a scheduled alert, or
 * a digest. Stored in `notifications.kind`. Values are lower case.
 */
enum NotificationKind: string
{
    use HasTranslatedLabel;

    case Action = 'action';
    case Alert = 'alert';
    case Digest = 'digest';

    /**
     * All kind values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
