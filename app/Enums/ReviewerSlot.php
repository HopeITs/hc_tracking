<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Why someone reviews a proposal stage (contracts.md §9). Declaration order
 * is priority: a person who fits two slots keeps the higher one. The first
 * four are the default slots, each an HR position set in
 * notification_settings `proposal_default_reviewers`.
 */
enum ReviewerSlot: string
{
    use HasTranslatedLabel;

    case MealManager = 'meal_manager';
    case FinanceManager = 'finance_manager';
    case FundraisingManager = 'fundraising_manager';
    case Ceo = 'ceo';
    case ProjectOwner = 'project_owner';
    case ProgramManager = 'program_manager';
    case Additional = 'additional';

    /**
     * The slots resolved from an HR position through Slots::holderOf().
     *
     * @return list<self>
     */
    public static function defaultSlots(): array
    {
        return [self::MealManager, self::FinanceManager, self::FundraisingManager, self::Ceo];
    }

    public function isDefault(): bool
    {
        return in_array($this, self::defaultSlots(), true);
    }

    /**
     * Lower is higher priority.
     */
    public function priority(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
