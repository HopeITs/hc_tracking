<?php

use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a report belongs to a target and a user', function () {
    $target = PlanIndicator::factory()->create();
    $user = User::factory()->create();

    $report = Report::factory()->create([
        'plan_indicator_id' => $target->id,
        'user_id' => $user->id,
    ]);

    expect($report->planIndicator)->toBeInstanceOf(PlanIndicator::class)
        ->and($report->planIndicator->id)->toBe($target->id)
        ->and($report->user->id)->toBe($user->id);
});

test('a report has its means of verification', function () {
    $report = Report::factory()->create();

    ReportFile::factory(2)->create(['report_id' => $report->id]);
    ReportFile::factory()->create();

    expect($report->files)->toHaveCount(2)
        ->and($report->files->first())->toBeInstanceOf(ReportFile::class);
});

test('a fresh report casts its three flags to booleans', function () {
    $report = Report::factory()->create()->fresh();

    expect($report->is_approved)->toBeFalse()
        ->and($report->is_editable)->toBeTrue()
        ->and($report->is_seen_by_meal)->toBeFalse();
});

test('an approved report casts its flags and dates', function () {
    $report = Report::factory()->approved()->create(['planned_date' => '2026-03-31'])->fresh();

    expect($report->is_approved)->toBeTrue()
        ->and($report->is_editable)->toBeFalse()
        ->and($report->is_seen_by_meal)->toBeTrue()
        ->and($report->planned_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($report->approved_date->toDateString())->toBe('2026-03-31')
        ->and($report->edited_date)->toBeNull();
});

test('a report casts its value to two decimal places', function () {
    expect(Report::factory()->create(['value' => '9500.5'])->fresh()->value)->toBe('9500.50');
});
