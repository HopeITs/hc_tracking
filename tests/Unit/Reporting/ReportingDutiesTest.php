<?php

declare(strict_types=1);

use App\Enums\ActiveReportType;
use App\Enums\DonorReportState;
use App\Enums\MonthlyReportStatus;
use App\Support\Reporting\ReportingDuties;
use Carbon\CarbonImmutable;

require_once __DIR__.'/TrackerRows.php';

it('merges the open months of one place into one line that opens the oldest', function () {
    $duties = ReportingDuties::group(collect([
        trackerRow(ActiveReportType::Monthly, 3, ['templateId' => 5, 'periodKey' => '2026-10', 'needsMe' => true, 'overdue' => true, 'due' => CarbonImmutable::parse('2026-11-06')]),
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 5, 'periodKey' => '2026-08', 'needsMe' => true, 'overdue' => true, 'due' => CarbonImmutable::parse('2026-09-07')]),
        trackerRow(ActiveReportType::Monthly, 2, ['templateId' => 5, 'periodKey' => '2026-09', 'needsMe' => true, 'overdue' => true, 'due' => CarbonImmutable::parse('2026-10-07')]),
    ]));

    expect($duties['fill'])->toHaveCount(1)
        ->and($duties['fill'][0]->months)->toBe(['2026-08', '2026-09', '2026-10'])
        ->and($duties['fill'][0]->url)->toBe('https://example.test/monthly/1')
        ->and($duties['fill'][0]->due->toDateString())->toBe('2026-09-07')
        ->and($duties['fill'][0]->late)->toBeTrue()
        ->and($duties['total'])->toBe(1)
        ->and($duties['late'])->toBe(1);
});

it('lists each report to validate on its own line', function () {
    $duties = ReportingDuties::group(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 5, 'periodKey' => '2026-09', 'status' => MonthlyReportStatus::Submitted, 'needsMe' => true, 'needsCheck' => true]),
        trackerRow(ActiveReportType::Monthly, 2, ['templateId' => 5, 'periodKey' => '2026-10', 'status' => MonthlyReportStatus::Submitted, 'needsMe' => true, 'needsCheck' => true]),
    ]));

    expect($duties['check'])->toHaveCount(2)
        ->and($duties['check'][0]->months)->toBe(['2026-09'])
        ->and($duties['fill'])->toBe([]);
});

it('puts donor reports the viewer is responsible for under send', function () {
    $duties = ReportingDuties::group(collect([
        trackerRow(ActiveReportType::Donor, 9, ['needsMe' => true, 'status' => DonorReportState::Overdue, 'overdue' => true, 'periodKey' => null]),
    ]));

    expect($duties['send'])->toHaveCount(1)
        ->and($duties['send'][0]->type)->toBe(ActiveReportType::Donor)
        ->and($duties['send'][0]->months)->toBe([])
        ->and($duties['send'][0]->late)->toBeTrue();
});

it('ignores rows that do not need the viewer', function () {
    $duties = ReportingDuties::group(collect([
        trackerRow(ActiveReportType::Monthly, 1),
        trackerRow(ActiveReportType::Donor, 2),
    ]));

    expect($duties)->toMatchArray(['fill' => [], 'check' => [], 'send' => [], 'total' => 0, 'late' => 0]);
});

it('sorts late lines first, then by due date', function () {
    $duties = ReportingDuties::group(collect([
        trackerRow(ActiveReportType::Donor, 1, ['needsMe' => true, 'due' => CarbonImmutable::parse('2026-12-01')]),
        trackerRow(ActiveReportType::Donor, 2, ['needsMe' => true, 'due' => CarbonImmutable::parse('2026-11-30')]),
        trackerRow(ActiveReportType::Donor, 3, ['needsMe' => true, 'overdue' => true, 'due' => CarbonImmutable::parse('2026-11-18')]),
    ]));

    expect(array_map(fn ($line) => $line->url, $duties['send']))->toBe([
        'https://example.test/donor/3',
        'https://example.test/donor/2',
        'https://example.test/donor/1',
    ]);
});
