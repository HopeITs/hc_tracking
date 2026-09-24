<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How loudly a notification arrives: info (inbox only), important (toast),
 * critical (red sticky toast, pinned until read).
 *
 * Stored in `notifications.priority`. Values are lower case.
 */
enum NotificationPriority: string
{
    use HasTranslatedLabel;

    case Info = 'info';
    case Important = 'important';
    case Critical = 'critical';

    /**
     * The position of this priority, so two can be compared.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Important => 1,
            self::Critical => 2,
        };
    }

    /**
     * The loudest of the given priorities, Info when none are given.
     */
    public static function highest(self ...$priorities): self
    {
        $highest = self::Info;

        foreach ($priorities as $priority) {
            if ($priority->rank() > $highest->rank()) {
                $highest = $priority;
            }
        }

        return $highest;
    }

    /**
     * All priority values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
