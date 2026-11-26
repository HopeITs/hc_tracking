<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use App\Enums\Concerns\HasStatusTone;
use App\Enums\MonthlyReportStatus;
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
     * @param  int|null  $templateId  the monthly report template (one place), null for donor reports
     * @param  bool  $returned  a monthly draft MEAL sent back to its preparer
     * @param  bool  $needsCheck  it waits on the viewer as its validator
     * @param  string|null  $donorName  the donor a donor report is owed to
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
        public ?int $templateId = null,
        public bool $returned = false,
        public bool $needsCheck = false,
        public ?string $donorName = null,
    ) {}

    /**
     * The row as scalars, for the cache: the cache store unserialises no objects (config cache.serializable_classes).
     *
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'name' => $this->name,
            'context' => $this->context,
            'periodKey' => $this->periodKey,
            'statusClass' => $this->status::class,
            'status' => $this->status->value,
            'due' => $this->due?->format('Y-m-d'),
            'overdue' => $this->overdue,
            'owner' => $this->owner,
            'url' => $this->url,
            'needsMe' => $this->needsMe,
            'programId' => $this->programId,
            'donorId' => $this->donorId,
            'search' => $this->search,
            'templateId' => $this->templateId,
            'returned' => $this->returned,
            'needsCheck' => $this->needsCheck,
            'donorName' => $this->donorName,
        ];
    }

    /**
     * @param  array<string, mixed>  $row  from toCache()
     */
    public static function fromCache(array $row): self
    {
        /** @var class-string<HasStatusTone&\BackedEnum> $statusClass */
        $statusClass = $row['statusClass'];

        return new self(
            type: ActiveReportType::from($row['type']),
            id: $row['id'],
            name: $row['name'],
            context: $row['context'],
            periodKey: $row['periodKey'],
            status: $statusClass::from($row['status']),
            due: $row['due'] === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', $row['due']),
            overdue: $row['overdue'],
            owner: $row['owner'],
            url: $row['url'],
            needsMe: $row['needsMe'],
            programId: $row['programId'],
            donorId: $row['donorId'],
            search: $row['search'],
            templateId: $row['templateId'],
            returned: $row['returned'],
            needsCheck: $row['needsCheck'],
            donorName: $row['donorName'],
        );
    }

    /**
     * The row's key in the page: unique across the three sources.
     */
    public function key(): string
    {
        return $this->type->value.'-'.$this->id;
    }

    /**
     * The one state the tracker draws: late, returned, wait, draft or done for a monthly
     * report; overdue, due or submitted for a donor report.
     */
    public function state(): string
    {
        if ($this->type === ActiveReportType::Donor) {
            return $this->status->value;
        }

        return match (true) {
            $this->status === MonthlyReportStatus::Validated => 'done',
            $this->status === MonthlyReportStatus::Submitted => 'wait',
            $this->returned => 'returned',
            $this->overdue => 'late',
            default => 'draft',
        };
    }
}
