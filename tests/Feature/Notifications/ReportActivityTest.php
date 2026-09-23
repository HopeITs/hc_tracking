<?php

use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\UserNotification;
use Livewire\Livewire;

test('submitting, approving and meal seeing a report are distinct events for the author and meal', function () {
    $author = userWithRole('Public Relations');
    $meal = userWithRole('MEAL');
    $report = Activity::withoutRecording(fn () => Report::factory()->create(['user_id' => $author->id]));

    $this->actingAs($author);
    $report->update(['status' => ReportStatus::Reviewed, 'submit_date' => now()->toDateString()]);

    $this->actingAs(userWithRole('MEAL'));
    $report->update(['status' => ReportStatus::Approved]);
    $report->update(['is_seen_by_meal' => true]);

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['report.submitted', 'report.approved', 'report.seen_by_meal'])
        ->and(UserNotification::query()->forUser($author)->count())->toBe(1)
        ->and(UserNotification::query()->forUser($meal)->count())->toBeGreaterThanOrEqual(1);
});

test('public relations watch reports written by public relations, not by others', function () {
    $prWatcher = userWithRole('Public Relations');
    $prAuthor = userWithRole('Public Relations');
    $mealAuthor = userWithRole('MEAL');
    [$prReport, $mealReport] = Activity::withoutRecording(fn () => [
        Report::factory()->create(['user_id' => $prAuthor->id]),
        Report::factory()->create(['user_id' => $mealAuthor->id]),
    ]);

    $this->actingAs(userWithRole('MEAL'));
    $prReport->update(['status' => ReportStatus::Reviewed]);
    $mealReport->update(['status' => ReportStatus::Reviewed]);

    expect(UserNotification::query()->forUser($prWatcher)->pluck('subject_id')->all())->toBe([$prReport->id]);
});

test('a file added to a report is recorded on the report', function () {
    $report = Activity::withoutRecording(fn () => Report::factory()->create());

    ReportFile::factory()->create(['report_id' => $report->id]);

    expect(Activity::query()->sole()->event)->toBe('report.file_added');
});

test('submitting from the report page records the submission', function () {
    $author = userWithRole('MEAL');
    $report = Activity::withoutRecording(fn () => Report::factory()->create(['user_id' => $author->id]));

    Livewire::actingAs($author)->test('pages::reports.show', ['report' => $report])->call('submitForReview');

    expect(Activity::query()->sole()->event)->toBe('report.submitted');
});
