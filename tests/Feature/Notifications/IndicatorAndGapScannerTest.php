<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Report;
use App\Models\UserNotification;
use App\Notifications\Alerts\DataGapScanner;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use Carbon\CarbonImmutable;

test('an indicator behind pace alerts once and re-arms after recovering', function () {
    $meal = userWithRole('MEAL');
    $recorder = userWithRole('MEAL');
    $indicator = Activity::withoutRecording(function () use ($recorder) {
        $indicator = Indicator::factory()->create(['baseline' => '0', 'target' => '100', 'name' => 'People reached']);
        IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '10', 'date' => '2026-06-30', 'recorded_by' => $recorder->id]);

        return $indicator;
    });
    $scanner = app(OffTrackIndicatorScanner::class);
    $mid = CarbonImmutable::parse('2026-07-01 06:00'); // ~50% of the year elapsed

    expect($scanner->scan($mid))->toBeGreaterThanOrEqual(1)
        ->and($scanner->scan($mid))->toBe(0);

    Activity::withoutRecording(fn () => IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '60', 'date' => '2026-07-02', 'recorded_by' => $recorder->id]));
    $scanner->scan($mid->addDays(2)); // back on pace → re-armed

    Activity::withoutRecording(fn () => IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '20', 'date' => '2026-07-05', 'recorded_by' => $recorder->id]));

    expect($scanner->scan($mid->addDays(5)))->toBeGreaterThanOrEqual(1);
    expect(UserNotification::query()->forUser($meal)->where('data', 'like', '%off_track.pace%')->count())->toBe(2);
});

test('an indicator with no reading inside its frequency window is flagged silent', function () {
    $meal = userWithRole('MEAL');
    Activity::withoutRecording(fn () => Indicator::factory()->create(['frequency' => 'monthly', 'target' => null, 'created_at' => '2026-01-01']));

    app(OffTrackIndicatorScanner::class)->scan(CarbonImmutable::parse('2026-03-02 06:00')); // Monday

    expect(UserNotification::query()->forUser($meal)->where('data', 'like', '%off_track.silent%')->count())->toBe(1);
});

test('indicator and data-gap alerts are never sent on a non-working day', function () {
    $meal = userWithRole('MEAL');
    Activity::withoutRecording(function (): void {
        Indicator::factory()->create(['frequency' => 'monthly', 'target' => null, 'created_at' => '2026-01-01']);
        Project::factory()->status(ProjectStatus::Active)->create(['end_date' => '2026-01-31']);
    });
    $sunday = CarbonImmutable::parse('2026-03-01 06:00');

    expect(app(OffTrackIndicatorScanner::class)->scan($sunday))->toBe(0)
        ->and(app(DataGapScanner::class)->scan($sunday))->toBe(0)
        ->and(UserNotification::query()->forUser($meal)->count())->toBe(0);
});

test('data gaps fire at most once a week', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    Activity::withoutRecording(fn () => Project::factory()->status(ProjectStatus::Active)->create(['user_id' => $ownerEmployee, 'end_date' => '2026-01-31']));
    $scanner = app(DataGapScanner::class);

    $first = $scanner->scan(CarbonImmutable::parse('2026-09-21 06:00'));
    $sameWeek = $scanner->scan(CarbonImmutable::parse('2026-09-23 06:00'));
    $nextWeek = $scanner->scan(CarbonImmutable::parse('2026-09-28 06:00'));

    expect($first)->toBe(3) // no milestones, no indicators, past end date
        ->and($sameWeek)->toBe(0)
        ->and($nextWeek)->toBe(3)
        ->and(UserNotification::query()->forUser($owner)->pluck('priority')->unique()->map->value->all())->toBe(['info']);
});

test('an approved report without files is a data gap for its author', function () {
    $author = userWithRole('MEAL');
    Activity::withoutRecording(fn () => Report::factory()->approved()->create(['user_id' => $author->id]));

    app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-09-21 06:00'));

    expect(UserNotification::query()->forUser($author)->where('data', 'like', '%report_without_files%')->count())->toBe(1);
});

test('an approved monthly report mirror without files is not a data gap', function () {
    $author = userWithRole('MEAL');
    Activity::withoutRecording(fn () => Report::factory()->approved()->create([
        'user_id' => $author->id,
        'monthly_report_id' => MonthlyReport::factory()->create()->id,
    ]));

    app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-09-21 06:00'));

    expect(UserNotification::query()->forUser($author)->where('data', 'like', '%report_without_files%')->count())->toBe(0);
});
