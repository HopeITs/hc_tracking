<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use App\Enums\Concerns\HasStatusTone;
use Carbon\CarbonImmutable;

/**
 * One open report as the Active Reports table shows it, whatever its source
 * (M-02): the common shape of a monthly report, a donor report and an
 * existing narrative report.
 */
final readonly class ActiveReportRow
{
    /**
     * @param  HasStatusTone&\BackedEnum  $status  the source's own status or state, for the badge and its label
     * @param  string|null  $periodKey  the month the report covers, 'Y-m'
     * @param  string  $search  lower-cased text the search box matches
     */
    public function __construct(
        public ActiveReportType $type,
        public int $id,
        public string $name,
        public string $context,
        public ?string $periodKey,
        public HasStatusTone&\BackedEnum $status,
        public ?CarbonImmutable $due,
        public bool $overdue,
        public ?string $owner,
        public string $url,
        public bool $needsMe,
        public ?int $programId,
        public ?int $donorId,
        public string $search,
    ) {}

    /**
     * The row's key in the page: unique across the three sources.
     */
    public function key(): string
    {
        return $this->type->value.'-'.$this->id;
    }
}
