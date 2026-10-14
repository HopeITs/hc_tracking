<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The states a project moves through from first draft to archive
 * (contracts.md §3).
 *
 * The value is what lands in `projects.status` (VARCHAR(50)), which is NOT
 * NULL and defaults to 'draft'. A project is created as a dateless draft; it
 * becomes `approved` and `active` only through the automatic writers (T08,
 * T33), and `submitted` is retired: it still renders in old activity rows.
 *
 * Values are lower case and casing is part of the value.
 */
enum ProjectStatus: string
{
    use HasTranslatedLabel;

    case Draft = 'draft';
    /** @deprecated Retired by T05; kept so old activity rows and history still render. */
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
            self::Draft, self::Approved, self::Active => true,
            self::Submitted, self::Closed, self::Archived => false,
        };
    }

    /**
     * Every status a project can be in today: all of them but the retired
     * `submitted`. Pickers, filters, charts and the lifecycle bar iterate
     * this, never `cases()`.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Draft, self::Approved, self::Active, self::Closed, self::Archived];
    }

    /**
     * The state that follows this one, or null at the end of the sequence.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Draft => self::Approved,
            self::Submitted => self::Approved,
            self::Approved => self::Active,
            self::Active => self::Closed,
            self::Closed => self::Archived,
            self::Archived => null,
        };
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
