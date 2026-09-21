<?php

use App\Enums\ReportStatus;
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

test('a fresh report is an editable draft unseen by MEAL', function () {
    $report = Report::factory()->create()->fresh();

    expect($report->status)->toBe(ReportStatus::Draft)
        ->and($report->isEditable())->toBeTrue()
        ->and($report->is_seen_by_meal)->toBeFalse();
});

test('an approved report casts its status and is no longer editable', function () {
    $report = Report::factory()->approved()->create(['planned_date' => '2026-03-31'])->fresh();

    expect($report->status)->toBe(ReportStatus::Approved)
        ->and($report->isEditable())->toBeFalse()
        ->and($report->is_seen_by_meal)->toBeTrue()
        ->and($report->planned_date)->toBeInstanceOf(CarbonImmutable::class)
        ->and($report->submit_date->toDateString())->toBe('2026-03-31');
});

test('a reviewed report is locked for editing but not yet approved', function () {
    $report = Report::factory()->reviewed()->create()->fresh();

    expect($report->status)->toBe(ReportStatus::Reviewed)
        ->and($report->isEditable())->toBeFalse()
        ->and($report->is_seen_by_meal)->toBeFalse();
});

test('the approved scope returns only signed off reports', function () {
    Report::factory()->approved()->create();
    Report::factory()->reviewed()->create();
    Report::factory()->create();

    expect(Report::query()->approved()->count())->toBe(1);
});

test('a report casts its value to two decimal places', function () {
    expect(Report::factory()->create(['value' => '9500.5'])->fresh()->value)->toBe('9500.50');
});

test('only a draft past the date it covers is overdue', function () {
    $late = Report::factory()->create(['planned_date' => now()->subDay()->toDateString()]);
    Report::factory()->create(['planned_date' => now()->addDay()->toDateString()]);
    Report::factory()->reviewed()->create(['planned_date' => now()->subMonth()->toDateString()]);

    expect($late->isOverdue())->toBeTrue()
        ->and(Report::query()->overdue()->pluck('id')->all())->toBe([$late->id]);
});
