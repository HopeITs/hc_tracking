<?php

declare(strict_types=1);

use App\Actions\Projects\ActivateProject;
use App\Actions\Projects\ApproveProjectFromProposal;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\CloseProject;
use App\Actions\Projects\ReopenProjectAsDraft;
use App\Actions\Projects\RestoreProject;
use App\Actions\Projects\SaveProject;
use App\Actions\Projects\SyncProjectSectors;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Assignment;
use App\Models\Media;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Sector;
use App\Support\Locations\ProjectLocationMirror;
use App\Support\ProjectPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Y-11 slice 2: every project writer leaves `locations:verify-mirror` clean,
 * and a write that goes around the mirror is caught.
 */

/**
 * `locations:verify-mirror`'s exit code.
 */
function verifyMirror(): int
{
    return Artisan::call('locations:verify-mirror');
}

/**
 * A project carried by one city-wide row of its own proposal.
 *
 * @return array{0: Project, 1: ProposalLocation}
 */
function mirroredProject(array $overrides = []): array
{
    $employee = insertHrEmployee();

    $project = Project::factory()->create([
        'user_id' => $employee,
        'program_id' => Program::factory()->create(['manager_id' => insertHrEmployee()])->id,
        'status' => ProjectStatus::Active->value,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        ...$overrides,
    ]);

    $proposal = Proposal::factory()->create([
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
    ]);
    $proposal->projects()->attach($project->id);

    $row = proposalRowForProject($proposal, $project, false);

    // apply(), not sync(): a first baseline writes the sectors, the
    // acknowledgement and the files too, which sync() leaves to their own writers.
    app(ProjectLocationMirror::class)->apply($project);

    return [$project, $row->fresh()];
}

test('the mirror starts clean and a raw update of the project makes verify fail and name the row', function () {
    [$project, $row] = mirroredProject();

    expect(verifyMirror())->toBe(0);

    // Straight to the table: exactly what a writer that forgot the mirror does.
    DB::table('projects')->where('id', $project->id)->update(['target_men' => 999]);

    $this->artisan('locations:verify-mirror')
        ->assertExitCode(1)
        ->expectsOutputToContain('row '.$row->id);
});

test('verify names the column that drifted', function () {
    [$project, $row] = mirroredProject();

    DB::table('proposal_locations')->where('id', $row->id)->update(['status' => ProjectStatus::Draft->value]);

    $this->artisan('locations:verify-mirror')
        ->assertExitCode(1)
        ->expectsOutputToContain('status');
});

test('SaveProject leaves the mirror clean', function () {
    [$project] = mirroredProject(['status' => ProjectStatus::Draft->value, 'start_date' => null, 'end_date' => null]);
    $manager = userWithRole('Managers');
    $this->actingAs($manager);

    app(SaveProject::class)->handle($project, [
        'name' => 'A new name',
        'description' => 'Changed here',
        'cityId' => $project->city_id,
        'centerId' => $project->center_id,
        'programId' => $project->program_id,
        'userId' => $project->user_id,
        'targetBeneficiaries' => 321,
        'targetMen' => 21,
        'targetWomen' => 100,
        'targetBoys' => 100,
        'targetGirls' => 100,
    ], $manager);

    expect(verifyMirror())->toBe(0)
        ->and((int) DB::table('proposal_locations')->where('project_id', $project->id)->value('target_beneficiaries'))->toBe(321)
        ->and(DB::table('proposal_locations')->where('project_id', $project->id)->value('description'))->toBe('Changed here');
});

test('CloseProject, ArchiveProject, RestoreProject and ReopenProjectAsDraft each leave the mirror clean', function () {
    [$project] = mirroredProject();
    $this->actingAs(userWithRole('Managers'));

    app(CloseProject::class)->handle($project);
    expect(verifyMirror())->toBe(0);
    expect(DB::table('proposal_locations')->where('project_id', $project->id)->value('status'))->toBe(ProjectStatus::Closed->value);

    app(ArchiveProject::class)->handle($project->fresh());
    expect(verifyMirror())->toBe(0);
    expect(DB::table('proposal_locations')->where('project_id', $project->id)->value('status'))->toBe(ProjectStatus::Archived->value);

    app(RestoreProject::class)->handle($project->fresh());
    expect(verifyMirror())->toBe(0);

    // reopenAsDraft is gated on proposals.update, which Managers do not hold.
    $this->actingAs(userWithRole('Public Relations Manager'));
    app(ReopenProjectAsDraft::class)->handle($project->fresh());
    expect(verifyMirror())->toBe(0);
    expect(DB::table('proposal_locations')->where('project_id', $project->id)->value('status'))->toBe(ProjectStatus::Draft->value);
});

