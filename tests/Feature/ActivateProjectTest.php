<?php

use App\Actions\Projects\ActivateProject;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProjectActivated;
use App\Events\ProposalStatusChanged;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * An approved, dated project with an acknowledged owner, linked to an
 * approved proposal that holds the grant dates.
 *
 * @return array{project: Project, proposal: Proposal}
 */
function approvedProjectWithProposal(): array
{
    $project = Project::factory()->approved()->create(['start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();

    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Approved,
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
    ]);
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

    return ['project' => $project, 'proposal' => $proposal];
}

function activate(User $actor, Project $project, string $via = 'template_confirmation'): void
{
    test()->actingAs($actor);

    app(ActivateProject::class)->handle($project, $via);
}

test('activating an approved project makes it active and moves its approved proposals with one note each', function () {
    $actor = userWithRole('MEAL');
    ['project' => $project, 'proposal' => $proposal] = approvedProjectWithProposal();
    $later = Proposal::factory()->create(['status' => ProposalStatus::Approved, 'actual_date_begin' => '2027-01-01', 'actual_date_end' => '2027-12-31']);
    $later->projects()->attach($project->id, ['target_beneficiaries' => 50]);
    $submitted = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $submitted->projects()->attach($project->id, ['target_beneficiaries' => 10]);
    Event::fake([ProjectActivated::class, ProposalStatusChanged::class]);

    activate($actor, $project);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($project->fresh()->start_date->toDateString())->toBe('2026-11-01')
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Active)
        ->and($later->fresh()->status)->toBe(ProposalStatus::Active)
        ->and($submitted->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(1)
        ->and($proposal->statusNotes()->sole()->changed_by)->toBe($actor->id)
        ->and($later->statusNotes()->count())->toBe(1)
        ->and($submitted->statusNotes()->count())->toBe(0);

    Event::assertDispatchedTimes(ProjectActivated::class, 1);
    Event::assertDispatched(ProjectActivated::class, fn (ProjectActivated $event): bool => $event->projectId === $project->id
        && $event->proposalId === $proposal->id
        && $event->startDate->toDateString() === '2026-11-01'
        && $event->endDate->toDateString() === '2027-10-31'
        && $event->via === 'template_confirmation');
    Event::assertDispatchedTimes(ProposalStatusChanged::class, 2);
    Event::assertDispatched(ProposalStatusChanged::class, fn (ProposalStatusChanged $event): bool => $event->proposalId === $proposal->id
        && $event->from === ProposalStatus::Approved && $event->to === ProposalStatus::Active && $event->actorId === $actor->id);
});

test('a project with no approved proposal activates with a null proposal id', function () {
    ['project' => $project, 'proposal' => $proposal] = approvedProjectWithProposal();
    $proposal->forceFill(['status' => ProposalStatus::Active])->save();
    Event::fake([ProjectActivated::class]);

    activate(userWithRole('MEAL'), $project);

    Event::assertDispatched(ProjectActivated::class, fn (ProjectActivated $event): bool => $event->proposalId === null);
});

test('a project that is not approved is refused with no write and no event', function (ProjectStatus $status) {
    ['project' => $project, 'proposal' => $proposal] = approvedProjectWithProposal();
    $project->forceFill(['status' => $status])->save();
    Event::fake([ProjectActivated::class, ProposalStatusChanged::class]);

    try {
        activate(userWithRole('MEAL'), $project);
        $refused = false;
    } catch (ValidationException $exception) {
        $refused = $exception->errors()['status'][0] === __('projects.activate_not_approved');
    }

    expect($refused)->toBeTrue()
        ->and($project->fresh()->status)->toBe($status)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($proposal->statusNotes()->count())->toBe(0);

    Event::assertNotDispatched(ProjectActivated::class);
    Event::assertNotDispatched(ProposalStatusChanged::class);
})->with([
    'draft' => ProjectStatus::Draft,
    'active' => ProjectStatus::Active,
    'closed' => ProjectStatus::Closed,
    'archived' => ProjectStatus::Archived,
]);

test('an owner who has not acknowledged blocks activation', function () {
    ['project' => $project] = approvedProjectWithProposal();
    $project->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Pending])->save();

    expect(fn () => activate(userWithRole('MEAL'), $project))->toThrow(ValidationException::class);
    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('a project without dates cannot be activated', function () {
    ['project' => $project] = approvedProjectWithProposal();
    $project->forceFill(['start_date' => null, 'end_date' => null])->save();

    expect(fn () => activate(userWithRole('MEAL'), $project))->toThrow(ValidationException::class);
    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('only report-template managers may activate', function () {
    ['project' => $project, 'proposal' => $proposal] = approvedProjectWithProposal();
    Event::fake([ProjectActivated::class]);

    expect(fn () => activate(userWithRole('Financial'), $project))->toThrow(AuthorizationException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved);

    Event::assertNotDispatched(ProjectActivated::class);
});

test('a second call finds the project active and raises no second event', function () {
    $actor = userWithRole('MEAL');
    ['project' => $project] = approvedProjectWithProposal();
    Event::fake([ProjectActivated::class]);

    activate($actor, $project);

    expect(fn () => activate($actor, $project->fresh()))->toThrow(ValidationException::class);

    Event::assertDispatchedTimes(ProjectActivated::class, 1);
});

test('an unknown via is rejected', function () {
    ['project' => $project] = approvedProjectWithProposal();

    expect(fn () => activate(userWithRole('MEAL'), $project, 'manual'))->toThrow(InvalidArgumentException::class);
    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('a failure after activation in the caller rolls everything back and dispatches nothing', function () {
    $actor = userWithRole('MEAL');
    ['project' => $project, 'proposal' => $proposal] = approvedProjectWithProposal();
    Event::fake([ProjectActivated::class, ProposalStatusChanged::class]);

    try {
        DB::transaction(function () use ($actor, $project): void {
            activate($actor, $project);

            throw new RuntimeException('ConfirmReportTemplate failed afterwards.');
        });
    } catch (RuntimeException) {
    }

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved);

    Event::assertNotDispatched(ProjectActivated::class);
    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('activation records project.activated with its via', function () {
    ['project' => $project, 'proposal' => $proposal] = approvedProjectWithProposal();

    activate(userWithRole('MEAL'), $project);

    $activity = DB::table('activities')->where('subject_type', 'project')->where('subject_id', $project->id)->where('event', 'project.activated')->first();

    expect($activity)->not->toBeNull()
        ->and(json_decode($activity->properties, true)['via'])->toBe('template_confirmation')
        ->and(json_decode($activity->properties, true)['proposal_id'])->toBe($proposal->id);
});
