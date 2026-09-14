<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The states a project moves through from first draft to archive.
 *
 * The value is what lands in `projects.status` (VARCHAR(50)), which is NOT
 * NULL and defaults to 'draft'. Every project existing before this column was
 * added therefore reads as a draft until someone advances it.
 *
 * Unlike `ProposalStatus` this is a strict sequence, so `next()` and
 * `isOpen()` are derivable from the case order alone.
 *
 * Values are lower case and casing is part of the value.
 */
enum ProjectStatus: string
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';

    /**
     * Whether a project in this state is still being delivered.
     *
     * The match has no default arm: a state added without an answer raises
     * UnhandledMatchError rather than silently counting as closed.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Draft, self::Submitted, self::Approved, self::Active => true,
            self::Closed, self::Archived => false,
        };
    }

    /**
     * The state that follows this one, or null at the end of the sequence.
     */
    public function next(): ?self
    {
        $cases = self::cases();
        $position = array_search($this, $cases, true);

        return $cases[$position + 1] ?? null;
    }

    /**
     * All project status values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
