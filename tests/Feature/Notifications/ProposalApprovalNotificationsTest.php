<?php

use App\Actions\Proposals\ApproveProposal;
use App\Enums\AssignmentStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Notifications\RecipientResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return list<int>
 */
function approvalRecipients(Activity $activity): array
{
    $ids = array_keys(app(RecipientResolver::class)->resolve($activity)->priorities);
    sort($ids);

    return $ids;
}

/**
 * A project activity written by hand, so only the resolver is under test.
 */
function fundingEvent(Project $project, string $event, ?int $actorId): Activity
{
    return Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actorId,
        'subject_type' => 'project',
        'subject_id' => $project->id,
        'program_id' => $project->program_id,
        'module' => 'projects',
        'event' => $event,
        'changes' => [],
        'properties' => ['label' => $project->name, 'notify' => true],
    ]));
}

test('project.approved and project.activated reach the owner, the program manager, the MEAL Manager and the PR Manager, and not a bystander', function (string $event) {
    $managerEmployee = insertHrEmployee();
    $ownerEmployee = insertHrEmployee();
    $programManager = userWithRole('Team Member', $managerEmployee);
    $owner = userWithRole('Team Member', $ownerEmployee);
    $prManager = userWithRole('Public Relations Manager');
    $bystander = userWithRole('Media Team');
    $actor = userWithRole('Financial');

    $project = Activity::withoutRecording(fn () => Project::factory()->create([
        'user_id' => $ownerEmployee,
        'program_id' => Program::factory()->create(['manager_id' => $managerEmployee])->id,
    ]));

    $ids = approvalRecipients(fundingEvent($project, $event, $actor->id));

    expect($ids)->toContain($owner->id, $programManager->id, $prManager->id)
        ->not->toContain($bystander->id)
        ->not->toContain($actor->id);
})->with(['project.approved', 'project.activated']);

test('approving writes the approved events on the proposal and none carries an amount', function () {
    $actor = userWithRole('Financial');
    $watcher = userWithRole('Public Relations Manager');
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Submitted,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
        'requested_budget' => '987654.00',
    ]);
    $project = Project::factory()->create();
    $project->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 10]);

    $this->actingAs($actor);
    app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
        'decision_date' => '2026-10-13',
        'approval_note' => 'Donor email confirms the grant.',
    ]);

    $events = DB::table('activities')->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->pluck('event')->all();

    expect($events)->toContain('proposal.approved', 'proposal.approved_without_agreement');

    $payload = DB::table('activities')->whereIn('subject_id', [$proposal->id, $project->id])->get()->map(fn ($row) => $row->changes.$row->properties)->implode('');

    expect($payload)->not->toContain('987654');

    $approved = Activity::query()->where('subject_type', 'proposal')->where('event', 'proposal.approved_without_agreement')->sole();

    expect(approvalRecipients($approved))->toContain($watcher->id);
    expect(DB::table('notifications')->get()->map(fn ($row) => $row->data)->implode(''))->not->toContain('987654');
});

test('a rolled-back approval leaves no activity and no notification', function () {
    $actor = userWithRole('Financial');
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted, 'planned_date_begin' => '2026-11-01', 'planned_date_end' => '2027-10-31']);
    $project = Project::factory()->create();
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 10]);
    $before = Activity::query()->count();

    $this->actingAs($actor);

    try {
        DB::transaction(function () use ($proposal): void {
            app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
                'actual_date_begin' => '2026-11-01',
                'actual_date_end' => '2027-10-31',
                'decision_date' => '2026-10-13',
                'approval_note' => 'Donor email confirms the grant.',
            ]);

            throw new RuntimeException('Caller failed.');
        });
    } catch (RuntimeException) {
    }

    expect(Activity::query()->count())->toBe($before)
        ->and(Activity::query()->where('event', 'like', 'proposal.approved%')->exists())->toBeFalse();
});
