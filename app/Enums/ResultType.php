<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The level a result sits at in the results chain.
 *
 * The value is what lands in `results.type` (VARCHAR(50)). Results nest
 * through `parent_id`, and the type records which rung of the chain a
 * statement occupies: an output is what the project delivers, an outcome the
 * change that delivery produces, an impact the long-term change it contributes
 * to. An activity is a step that delivers an output; a cross-cutting result
 * stands beside the chain as a root of its own.
 *
 * The column is NOT NULL with no database default — a result is always stated
 * at a known level.
 *
 * Values are lower case and casing is part of the value.
 */
enum ResultType: string
{
    use HasTranslatedLabel;

    case Impact = 'impact';
    case Outcome = 'outcome';
    case Output = 'output';
    case Activity = 'activity';
    case CrossCutting = 'cross-cutting';

    /**
     * The level this one sits directly under, or null for a root-only level.
     *
     * The one definition of the chain (contracts §11.2): activity → output →
     * outcome → impact. Impact and cross-cutting have no parent; nothing sits
     * under an activity or a cross-cutting result.
     */
    public function parentLevel(): ?self
    {
        return match ($this) {
            self::Activity => self::Output,
            self::Output => self::Outcome,
            self::Outcome => self::Impact,
            self::Impact, self::CrossCutting => null,
        };
    }

    /**
     * Display order, top of the chain first; cross-cutting results come last,
     * beside the chain.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Impact => 0,
            self::Outcome => 1,
            self::Output => 2,
            self::Activity => 3,
            self::CrossCutting => 4,
        };
    }

    /**
     * Whether a result of this level may have results nested under it.
     */
    public function acceptsChildren(): bool
    {
        return match ($this) {
            self::Impact, self::Outcome, self::Output => true,
            self::Activity, self::CrossCutting => false,
        };
    }

    /**
     * All result type values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
