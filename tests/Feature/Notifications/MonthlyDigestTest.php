<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\User;
use App\Notifications\Alerts\MonthlyDigestScanner;
use App\Notifications\AppNotification;
use App\Notifications\MonthlyDigestMail;
use App\Notifications\NotificationPresenter;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// 2026-11-01 is a Sunday, so Monday 2026-11-02 is November's first working day (Mon–Fri default).
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->pmEmployee = insertHrEmployee(firstNameEn: 'Pam', lastNameEn: 'Manager');

    Activity::withoutRecording(function (): void {
        $this->programA = Program::factory()->create(['manager_id' => $this->pmEmployee]);
        $this->programB = Program::factory()->create(['manager_id' => null]);

        $this->started = Project::factory()->create(['program_id' => $this->programA->id, 'status' => ProjectStatus::Active, 'start_date' => '2026-10-05', 'end_date' => '2027-06-30']);
        $this->ended = Project::factory()->create(['program_id' => $this->programB->id, 'status' => ProjectStatus::Closed, 'start_date' => '2026-01-01', 'end_date' => '2026-10-20']);
        Project::factory()->create(['program_id' => $this->programA->id, 'status' => ProjectStatus::Draft, 'start_date' => '2026-10-10', 'end_date' => '2027-01-31']);

        $indicator = Indicator::factory()->create([
            'result_id' => Result::factory()->create(['program_id' => $this->programB->id])->id,
            'project_id' => $this->ended->id,
            'baseline' => '0',
            'target' => '100',
        ]);
        IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '10', 'date' => '2026-09-30']);

        $agreement = DonorProposal::factory()->create(['budget' => '98765.43', 'currency' => 'USD']);
        $this->started->proposals()->attach($agreement->proposal_id);
        DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim report', 'due_on' => '2026-11-16']);
        DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Quarterly update', 'due_on' => '2026-10-12']);
        DonorReport::factory()->submitted()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Final report', 'due_on' => '2026-10-01']);
    });

    $this->pm = userWithRole('Managers', $this->pmEmployee);
    $this->gm = userWithRole('General Manager');
});

function runMonthlyDigest(string $at, array $options = []): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return test()->artisan('reports:monthly-digest', $options)->run();
}

function monthlyMailFor(User $user): ?MonthlyDigestMail
{
    $sent = Notification::sent($user, MonthlyDigestMail::class);

    return $sent->count() === 1 ? $sent->first() : null;
}

test('the digest goes out only on the first working day of the month', function () {
    runMonthlyDigest('2026-11-01 08:00');
    Notification::assertNothingSent();

    runMonthlyDigest('2026-11-02 08:00');
    runMonthlyDigest('2026-11-03 08:00');

    Notification::assertSentToTimes($this->gm, MonthlyDigestMail::class, 1);
    Notification::assertSentToTimes($this->gm, AppNotification::class, 1);
    Notification::assertSentTo($this->gm, AppNotification::class, fn (AppNotification $notification): bool => $notification->payload->key === 'notifications.digests.monthly.summary'
        && $notification->payload->module === 'system'
        && $notification->payload->params['month'] === ['month' => '2026-10']);
    expect(monthlyMailFor($this->gm)->month)->toBe('2026-10');
});

test('general management sees every program and a program manager only theirs, by permission', function () {
    runMonthlyDigest('2026-11-02 08:00');

    expect(monthlyMailFor($this->gm)->allPrograms)->toBeTrue()
        ->and(monthlyMailFor($this->gm)->sections)->toBe([
            'projects' => ['started' => 1, 'ended' => 1],
            'indicators' => ['off_track' => 1],
            'donor_reports' => ['due' => 1, 'overdue' => 1],
        ])
        ->and(monthlyMailFor($this->pm)->allPrograms)->toBeFalse()
        ->and(monthlyMailFor($this->pm)->sections)->toBe([
            'projects' => ['started' => 1, 'ended' => 0],
            'indicators' => ['off_track' => 0],
        ]);
});

test('the MEAL Manager slot holder and the PR Manager are copied, and one person gets one digest', function () {
    $mealEmployee = insertHrEmployee(firstNameEn: 'Maha', lastNameEn: 'Slot');
    holdMealManagerSlot($mealEmployee);
    $mealManager = userWithRole('MEAL', $mealEmployee);
    $prManager = userWithRole('Public Relations Manager');
    $superAdmin = userWithRole('Super Admin');
    $this->pm->assignRole('General Manager');

    runMonthlyDigest('2026-11-02 08:00');

    expect(monthlyMailFor($mealManager)->allPrograms)->toBeTrue()
        ->and(monthlyMailFor($prManager)->allPrograms)->toBeTrue()
        ->and(monthlyMailFor($prManager)->sections)->toHaveKey('donor_reports')
        ->and(monthlyMailFor($this->pm)->allPrograms)->toBeTrue();
    Notification::assertSentToTimes($this->pm, AppNotification::class, 1);
    Notification::assertNotSentTo($superAdmin, MonthlyDigestMail::class);
    Notification::assertNotSentTo($superAdmin, AppNotification::class);
});

test('a rerun and --month for the same month send nothing new', function () {
    runMonthlyDigest('2026-11-02 08:00');
    runMonthlyDigest('2026-11-02 09:00');
    runMonthlyDigest('2026-11-02 10:00', ['--month' => '2026-10']);

    Notification::assertSentToTimes($this->gm, MonthlyDigestMail::class, 1);
    Notification::assertSentToTimes($this->gm, AppNotification::class, 1);
});

test('--month sends a past month on any day', function () {
    expect(runMonthlyDigest('2026-11-10 08:00', ['--month' => '2026-09']))->toBe(0);

    expect(monthlyMailFor($this->gm)->month)->toBe('2026-09')
        ->and(DB::table('notification_alert_ledger')->where('rule', 'monthly_digest')->where('stage', '2026-09')->where('user_id', $this->gm->id)->exists())->toBeTrue();
});

