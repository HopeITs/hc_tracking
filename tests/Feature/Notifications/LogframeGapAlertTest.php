<?php

use App\Actions\Projects\SaveProject;
use App\Enums\EventImportance;
use App\Enums\OversightMode;
use App\Enums\ProjectStatus;
use App\Enums\ResultType;
use App\Http\Middleware\SyncProgramsFromHr;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\Result;
use App\Models\Sector;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\DataGapScanner;
use App\Notifications\Alerts\LogframeGapAlert;
use App\Notifications\EventCatalog;
use App\Notifications\SubjectLinks;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * The logframe-missing alerts a user holds.
 *
 * @return Collection<int, UserNotification>
 */
function logframeAlertsFor(User $user): Collection
{
    return UserNotification::query()->forUser($user)->where('data', 'like', '%logframe_missing%')->get();
}

/**
 * A program-framework root for the program (proposal_id NULL).
 */
function programFrameworkRoot(Program $program): Result
{
    return Activity::withoutRecording(fn (): Result => Result::factory()->type(ResultType::Impact)->create(['program_id' => $program->id]));
}

beforeEach(function () {
    $this->meal = userWithRole('MEAL');
    $this->manager = mealManager();
    $this->program = Activity::withoutRecording(fn (): Program => Program::factory()->create());
});

describe('SaveProject', function () {
    beforeEach(function () {
        $this->actor = userWithRole('General Manager');
        $this->owner = $this->program->manager_id;
        $this->centerId = insertHrCenter(insertHrCity('Idlib', 'إدلب'), 'Hope Center Idlib', 'مركز الأمل إدلب');
        $this->sector = Sector::factory()->create();
        $this->form = fn (array $fields = []): array => [
            'name' => 'Idlib Micro',
            'centerId' => (string) $this->centerId,
            'programId' => (string) $this->program->id,
            'userId' => (string) $this->owner,
            'sectorIds' => [$this->sector->id],
            ...$fields,
        ];
    });

    test('a project created under a program without a framework alerts MEAL once, after commit', function () {
        $this->actingAs($this->actor);

        app(SaveProject::class)->handle(null, ($this->form)(), $this->actor);

        expect(logframeAlertsFor($this->meal))->toHaveCount(1)
            ->and(logframeAlertsFor($this->manager))->toHaveCount(1)
            ->and(logframeAlertsFor($this->meal)->first())
            ->priority->value->toBe('important')
            ->module->toBe('results')
            ->subject_type->toBe('program')
            ->subject_id->toBe($this->program->id);
    });

    test('a program that has a framework raises nothing', function () {
        programFrameworkRoot($this->program);
        $this->actingAs($this->actor);

        app(SaveProject::class)->handle(null, ($this->form)(), $this->actor);

        expect(logframeAlertsFor($this->meal))->toHaveCount(0);
    });

    test('a project moved to a program without a framework alerts', function () {
        programFrameworkRoot($this->program);
        $this->actingAs($this->actor);
        $project = app(SaveProject::class)->handle(null, ($this->form)(), $this->actor);
        $bare = Activity::withoutRecording(fn (): Program => Program::factory()->create(['manager_id' => $this->owner]));

        app(SaveProject::class)->handle($project, ($this->form)(['programId' => (string) $bare->id]), $this->actor);

        expect(logframeAlertsFor($this->meal)->pluck('subject_id')->all())->toBe([$bare->id]);
    });

    test('a refused save sends nothing', function () {
        $this->actingAs($this->actor);

        expect(fn () => app(SaveProject::class)->handle(null, ($this->form)(['sectorIds' => []]), $this->actor))->toThrow(ValidationException::class);

        expect(logframeAlertsFor($this->meal))->toHaveCount(0);
    });
});

test('the same gap is never alerted twice, and re-arms once a framework comes and goes', function () {
    $alert = app(LogframeGapAlert::class);

    expect($alert->notify([$this->program->id]))->toBe(2)
        ->and($alert->notify([$this->program->id]))->toBe(0);

    $root = programFrameworkRoot($this->program);
    expect($alert->notify([$this->program->id]))->toBe(0);

    Activity::withoutRecording(fn () => $root->delete());

    expect($alert->notify([$this->program->id]))->toBe(2)
        ->and(logframeAlertsFor($this->meal))->toHaveCount(2);
});