test('ActivateProject leaves the mirror clean', function () {
    [$project] = mirroredProject(['status' => ProjectStatus::Approved->value]);
    $project->proposals()->update(['proposals.status' => 'approved']);

    Assignment::query()->where('assignable_type', 'project')->where('assignable_id', $project->id)->delete();
    Assignment::factory()->create([
        'assignable_type' => 'project',
        'assignable_id' => $project->id,
        'field' => 'user_id',
        'employee_id' => $project->user_id,
        'status' => AssignmentStatus::Acknowledged->value,
        'responded_at' => now(),
    ]);

    $this->actingAs(userWithRole('MEAL'));

    app(ActivateProject::class)->handle($project->fresh(), 'template_confirmation');

    expect(verifyMirror())->toBe(0)
        ->and(DB::table('proposal_locations')->where('project_id', $project->id)->value('status'))->toBe(ProjectStatus::Active->value);
});

test('a widened period reaches the rows', function () {
    [$project, $row] = mirroredProject();

    ProjectPeriods::widen($project, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-06-30'));

    // The row's own proposal still ends in 2026, so its period stays clipped
    // to the proposal while the project's grew.
    expect(verifyMirror())->toBe(0)
        ->and(DB::table('proposal_locations')->where('id', $row->id)->value('end_date'))->toBe('2026-12-31')
        ->and($project->fresh()->end_date->toDateString())->toBe('2027-06-30');
});

test('a sector sync reaches every row of the project', function () {
    [$project, $row] = mirroredProject();
    $sectors = Sector::factory()->count(2)->create();
    $this->actingAs(userWithRole('Managers'));

    // ->fresh(): on a wasRecentlyCreated model the action authorizes `create`.
    app(SyncProjectSectors::class)->handle($project->fresh(), $sectors->pluck('id')->all());

    expect(verifyMirror())->toBe(0)
        ->and(DB::table('proposal_location_sector')->where('proposal_location_id', $row->id)->count())->toBe(2);

    // A sector taken off the project comes off the row too.
    app(SyncProjectSectors::class)->handle($project->fresh(), [$sectors->first()->id]);

    expect(verifyMirror())->toBe(0)
        ->and(DB::table('proposal_location_sector')->where('proposal_location_id', $row->id)->count())->toBe(1);
});

test('an acknowledgement and a rejection both reach the row', function () {
    [$project, $row] = mirroredProject(['status' => ProjectStatus::Draft->value, 'start_date' => null, 'end_date' => null]);
    $owner = userWithRole('Team Member', $project->user_id);
    $this->actingAs($owner);

    Assignment::query()->where('assignable_type', 'project')->where('assignable_id', $project->id)->delete();
    $assignment = Assignment::factory()->create([
        'assignable_type' => 'project',
        'assignable_id' => $project->id,
        'field' => 'user_id',
        'employee_id' => $project->user_id,
        'status' => AssignmentStatus::Pending->value,
    ]);

    $assignment->acknowledge($owner);

    expect(verifyMirror())->toBe(0)
        ->and(DB::table('assignments')->where('assignable_type', 'proposal_location')->where('assignable_id', $row->id)->value('status'))
        ->toBe(AssignmentStatus::Acknowledged->value);

    $second = Assignment::factory()->create([
        'assignable_type' => 'project',
        'assignable_id' => $project->id,
        'field' => 'user_id',
        'employee_id' => $project->user_id,
        'status' => AssignmentStatus::Pending->value,
    ]);
    $second->reject($owner, 'I am away until March');

    expect(verifyMirror())->toBe(0)
        ->and(DB::table('assignments')->where('assignable_type', 'proposal_location')->where('assignable_id', $row->id)->value('status'))
        ->toBe(AssignmentStatus::Rejected->value);
});

test('ApproveProjectFromProposal leaves the mirror clean', function () {
    [$project, $row] = mirroredProject(['status' => ProjectStatus::Draft->value, 'start_date' => null, 'end_date' => null]);
    $project->proposals()->update(['proposals.status' => 'approved']);

    Assignment::query()->where('assignable_type', 'project')->where('assignable_id', $project->id)->delete();
    Assignment::factory()->create([
        'assignable_type' => 'project',
        'assignable_id' => $project->id,
        'field' => 'user_id',
        'employee_id' => $project->user_id,
        'status' => AssignmentStatus::Acknowledged->value,
        'responded_at' => now(),
    ]);

    $this->actingAs(userWithRole('Public Relations Manager'));

    app(ApproveProjectFromProposal::class)->handle($project->fresh(), 'proposal_approval');

    expect(verifyMirror())->toBe(0)
        ->and(DB::table('proposal_locations')->where('id', $row->id)->value('status'))->toBe(ProjectStatus::Approved->value);
});

test('a file added to a project is mirrored and one deleted is removed', function () {
    [$project, $row] = mirroredProject();
    $this->actingAs(userWithRole('Managers'));

    $file = Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'created_at' => '2026-06-01',
    ]);

    app(ProjectLocationMirror::class)->attachFile($project, $file);

    expect(DB::table('media')->where('mediable_type', 'proposal_location')->where('mediable_id', $row->id)->count())->toBe(1)
        ->and(verifyMirror())->toBe(0);

    app(ProjectLocationMirror::class)->detachFile($project, $file);

    expect(DB::table('media')->where('mediable_type', 'proposal_location')->where('mediable_id', $row->id)->count())->toBe(0);
});

