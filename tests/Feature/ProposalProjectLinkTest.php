<?php

use App\Actions\Projects\ReopenProjectAsDraft;
use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\UnlinkProposalProject;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();
    $this->actingAs($this->user);
    $this->proposal = Proposal::factory()->create(['name' => 'Winter Response']);
});

/**
 * Link the proposal straight through the pivot, as data already on file.
 */
function fileLink(Proposal $proposal, Project ...$projects): void
{
    foreach ($projects as $project) {
        DB::table('project_proposal')->insert(['project_id' => $project->id, 'proposal_id' => $proposal->id]);
    }
}

/**
 * The validation messages a refused call raised, keyed by field.
 *
 * @return array<string, list<string>>
 */
function refusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

/**
 * A target this proposal committed on the project, optionally reported.
 */
function proposalTarget(Proposal $proposal, Project $project, bool $reported = false): PlanIndicator
{
    $target = PlanIndicator::factory()->forProposal($proposal)->create(['project_id' => $project->id]);

    if ($reported) {
        Report::factory()->create(['plan_indicator_id' => $target->id]);
    }

    return $target;
}

function linkedIds(Proposal $proposal): array
{
    return DB::table('project_proposal')->where('proposal_id', $proposal->id)->orderBy('project_id')->pluck('project_id')->all();
}

test('the proposal page links a project through the action and records its name, never an amount', function () {
    $project = Project::factory()->active()->create(['name' => 'Aleppo Clinics']);

    Livewire::test('pages::proposals.show', ['proposal' => $this->proposal])
        ->set('projectToLink', (string) $project->id)
        ->call('linkProject')
        ->assertHasNoErrors();

    expect(DB::table('project_proposal')->where('proposal_id', $this->proposal->id)->sole())
        ->project_id->toBe($project->id)
        ->target_beneficiaries->toBeNull()
        ->and(Activity::query()->where('event', 'proposal.project_linked')->sole())
        ->subject_type->toBe('proposal')
        ->subject_id->toBe($this->proposal->id)
        ->properties->toEqual(['label' => 'Winter Response', 'notify' => true, 'project_id' => $project->id, 'project' => 'Aleppo Clinics']);
});

test('the proposal page unlinks a project through the action and records its name', function () {
    $kept = Project::factory()->create();
    $dropped = Project::factory()->create(['name' => 'Homs Schools']);
    fileLink($this->proposal, $kept, $dropped);

    Livewire::test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('confirmUnlinkProject', $dropped->id)
        ->assertSet('showUnlinkModal', true)
        ->call('unlinkProject')
        ->assertHasNoErrors()
        ->assertSet('showUnlinkModal', false);

    expect(linkedIds($this->proposal))->toBe([$kept->id])
        ->and(Activity::query()->where('event', 'proposal.project_unlinked')->sole()->properties)
        ->toEqual(['label' => 'Winter Response', 'notify' => true, 'project_id' => $dropped->id, 'project' => 'Homs Schools']);
});

