<?php

use App\Enums\ActiveReportType;
use App\Enums\DonorReportState;
use App\Enums\MonthlyReportStatus;
use App\Support\Reporting\ActiveReportRow;
use Carbon\CarbonImmutable;

/**
 * A tracker row built in memory for the reader tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function trackerRow(ActiveReportType $type, int $id, array $overrides = []): ActiveReportRow
{
    $values = [
        'type' => $type,
        'id' => $id,
        'name' => 'Place '.$id,
        'context' => 'Proposal '.$id,
        'periodKey' => '2026-10',
        'status' => $type === ActiveReportType::Monthly ? MonthlyReportStatus::Draft : DonorReportState::Due,
        'due' => CarbonImmutable::parse('2026-11-06'),
        'overdue' => false,
        'owner' => 'Owner',
        'url' => 'https://example.test/'.$type->value.'/'.$id,
        'needsMe' => false,
        'programId' => null,
        'donorId' => null,
        'search' => 'place '.$id,
        'templateId' => $type === ActiveReportType::Monthly ? 1 : null,
        'returned' => false,
        'needsCheck' => false,
        ...$overrides,
    ];

    return new ActiveReportRow(...$values);
}
