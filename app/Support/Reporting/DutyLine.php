<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use Carbon\CarbonImmutable;

/**
 * One line of "My reporting duties": a report (or, for a place's open months,
 * several monthly reports merged) waiting on the viewer.
 */
final readonly class DutyLine
{
    /**
     * @param  list<string>  $months  the months it covers, 'Y-m', oldest first; empty for a donor report
     * @param  CarbonImmutable  $due  the due date of the oldest report
     * @param  string  $url  the page of the oldest report
     */
    public function __construct(
        public ActiveReportType $type,
        public string $key,
        public string $name,
        public string $context,
        public array $months,
        public CarbonImmutable $due,
        public bool $late,
        public string $url,
    ) {}
}