test('the mirror adds at most three queries to a project write', function () {
    [$project] = mirroredProject(['status' => ProjectStatus::Draft->value, 'start_date' => null, 'end_date' => null]);
    $manager = userWithRole('Managers');
    $this->actingAs($manager);

    $input = fn (string $name): array => [
        'name' => $name,
        'description' => 'Measured',
        'cityId' => $project->city_id,
        'centerId' => $project->center_id,
        'programId' => $project->program_id,
        'userId' => $project->user_id,
        'targetBeneficiaries' => 100,
        'targetMen' => 25,
        'targetWomen' => 25,
        'targetBoys' => 25,
        'targetGirls' => 25,
    ];

    // Warm every lazy lookup (HR tables, permissions) so the two runs differ
    // only by the mirror.
    app(SaveProject::class)->handle($project->fresh(), $input('Warm up'), $manager);

    $count = function (callable $run): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $queries = count(DB::getRawQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $withMirror = $count(fn () => app(SaveProject::class)->handle($project->fresh(), $input('With mirror'), $manager));

    // The same write with no row to mirror onto: the mirror returns after one
    // lookup and writes nothing.
    [$bare] = [Project::factory()->create([
        'user_id' => $project->user_id,
        'program_id' => $project->program_id,
        'status' => ProjectStatus::Draft->value,
    ])];
    app(SaveProject::class)->handle($bare->fresh(), $input('Warm bare'), $manager);
    $withoutRows = $count(fn () => app(SaveProject::class)->handle($bare->fresh(), $input('Without rows'), $manager));

    expect($withMirror - $withoutRows)->toBeLessThanOrEqual(3);
});

test('a deleted row takes its mirrored files and acknowledgement with it', function () {
    [$project, $row] = mirroredProject();

    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'created_at' => '2026-06-01']);
    app(ProjectLocationMirror::class)->apply($project);

    expect(DB::table('media')->where('mediable_type', 'proposal_location')->where('mediable_id', $row->id)->count())->toBe(1)
        ->and(DB::table('assignments')->where('assignable_type', 'proposal_location')->where('assignable_id', $row->id)->count())->toBe(1);

    ProposalLocation::query()->whereKey($row->id)->first()->delete();

    // Neither column carries a foreign key, so only the observers remove them.
    expect(DB::table('media')->where('mediable_type', 'proposal_location')->where('mediable_id', $row->id)->count())->toBe(0)
        ->and(DB::table('assignments')->where('assignable_type', 'proposal_location')->where('assignable_id', $row->id)->count())->toBe(0)
        ->and(DB::table('media')->where('mediable_type', 'project')->where('mediable_id', $project->id)->count())->toBe(1);
});
