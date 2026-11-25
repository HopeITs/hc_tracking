<?php

use App\Models\Activity;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\SubjectLinks;
use App\Support\Locations\StoredSubjectBackfill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Y-16 (D-93, D-94): the project page is the row's now, so a notification or
| an activity stored against a project has nowhere to lead. The backfill
| re-points every stored subject at the row that carried that project on the
| day the record was made.
*/

/**
 * A notification stored against a project, as one sent before the backfill.
 */
function storedProjectNotification(Project $project, User $user, string $at = '2026-09-01 09:00:00'): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => AppNotification::class,
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => json_encode(['key' => 'notifications.alerts.project_started', 'module' => 'projects']),
        'module' => 'projects',
        'subject_type' => 'project',
        'subject_id' => $project->id,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return $id;
}

test('the backfill points a stored project notification at the row that carried it', function () {
    $user = User::factory()->role('General Manager')->create();
    [$project, $row] = Activity::withoutRecording(function (): array {
        $project = Project::factory()->active()->create();

        return [$project, rowFor($project)];
    });
    $id = storedProjectNotification($project, $user);

    $counts = app(StoredSubjectBackfill::class)->run(apply: true);

    $stored = DB::table('notifications')->where('id', $id)->first();

    expect($stored->subject_type)->toBe('proposal_location')
        ->and((int) $stored->subject_id)->toBe($row->id)
        // The module names an ability and a screen, not a table (Y-16).
        ->and($stored->module)->toBe('projects')
        ->and($counts['moved']['notifications'])->toBe(1)
        ->and($counts['rows'])->toBe([$row->id => 1]);
});

test('the backfill points a stored activity at the row and leaves a project no row carries alone', function () {
    [$carried, $row, $orphan] = Activity::withoutRecording(function (): array {
        $carried = Project::factory()->active()->create();

        return [$carried, rowFor($carried), Project::factory()->active()->create()];
    });

    foreach ([$carried, $orphan] as $project) {
        Activity::withoutRecording(fn () => Activity::query()->create([
            'subject_type' => 'project',
            'subject_id' => $project->id,
            'module' => 'projects',
            'event' => 'project.activated',
            'changes' => [],
            'properties' => [],
        ]));
    }

    $counts = app(StoredSubjectBackfill::class)->run(apply: true);

    expect($counts['moved']['activities'])->toBe(1)
        ->and($counts['unmapped'])->toBe([$orphan->id => 1])
        ->and(Activity::query()->where('subject_type', 'proposal_location')->where('subject_id', $row->id)->count())->toBe(1)
        ->and(Activity::query()->where('subject_type', 'project')->where('subject_id', $orphan->id)->count())->toBe(1);
});

test('a dry run reports the same counts and writes nothing', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Activity::withoutRecording(function (): Project {
        $project = Project::factory()->active()->create();
        rowFor($project);

        return $project;
    });
    $id = storedProjectNotification($project, $user);

    $counts = app(StoredSubjectBackfill::class)->run(apply: false);

    expect($counts['moved']['notifications'])->toBe(1)
        ->and(DB::table('notifications')->where('id', $id)->value('subject_type'))->toBe('project');
});

test('the backfill is safe to re-run', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Activity::withoutRecording(function (): Project {
        $project = Project::factory()->active()->create();
        rowFor($project);

        return $project;
    });
    storedProjectNotification($project, $user);

    app(StoredSubjectBackfill::class)->run(apply: true);
    $second = app(StoredSubjectBackfill::class)->run(apply: true);

    expect($second['moved']['notifications'])->toBe(0);
});

test('the date decides which row a record reaches', function () {
    // Two proposals carried the same project one after the other (D-94).
    $project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create(['start_date' => '2025-01-01', 'end_date' => '2027-12-31']));
    $user = User::factory()->role('General Manager')->create();

    $first = Activity::withoutRecording(fn () => rowFor($project, Proposal::factory()->running()->create([
        'name' => 'First grant',
        'planned_date_begin' => '2025-01-01',
        'planned_date_end' => '2025-12-31',
    ])));
    $second = Activity::withoutRecording(fn (): ProposalLocation => ProposalLocation::factory()->forProject($project)->create([
        'proposal_id' => Proposal::factory()->running()->create([
            'name' => 'Second grant',
            'planned_date_begin' => '2026-01-01',
            'planned_date_end' => '2026-12-31',
        ])->id,
        'status' => $project->status,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]));

    $old = storedProjectNotification($project, $user, '2025-06-01 09:00:00');
    $new = storedProjectNotification($project, $user, '2026-06-01 09:00:00');

    app(StoredSubjectBackfill::class)->run(apply: true);

    expect((int) DB::table('notifications')->where('id', $old)->value('subject_id'))->toBe($first->id)
        ->and((int) DB::table('notifications')->where('id', $new)->value('subject_id'))->toBe($second->id);
});

test('a stored project notification still opens the row that carries it before the backfill runs', function () {
    [$project, $row] = Activity::withoutRecording(function (): array {
        $project = Project::factory()->active()->create();

        return [$project, rowFor($project)];
    });

    expect(app(SubjectLinks::class)->routeFor('project', $project->id))
        ->toBe(route('projects.show', $row))
        ->and(app(SubjectLinks::class)->routeFor('proposal_location', $row->id))
        ->toBe(route('projects.show', $row));
});

test('the inbox opens a re-pointed notification on the project page', function () {
    $user = User::factory()->role('General Manager')->create();
    [$project, $row] = Activity::withoutRecording(function (): array {
        $project = Project::factory()->active()->create();

        return [$project, rowFor($project)];
    });
    $id = storedProjectNotification($project, $user);

    app(StoredSubjectBackfill::class)->run(apply: true);

    $stored = UserNotification::query()->whereKey($id)->firstOrFail();

    expect(app(SubjectLinks::class)->routeFor((string) $stored->subject_type, (int) $stored->subject_id))
        ->toBe(route('projects.show', $row));
});
