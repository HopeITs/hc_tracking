<?php

declare(strict_types=1);

use App\Enums\ActiveReportType;
use App\Enums\MonthlyReportStatus;
use App\Support\Reporting\MonthlyTrackerGrid;

require_once __DIR__.'/TrackerRows.php';

it('puts each report in its place and month', function () {
    $grid = MonthlyTrackerGrid::build(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 7, 'periodKey' => '2026-09', 'status' => MonthlyReportStatus::Validated]),
        trackerRow(ActiveReportType::Monthly, 2, ['templateId' => 7, 'periodKey' => '2026-10', 'status' => MonthlyReportStatus::Submitted]),
    ]), '2026-09', '2026-11');

    expect($grid['months'])->toBe(['2026-09', '2026-10', '2026-11'])
        ->and($grid['places'])->toHaveCount(1)
        ->and($grid['places'][0]['templateId'])->toBe(7)
        ->and($grid['places'][0]['cells']['2026-09']?->id)->toBe(1)
        ->and($grid['places'][0]['cells']['2026-10']?->id)->toBe(2)
        ->and($grid['places'][0]['cells']['2026-11'])->toBeNull();
});

it('leaves a month without a report empty, not late', function () {
    $grid = MonthlyTrackerGrid::build(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 3, 'periodKey' => '2026-09', 'overdue' => true]),
    ]), '2026-06', '2026-11');

    $cells = $grid['places'][0]['cells'];

    expect(array_keys($cells))->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->and([$cells['2026-06'], $cells['2026-07'], $cells['2026-08']])->toBe([null, null, null])
        ->and($cells['2026-09']->state())->toBe('late')
        ->and($grid['counts']['late'])->toBe(1);
});

it('counts behind as the late months of a place', function () {
    $grid = MonthlyTrackerGrid::build(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 4, 'periodKey' => '2026-08', 'overdue' => true]),
        trackerRow(ActiveReportType::Monthly, 2, ['templateId' => 4, 'periodKey' => '2026-09', 'overdue' => true]),
        trackerRow(ActiveReportType::Monthly, 3, ['templateId' => 4, 'periodKey' => '2026-10', 'status' => MonthlyReportStatus::Submitted]),
    ]), '2026-08', '2026-10');

    expect($grid['places'][0]['behind'])->toBe(2);
});

it('sorts the most behind place first, then by name', function () {
    $grid = MonthlyTrackerGrid::build(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 1, 'name' => 'Aleppo', 'periodKey' => '2026-10', 'status' => MonthlyReportStatus::Validated]),
        trackerRow(ActiveReportType::Monthly, 2, ['templateId' => 2, 'name' => 'Homs', 'periodKey' => '2026-10', 'overdue' => true]),
        trackerRow(ActiveReportType::Monthly, 3, ['templateId' => 3, 'name' => 'Damascus', 'periodKey' => '2026-10', 'status' => MonthlyReportStatus::Validated]),
    ]), '2026-10', '2026-10');

    expect(array_column($grid['places'], 'name'))->toBe(['Homs', 'Aleppo', 'Damascus']);
});

it('counts only cells inside the window', function () {
    $grid = MonthlyTrackerGrid::build(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 1, 'periodKey' => '2026-03', 'overdue' => true]),
        trackerRow(ActiveReportType::Monthly, 2, ['templateId' => 1, 'periodKey' => '2026-10', 'status' => MonthlyReportStatus::Submitted, 'needsMe' => true, 'needsCheck' => true]),
        trackerRow(ActiveReportType::Monthly, 3, ['templateId' => 1, 'periodKey' => '2026-11']),
        trackerRow(ActiveReportType::Monthly, 4, ['templateId' => 1, 'periodKey' => '2026-09', 'status' => MonthlyReportStatus::Validated]),
    ]), '2026-09', '2026-11');

    expect($grid['counts'])->toBe(['open' => 2, 'needs' => 1, 'late' => 0, 'wait' => 1, 'done' => 1]);
});

it('drops a place with no report inside the window', function () {
    $grid = MonthlyTrackerGrid::build(collect([
        trackerRow(ActiveReportType::Monthly, 1, ['templateId' => 1, 'periodKey' => '2026-01', 'overdue' => true]),
    ]), '2026-09', '2026-11');

    expect($grid['places'])->toBe([]);
});
