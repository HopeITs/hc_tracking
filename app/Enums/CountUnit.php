<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What an activity counts in the annual plan, agreed with MEAL (2026-11-11).
 * Only sessions also carry their length in hours. "Other" takes a free-text
 * name stored on the plan item.
 */
enum CountUnit: string
{
    use HasTranslatedLabel;

    case Session = 'session';
    case Test = 'test';
    case Kit = 'kit';
    case Item = 'item';
    case Case = 'case';
    case Course = 'course';
    case WaterPoint = 'water_point';
    case Event = 'event';
    case FieldVisit = 'field_visit';
    case Survey = 'survey';
    case Other = 'other';

    /**
     * Whether a batch of this unit also needs the hours of each session.
     */
    public function needsHours(): bool
    {
        return $this === self::Session;
    }

    /**
     * "3 sessions", "1 kit": the counted noun in the current language.
     */
    public function counted(int $count, ?string $otherName = null): string
    {
        if ($this === self::Other) {
            return $count.' '.($otherName !== null && $otherName !== '' ? $otherName : __('annual_plans.unit_other_fallback'));
        }

        return trans_choice('annual_plans.unit_noun.'.$this->value, $count, ['count' => $count]);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
