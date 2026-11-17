<?php

declare(strict_types=1);

namespace App\Support\Reporting;

/**
 * A place (D-75, contracts.md §12.4): city + program + center. A program that
 * works in the field with no center is the city-wide place of that city and
 * program (D-88). A place with neither a center nor the city-wide flag is
 * "center not set" (D-82): missing, and equal to nothing.
 */
final readonly class ReportPlace
{
    public function __construct(
        public int $cityId,
        public int $programId,
        public ?int $centerId,
        public bool $cityWide,
    ) {}

    /**
     * No center and not city-wide.
     */
    public function isUnset(): bool
    {
        return $this->centerId === null && ! $this->cityWide;
    }

    /**
     * Same city and program, and either the same non-null center or both
     * city-wide. An unset place equals nothing.
     */
    public function equals(self $other): bool
    {
        if ($this->isUnset() || $other->isUnset()) {
            return false;
        }

        if ($this->cityId !== $other->cityId || $this->programId !== $other->programId) {
            return false;
        }

        if ($this->cityWide || $other->cityWide) {
            return $this->cityWide && $other->cityWide;
        }

        return $this->centerId === $other->centerId;
    }

    public function key(): string
    {
        $where = match (true) {
            $this->cityWide => 'city-wide',
            $this->centerId !== null => 'c'.$this->centerId,
            default => 'unset',
        };

        return $this->cityId.':'.$this->programId.':'.$where;
    }
}