test('the project page links and unlinks a proposal through the same actions', function () {
    $project = Project::factory()->active()->create();
    $other = Project::factory()->create();
    fileLink($this->proposal, $other);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->assertSee('Winter Response')
        ->set('proposalToAttach', (string) $this->proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect(linkedIds($this->proposal))->toBe([min($project->id, $other->id), max($project->id, $other->id)])
        ->and(Activity::query()->where('event', 'proposal.project_linked')->count())->toBe(1);

    Livewire::test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->call('detachProposal', $this->proposal->id)
        ->assertHasNoErrors();

    expect(linkedIds($this->proposal))->toBe([$other->id])
        ->and(Activity::query()->where('event', 'proposal.project_unlinked')->count())->toBe(1);
});

test('the project page needs proposals.update, not projects.update, to link or unlink', function () {
    $manager = User::factory()->role('Managers')->create();
    $project = Project::factory()->active()->create();
    fileLink($this->proposal, $project, Project::factory()->create());
    $unlinked = Proposal::factory()->create();
    $fresh = fn () => Livewire::actingAs($manager)->test('pages::projects.show', ['project' => $project]);

    expect($fresh()->instance()->attachableProposals)->toBeEmpty();

    $fresh()->set('proposalToAttach', (string) $unlinked->id)->call('attachProposal')->assertForbidden();
    $fresh()->call('detachProposal', $this->proposal->id)->assertForbidden();

    expect($unlinked->projects()->count())->toBe(0)
        ->and($this->proposal->projects()->count())->toBe(2);
});

test('the project page offers no proposal while the project itself cannot be linked', function () {
    $project = Project::factory()->status(ProjectStatus::Archived)->create();

    expect(Livewire::test('pages::projects.show', ['project' => $project])->instance()->attachableProposals)->toBeEmpty();
});

test('the proposal page refuses linking and unlinking to a user without proposals.update', function () {
    $viewer = User::factory()->role('Team Member')->create()->givePermissionTo('proposals.view');
    $project = Project::factory()->create();
    fileLink($this->proposal, $project, Project::factory()->create());
    $candidate = Project::factory()->create();
    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::proposals.show', ['proposal' => $this->proposal]);

    $fresh()->set('projectToLink', (string) $candidate->id)->call('linkProject')->assertForbidden();
    $fresh()->call('confirmUnlinkProject', $project->id)->assertForbidden();

    expect($this->proposal->projects()->count())->toBe(2);
});

test('each action refuses a user without proposals.update', function (Closure $call) {
    $this->actingAs(User::factory()->role('Managers')->create());
    $closed = Project::factory()->closed()->create();
    $linked = Project::factory()->create();
    fileLink($this->proposal, $linked, Project::factory()->create());

    expect(fn () => $call($this->proposal, $closed, $linked))->toThrow(AuthorizationException::class)
        ->and($this->proposal->projects()->count())->toBe(2)
        ->and($closed->fresh()->status)->toBe(ProjectStatus::Closed);
})->with([
    'link' => fn (Proposal $proposal, Project $closed) => app(LinkProposalProject::class)->handle($proposal, $closed->id),
    'unlink' => fn (Proposal $proposal, Project $closed, Project $linked) => app(UnlinkProposalProject::class)->handle($proposal, $linked->id),
    'reopen as draft' => fn (Proposal $proposal, Project $closed) => app(ReopenProjectAsDraft::class)->handle($closed),
]);

test('the last linked project cannot be unlinked', function () {
    $project = Project::factory()->create();
    fileLink($this->proposal, $project);

    Livewire::test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('confirmUnlinkProject', $project->id)
        ->call('unlinkProject')
        ->assertHasErrors(['project' => __('proposals.the_last_linked_project_cannot_be_unlinked')])
        ->assertSet('showUnlinkModal', true);

    expect(linkedIds($this->proposal))->toBe([$project->id])
        ->and(Activity::query()->where('event', 'proposal.project_unlinked')->exists())->toBeFalse();
});

test('a project carrying reported targets of this proposal cannot be unlinked, while reports under another proposal do not block it', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();
    fileLink($this->proposal, $project, $other);
    proposalTarget(Proposal::factory()->create(), $project, reported: true);
    proposalTarget($this->proposal, $other, reported: true);

    app(UnlinkProposalProject::class)->handle($this->proposal, $project->id);
    fileLink($this->proposal, $project);
    proposalTarget($this->proposal, $project, reported: true);

    expect(refusal(fn () => app(UnlinkProposalProject::class)->handle($this->proposal, $project->id)))
        ->toBe(['project' => [__('proposals.this_project_has_reported_targets_in_this_proposal')]])
        ->and(linkedIds($this->proposal))->toContain($project->id);
});

test('the unlink confirmation counts the unreported targets, which stay after the unlink', function () {
    $project = Project::factory()->create(['name' => 'Latakia Wells']);
    fileLink($this->proposal, $project, Project::factory()->create());
    proposalTarget($this->proposal, $project);
    proposalTarget($this->proposal, $project);

    Livewire::test('pages::proposals.show', ['proposal' => $this->proposal])
        ->set('tab', 'projects')
        ->call('confirmUnlinkProject', $project->id)
        ->assertSet('unlinkUnreportedTargetCount', 2)
        ->assertSeeText(trans_choice('proposals.unlink_project_unreported_targets', 2, ['count' => 2, 'name' => 'Latakia Wells']))
        ->call('unlinkProject')
        ->assertHasNoErrors();

    expect(linkedIds($this->proposal))->not->toContain($project->id)
        ->and(PlanIndicator::query()->where('source_id', $this->proposal->id)->where('project_id', $project->id)->count())->toBe(2);
});

test('unlinking a project the proposal does not fund is not found', function () {
    $foreign = Project::factory()->create();
    fileLink(Proposal::factory()->create(), $foreign);
    fileLink($this->proposal, Project::factory()->create(), Project::factory()->create());

    expect(fn () => app(UnlinkProposalProject::class)->handle($this->proposal, $foreign->id))
        ->toThrow(ModelNotFoundException::class)
        ->and($foreign->proposals()->count())->toBe(1);
});

