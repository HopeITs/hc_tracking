<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * When a MEAL follow-up task fires for an activity in a centre: when the
 * activity starts there, when its first batch passes its middle week, or
 * when the activity is done there.
 */
enum FollowUpTrigger: string
{
    use HasTranslatedLabel;

    case Start = 'start';
    case Middle = 'middle';
    case Finish = 'finish';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
