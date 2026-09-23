<?php

use App\Jobs\RunScanner;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\DailyBriefScanner;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WeeklyDigestScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

test('program managers get their program digest and the general manager the whole picture, on monday only', function () {
    $managerEmployee = insertHrEmployee();
    $manager = userWithRole('Managers', $managerEmployee);
    $generalManager = userWithRole('General Manager');
    Activity::withoutRecording(function () use ($managerEmployee): void {
        $program = Program::factory()->create(['manager_id' => $managerEmployee, 'name' => 'WASH']);
        $project = Project::factory()->create(['program_id' => $program->id, 'end_date' => '2027-12-31']);
        $milestone = Milestone::factory()->create(['project_id' => $project->id, 'planned_end_date' => '2027-12-31']);
        Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'due_date' => '2026-09-15']);
        Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'due_date' => '2026-09-25']);
        Risk::factory()->create(['project_id' => $project->id, 'happened' => true, 'solution' => null]);
    });

    expect(app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-22 08:00')))->toBe(0); // Tuesday

    app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-21 08:00'));
    app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-21 09:00'));

    expect(sentencesFor($manager))->toBe(['WASH this week: 1 overdue, 0 indicators off track, 1 open risks, 1 deadlines in the next 14 days'])
        ->and(UserNotification::query()->forUser($generalManager)->sole()->kind->value)->toBe('digest');
});

test('the digest clock queues each digest once when its time has passed', function () {
    Queue::fake();
    app(NotificationSettings::class)->save(['daily_brief_time' => '07:30', 'weekly_digest_time' => '08:00']);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:29'));
    $this->artisan('notifications:digests')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->travelTo(CarbonImmutable::parse('2026-09-21 08:05'));
    $this->artisan('notifications:digests');
    $this->artisan('notifications:digests');

    Queue::assertPushed(RunScanner::class, fn (RunScanner $job): bool => $job->scanner === DailyBriefScanner::class);
    Queue::assertPushed(RunScanner::class, 2);
});
