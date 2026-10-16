<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The status of a logframe activity in a monthly report (contracts §13,
 * D-51). Unfinished statuses roll over to the next month; final ones stop it.
 */
enum ActivityStatus: string
{
    use HasTranslatedLabel;

    case NotStarted = 'not_started';
    case Started = 'started';
    case Ongoing = 'ongoing';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Conducted = 'conducted';

    /**
     * The statuses an activity of this kind may take: an event is not
     * started, conducted or cancelled; a continuous activity is never
     * conducted.
     *
     * @return list<self>
     */
    public static function allowedFor(ActivityKind $kind): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => match ($status) {
            self::NotStarted, self::Cancelled => true,
            self::Started, self::Ongoing, self::Suspended, self::Completed => $kind === ActivityKind::Continuous,
            self::Conducted => $kind === ActivityKind::Event,
        }));
    }

    /**
     * Whether the activity stops appearing in later months.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Completed, self::Conducted => true,
            self::NotStarted, self::Started, self::Ongoing, self::Suspended => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