test('nobody without results.view is alerted', function () {
    Role::findByName('MEAL')->revokePermissionTo('results.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app(LogframeGapAlert::class)->notify([$this->program->id]);

    expect(logframeAlertsFor($this->meal))->toHaveCount(0)
        ->and(logframeAlertsFor($this->manager))->toHaveCount(0);
});

test('ten programs at once give each recipient one grouped notification with the count', function () {
    $programs = Activity::withoutRecording(fn () => Program::factory()->count(9)->create())->push($this->program);

    expect(app(LogframeGapAlert::class)->notify($programs->modelKeys()))->toBe(2);

    $grouped = logframeAlertsFor($this->meal);

    expect($grouped)->toHaveCount(1)
        ->and($grouped->first()->data['key'])->toBe('notifications.alerts.logframe_missing.grouped')
        ->and($grouped->first()->data['params']['count'])->toBe(10)
        ->and(app(SubjectLinks::class)->urlFor($grouped->first()))->toBe(route('results.index'))
        ->and(app(LogframeGapAlert::class)->notify($programs->modelKeys()))->toBe(0);
});

test('a single alert names the program and opens its pre-filled create form', function () {
    app(LogframeGapAlert::class)->notify([$this->program->id]);

    $notification = logframeAlertsFor($this->meal)->first();

    expect($notification->data['key'])->toBe('notifications.alerts.logframe_missing.single')
        ->and($notification->data['params']['subject'])->toBe($this->program->name)
        ->and(app(SubjectLinks::class)->urlFor($notification))->toBe(route('results.index', ['program' => $this->program->id, 'create' => 1]));
});

describe('the daily rule', function () {
    test('flags programs with open projects and no framework, once', function () {
        Activity::withoutRecording(fn () => Project::factory()->status(ProjectStatus::Active)->create(['program_id' => $this->program->id]));
        $framed = Activity::withoutRecording(fn (): Program => Program::factory()->create());
        programFrameworkRoot($framed);
        Activity::withoutRecording(fn () => Project::factory()->status(ProjectStatus::Draft)->create(['program_id' => $framed->id]));
        $closed = Activity::withoutRecording(fn (): Program => Program::factory()->create());
        Activity::withoutRecording(fn () => Project::factory()->status(ProjectStatus::Closed)->create(['program_id' => $closed->id]));
        $monday = CarbonImmutable::parse('2026-09-21 06:00');

        app(DataGapScanner::class)->scan($monday);
        app(DataGapScanner::class)->scan($monday->addDay());

        expect(logframeAlertsFor($this->meal)->pluck('subject_id')->all())->toBe([$this->program->id]);
    });

    test('sends nothing on a non-working day', function () {
        Activity::withoutRecording(fn () => Project::factory()->status(ProjectStatus::Active)->create(['program_id' => $this->program->id]));

        app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-09-20 06:00')); // Sunday

        expect(logframeAlertsFor($this->meal))->toHaveCount(0);
    });
});

describe('the HR sync', function () {
    test('a direct syncFromHr() stays silent and returns only the new ids', function () {
        $newId = insertHrProgram('Study Zone');

        expect(Program::syncFromHr())->toBe([$newId])
            ->and(Program::syncFromHr())->toBe([])
            ->and(logframeAlertsFor($this->meal))->toHaveCount(0);
    });

    test('seeding programs never alerts', function () {
        insertHrProgram('Study Zone');

        Activity::withoutRecording(fn () => Program::syncFromHr());

        expect(logframeAlertsFor($this->meal))->toHaveCount(0);
    });

    test('the request middleware alerts for the programs it newly mirrored', function () {
        $newId = insertHrProgram('Study Zone');
        Cache::forget(SyncProgramsFromHr::CacheKey);

        (new SyncProgramsFromHr)->handle(Request::create('/'), fn () => new Response);

        expect(logframeAlertsFor($this->meal)->pluck('subject_id')->all())->toBe([$newId]);
    });
});

test('the catalog carries the key as a MEAL and management digest', function () {
    $entry = EventCatalog::all()['program.logframe_missing'];

    expect($entry['module'])->toBe('results')
        ->and($entry['importance'])->toBe(EventImportance::Digest)
        ->and($entry['oversight'])->toBe(OversightMode::All)
        ->and($entry['direct'])->toBe('scanner');
});
