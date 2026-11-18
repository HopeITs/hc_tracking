<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;

/**
 * The combined filter of the report extraction (Y-05, D-79, contracts §14.1):
 * proposal · city · program · center · from / to, plus donor, library
 * indicator, pillar and "my places". `from` and `to` are months (inclusive),
 * kept as their first day; an open end means "from the first month" / "to
 * this month". The Field work (`cityWide`, D-88) and Center not set
 * (`centerUnset`, D-82) places each exclude `centerId`.
 */
final readonly class ExtractionFilter
{
    public ?int $centerId;

    public ?CarbonImmutable $from;

    public ?CarbonImmutable $to;

    public function __construct(
        public ?int $proposalId = null,
        public ?int $cityId = null,
        public ?int $programId = null,
        ?int $centerId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        public ?int $donorId = null,
        public ?int $indicatorId = null,
        public ?int $pillarId = null,
        public bool $mine = false,
        public bool $cityWide = false,
        public bool $centerUnset = false,
    ) {
        $this->centerId = $cityWide || $centerUnset ? null : $centerId;
        $this->from = $from?->startOfMonth()->startOfDay();
        $this->to = $to?->startOfMonth()->startOfDay();
    }

    /**
     * Whether a city, program, center, Field work or Center not set filter is
     * on (the place filters read the report template).
     */
    public function hasPlaceFilter(): bool
    {
        return $this->cityId !== null || $this->programId !== null || $this->centerId !== null || $this->cityWide || $this->centerUnset;
    }

    /**
     * A donor or proposal filter selects the donor view; otherwise the
     * center view (contracts §14.1).
     */
    public function isDonorView(): bool
    {
        return $this->donorId !== null || $this->proposalId !== null;
    }
}