test('linking a closed project reopens it as a draft without dates, keeping the history in the activity log', function () {
    $centerId = insertHrCenter(insertHrCity());
    $project = Project::factory()->closed()->create(['center_id' => $centerId, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    app(LinkProposalProject::class)->handle($this->proposal, $project->id, 40);

    expect($project->fresh())
        ->status->toBe(ProjectStatus::Draft)
        ->start_date->toBeNull()
        ->end_date->toBeNull()
        ->and($this->proposal->projects()->sole()->pivot->target_beneficiaries)->toBe(40)
        ->and(Activity::query()->where('subject_type', 'project')->where('subject_id', $project->id)->where('event', 'project.status_changed')->sole()->changes)
        ->toMatchArray(['status' => ['closed', 'draft'], 'start_date' => ['2025-01-01', null], 'end_date' => ['2025-12-31', null]]);
});

test('a closed project without a center is reopened without the open-project check', function () {
    $program = Program::factory()->create();
    Project::factory()->active()->create(['center_id' => null, 'program_id' => $program->id]);
    $project = Project::factory()->closed()->create(['center_id' => null, 'program_id' => $program->id]);

    app(LinkProposalProject::class)->handle($this->proposal, $project->id);

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and(linkedIds($this->proposal))->toBe([$project->id]);
});

test('a closed project is refused when its center already runs an open project for that program', function () {
    $centerId = insertHrCenter(insertHrCity());
    $program = Program::factory()->create();
    Project::factory()->active()->create(['name' => 'Idlib Hope Center', 'center_id' => $centerId, 'program_id' => $program->id]);
    $project = Project::factory()->closed()->create(['center_id' => $centerId, 'program_id' => $program->id]);

    expect(refusal(fn () => app(LinkProposalProject::class)->handle($this->proposal, $project->id)))
        ->toBe(['projectIds' => [__('projects.open_project_exists', ['name' => 'Idlib Hope Center'])]])
        ->and($project->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and(linkedIds($this->proposal))->toBe([]);
});

test('a project that cannot be linked is refused and never offered', function (Closure $makeProjectId) {
    $projectId = $makeProjectId();

    expect(refusal(fn () => app(LinkProposalProject::class)->handle($this->proposal, $projectId)))
        ->toBe(['projectIds' => [__('proposals.that_project_is_no_longer_available')]])
        ->and(linkedIds($this->proposal))->toBe([])
        ->and(Livewire::test('pages::proposals.show', ['proposal' => $this->proposal])->instance()->linkableProjects->pluck('id')->all())
        ->not->toContain($projectId);
})->with([
    'archived' => fn (): int => Project::factory()->status(ProjectStatus::Archived)->create()->id,
    'owner-rejected' => function (): int {
        $project = Project::factory()->active()->create();
        Activity::withoutRecording(fn () => Assignment::query()->create([
            'assignable_type' => 'project',
            'assignable_id' => $project->id,
            'field' => 'user_id',
            'employee_id' => $project->user_id,
            'assigned_by' => test()->user->id,
            'status' => AssignmentStatus::Rejected,
        ]));

        return $project->id;
    },
    'missing' => fn (): int => 999999,
]);

test('the proposal page shows a refused link under the project picker', function () {
    $archived = Project::factory()->status(ProjectStatus::Archived)->create();

    Livewire::test('pages::proposals.show', ['proposal' => $this->proposal])
        ->set('projectToLink', (string) $archived->id)
        ->call('linkProject')
        ->assertHasErrors(['projectToLink' => __('proposals.that_project_is_no_longer_available')]);
});

test('beneficiaries below one are refused', function () {
    $project = Project::factory()->create();

    expect(refusal(fn () => app(LinkProposalProject::class)->handle($this->proposal, $project->id, 0)))
        ->toBe(['beneficiaries' => [__('proposals.enter_at_least_one_beneficiary')]])
        ->and(linkedIds($this->proposal))->toBe([]);
});

test('linking a project already on the proposal changes nothing', function () {
    $project = Project::factory()->create();
    DB::table('project_proposal')->insert(['project_id' => $project->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 12]);

    app(LinkProposalProject::class)->handle($this->proposal, $project->id, 50);

    expect(DB::table('project_proposal')->where('proposal_id', $this->proposal->id)->sole()->target_beneficiaries)->toBe(12)
        ->and(Activity::query()->where('event', 'proposal.project_linked')->exists())->toBeFalse();
});

test('the second link of the same closed project finds it a draft and just links', function () {
    $project = Project::factory()->closed()->create(['center_id' => insertHrCenter(insertHrCity())]);
    $staleCopy = Project::query()->findOrFail($project->id);
    $second = Proposal::factory()->create();

    app(LinkProposalProject::class)->handle($this->proposal, $project->id);
    app(LinkProposalProject::class)->handle($second, $project->id);
    app(ReopenProjectAsDraft::class)->handle($staleCopy);

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($staleCopy->status)->toBe(ProjectStatus::Draft)
        ->and($project->proposals()->count())->toBe(2)
        ->and(Activity::query()->where('subject_type', 'project')->where('event', 'project.status_changed')->count())->toBe(1);
});