test('no amount or currency reaches the payload or the mail', function () {
    runMonthlyDigest('2026-11-02 08:00');

    $payload = Notification::sent($this->gm, AppNotification::class)->first()->payload;
    $mail = monthlyMailFor($this->gm);
    $html = (string) $mail->toMail($this->gm)->render();

    expect(json_encode($payload->params))->not->toContain('98765')->not->toContain('USD')
        ->and(json_encode($mail->sections))->not->toContain('98765')
        ->and($html)->not->toContain('98765')->not->toContain('98,765')->not->toContain('USD');
});

test('an arabic reader gets the digest in arabic', function () {
    $this->gm->forceFill(['locale' => 'ar'])->save();

    runMonthlyDigest('2026-11-02 08:00');

    Notification::assertSentTo($this->gm, MonthlyDigestMail::class, fn (MonthlyDigestMail $mail, array $channels, User $notifiable, ?string $locale): bool => $locale === 'ar');

    app()->setLocale('ar');
    $message = monthlyMailFor($this->gm)->toMail($this->gm);

    expect($message->subject)->toBe('الملخص الشهري: تشرين الأول 2026')
        ->and((string) $message->render())->toContain('المشاريع')->toContain('dir="ltr"');
});

test('a user without an HR email gets the in-app digest only', function () {
    $noEmail = Activity::withoutRecording(fn () => User::factory()->role('General Manager')->create(['email' => '']));

    runMonthlyDigest('2026-11-02 08:00');

    Notification::assertSentToTimes($noEmail, AppNotification::class, 1);
    Notification::assertNotSentTo($noEmail, MonthlyDigestMail::class);
});

test('the mail is queued with three tries and backoff, apart from the in-app part', function () {
    $mail = new MonthlyDigestMail('2026-10', true, []);

    expect($mail)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail->tries)->toBe(3)
        ->and($mail->backoff)->not->toBeEmpty()
        ->and($mail->via($this->gm))->toBe(['mail']);

    $scanner = app(MonthlyDigestScanner::class);
    $scanner->send(CarbonImmutable::parse('2026-10-01'));
    DB::table('notification_alert_ledger')->where('rule', 'monthly_digest_mail')->delete();
    $scanner->send(CarbonImmutable::parse('2026-10-01'));

    Notification::assertSentToTimes($this->gm, AppNotification::class, 1);
    Notification::assertSentToTimes($this->gm, MonthlyDigestMail::class, 2);
});

test('a dry run lists the digests and sends or claims nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00'));

    $this->artisan('reports:monthly-digest', ['--dry-run' => true])
        ->expectsOutputToContain($this->gm->name)
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(DB::table('notification_alert_ledger')->count())->toBe(0);
});

test('an invalid or future month fails with the translated message', function (string $month) {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00'));

    $this->artisan('reports:monthly-digest', ['--month' => $month])
        ->expectsOutputToContain(__('notifications.digests.monthly.invalid_month'))
        ->assertFailed();

    Notification::assertNothingSent();
})->with(['2026-13', 'october', '2026-12', '2026-1']);

test('a recipient with nothing to report hears "nothing pending"', function () {
    $quietEmployee = insertHrEmployee(firstNameEn: 'Quiet', lastNameEn: 'Manager');
    Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $quietEmployee]));
    $quiet = userWithRole('Managers', $quietEmployee);

    runMonthlyDigest('2026-11-02 08:00');

    $payload = Notification::sent($quiet, AppNotification::class)->first()->payload;
    $html = (string) monthlyMailFor($quiet)->toMail($quiet)->render();

    expect(app(NotificationPresenter::class)->param($payload->params['sections'], 'en'))->toBe(__('notifications.digests.monthly.nothing_pending'))
        ->and($html)->toContain(__('notifications.digests.monthly.nothing_pending'));
});

test('the in-app summary lists the non-zero counts in the reader\'s language', function () {
    runMonthlyDigest('2026-11-02 08:00');

    $payload = Notification::sent($this->pm, AppNotification::class)->first()->payload;
    $presenter = app(NotificationPresenter::class);

    expect($presenter->param($payload->params['sections'], 'en'))->toBe('Projects started: 1')
        ->and($presenter->param($payload->params['month'], 'ar'))->toBe('تشرين الأول 2026');
});

test('building the digest takes a flat number of queries however many programs, records and managers there are', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00'));
    $scanner = app(MonthlyDigestScanner::class);
    $count = function () use ($scanner): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $scanner->preview(CarbonImmutable::parse('2026-10-01'));

        return $queries;
    };
    $grow = function (): void {
        $employee = insertHrEmployee(firstNameEn: fake()->firstName(), lastNameEn: 'Manager');
        $program = Activity::withoutRecording(function () use ($employee): Program {
            $program = Program::factory()->create(['manager_id' => $employee]);
            $project = Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Active, 'start_date' => '2026-10-07', 'end_date' => '2027-03-31']);
            $indicator = Indicator::factory()->create(['result_id' => Result::factory()->create(['program_id' => $program->id])->id, 'project_id' => $project->id, 'baseline' => '0', 'target' => '100']);
            IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '5', 'date' => '2026-09-30']);
            $agreement = DonorProposal::factory()->create();
            $project->proposals()->attach($agreement->proposal_id);
            DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => '2026-10-20']);

            return $program;
        });
        userWithRole('Managers', $employee);
    };

    $grow();
    $count(); // warms the remembered HR lookups and the permission cache
    $few = $count();

    foreach (range(1, 3) as $ignored) {
        $grow();
    }
    $count();

    expect($count())->toBe($few);
});
