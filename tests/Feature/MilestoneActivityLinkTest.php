<?php

use App\Actions\Milestones\LinkMilestoneToActivity;
use App\Enums\MilestoneStatus;
use App\Enums\ResultType;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->editor = User::factory()->role('Managers')->create();
    $this->actingAs($this->editor);

    $this->program = Program::factory()->create();
    $this->project = Project::factory()->create(['program_id' => $this->program->id]);

    $this->funding = Proposal::factory()->create();
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $this->funding->id]);
    $this->fundingActivity = milestoneLinkActivity($this->funding->id, $this->program->id, 'Run four irrigation trainings.');

    $this->programActivity = milestoneLinkActivity(null, $this->program->id, 'Hold the yearly program review.');
    $this->strangerActivity = milestoneLinkActivity(Proposal::factory()->create()->id, $this->program->id, 'Distribute winter kits elsewhere.');

    $this->milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
});

/**
 * An activity under a fresh output, in a proposal's framework or (null) the program framework.
 */
function milestoneLinkActivity(?int $proposalId, ?int $programId, string $statement = 'Run a training session.'): Result
{
    $output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $proposalId, 'program_id' => $programId]);

    return Result::factory()->activity($output)->create(['statement' => $statement]);
}

/**
 * The message a refused link raised on the action's `activity` key.
 */
function milestoneLinkRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors()['activity'][0] ?? null;
    }

    throw new RuntimeException('The link was not refused.');
}

function linkMilestone(Milestone $milestone, ?int $activityId): Milestone
{
    return app(LinkMilestoneToActivity::class)->handle($milestone, $activityId);
}

test('a milestone links to an activity of a proposal funding its project', function () {
    expect(linkMilestone($this->milestone, $this->fundingActivity->id)->result_id)->toBe($this->fundingActivity->id)
        ->and($this->milestone->refresh()->activity->is($this->fundingActivity))->toBeTrue()
        ->and($this->milestone->activityLinkIssues())->toBe([]);
});

test('a milestone links to an activity of its project program framework', function () {
    linkMilestone($this->milestone, $this->programActivity->id);

    expect($this->milestone->refresh()->result_id)->toBe($this->programActivity->id);
});

test('an activity of a proposal that does not fund the project is refused', function () {
    expect(milestoneLinkRefusal(fn () => linkMilestone($this->milestone, $this->strangerActivity->id)))
        ->toBe(__('milestones.milestone_activity'))
        ->and($this->milestone->refresh()->result_id)->toBeNull();
});

test('a result that is not an activity is refused', function () {
    expect(milestoneLinkRefusal(fn () => linkMilestone($this->milestone, $this->fundingActivity->parent_id)))
        ->toBe(__('milestones.milestone_activity'));
});

test('a proposal-level milestone links only to its own proposal activities', function () {
    $otherFunding = Proposal::factory()->create();
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $otherFunding->id]);
    $otherFundingActivity = milestoneLinkActivity($otherFunding->id, $this->program->id);
    DB::table('milestones')->where('id', $this->milestone->id)->update(['proposal_id' => $this->funding->id]);
    $milestone = $this->milestone->refresh();

    expect(milestoneLinkRefusal(fn () => linkMilestone($milestone, $otherFundingActivity->id)))->toBe(__('milestones.milestone_activity'))
        ->and(milestoneLinkRefusal(fn () => linkMilestone($milestone, $this->programActivity->id)))->toBe(__('milestones.milestone_activity'))
        ->and(linkMilestone($milestone, $this->fundingActivity->id)->result_id)->toBe($this->fundingActivity->id);
});

test('linking nothing clears the link', function () {
    linkMilestone($this->milestone, $this->fundingActivity->id);

    expect(linkMilestone($this->milestone, null)->result_id)->toBeNull()
        ->and($this->milestone->refresh()->result_id)->toBeNull();
});

test('unlinking the project from the proposal keeps the link and flags it', function () {
    linkMilestone($this->milestone, $this->fundingActivity->id);
    DB::table('project_proposal')->where('proposal_id', $this->funding->id)->delete();

    $milestone = $this->milestone->refresh();

    expect($milestone->result_id)->toBe($this->fundingActivity->id)
        ->and($milestone->activityLinkIssues())->toBe(['milestones.activity_link_not_funding']);
});

test('moving the project to another program flags a program-framework link', function () {
    linkMilestone($this->milestone, $this->programActivity->id);
    DB::table('projects')->where('id', $this->project->id)->update(['program_id' => Program::factory()->create()->id]);

    $milestone = $this->milestone->refresh();

    expect($milestone->result_id)->toBe($this->programActivity->id)
        ->and($milestone->activityLinkIssues())->toBe(['milestones.activity_link_program_moved']);
});

test('linking needs the right to edit the milestone', function () {
    $this->actingAs(User::factory()->role('Team Member')->create());

    expect(fn () => linkMilestone($this->milestone, $this->fundingActivity->id))->toThrow(AuthorizationException::class)
        ->and($this->milestone->refresh()->result_id)->toBeNull();
});

test('an activity removed meanwhile is refused, not a server error', function () {
    $activityId = $this->fundingActivity->id;
    $this->fundingActivity->delete();

    expect(milestoneLinkRefusal(fn () => linkMilestone($this->milestone, $activityId)))->toBe(__('milestones.milestone_activity'));
});

test('the milestone page links an activity and refuses a foreign one on the field', function () {
    Livewire::actingAs($this->editor)
        ->test('pages::milestones.show', ['milestone' => $this->milestone])
        ->assertSee($this->fundingActivity->statement)
        ->assertDontSee($this->strangerActivity->statement)
        ->set('milestoneActivityId', (string) $this->strangerActivity->id)
        ->call('updateMilestone')
        ->assertHasErrors(['milestoneActivityId' => __('milestones.milestone_activity')])
        ->set('milestoneActivityId', (string) $this->fundingActivity->id)
        ->call('updateMilestone')
        ->assertHasNoErrors();

    expect($this->milestone->refresh()->result_id)->toBe($this->fundingActivity->id);
});

test('the milestone page shows a flagged link without blocking other edits', function () {
    linkMilestone($this->milestone, $this->fundingActivity->id);
    DB::table('project_proposal')->where('proposal_id', $this->funding->id)->delete();

    Livewire::actingAs($this->editor)
        ->test('pages::milestones.show', ['milestone' => $this->milestone])
        ->assertSee(__('milestones.activity_link_not_funding'))
        ->set('name', 'Renamed milestone')
        ->call('updateMilestone')
        ->assertHasNoErrors();

    expect($this->milestone->refresh())
        ->name->toBe('Renamed milestone')
        ->result_id->toBe($this->fundingActivity->id);
});

test('a related milestone is created with its activity', function () {
    Livewire::actingAs($this->editor)
        ->test('pages::milestones.show', ['milestone' => $this->milestone])
        ->call('openRelatedModal')
        ->set('relatedName', 'Training session two')
        ->set('relatedPlannedStartDate', '2026-11-02')
        ->set('relatedResponsibleUserId', (string) insertHrEmployee())
        ->set('relatedActivityId', (string) $this->fundingActivity->id)
        ->call('saveRelatedMilestone')
        ->assertHasNoErrors();

    expect(Milestone::query()->where('name', 'Training session two')->sole()->result_id)->toBe($this->fundingActivity->id);
});

test('the milestone directory creates a milestone with its activity and rolls back a refused one', function () {
    $create = fn (string $name, Result $activity) => Livewire::actingAs($this->editor)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('milestoneProjectId', (string) $this->project->id)
        ->set('name', $name)
        ->set('responsibleUserId', (string) insertHrEmployee())
        ->set('milestoneStatus', MilestoneStatus::Planned->value)
        ->set('plannedStartDate', '2026-11-02')
        ->set('milestoneActivityId', (string) $activity->id)
        ->call('saveMilestone');

    $create('Linked delivery', $this->programActivity)->assertHasNoErrors();
    $create('Foreign delivery', $this->strangerActivity)->assertHasErrors(['milestoneActivityId' => __('milestones.milestone_activity')]);

    expect(Milestone::query()->where('name', 'Linked delivery')->sole()->result_id)->toBe($this->programActivity->id)
        ->and(Milestone::query()->where('name', 'Foreign delivery')->exists())->toBeFalse();
});

test('the calendar creates a milestone with its activity', function () {
    Livewire::actingAs($this->editor)
        ->test('pages::calendar.index')
        ->call('openCreateModal', '2026-11-02')
        ->set('milestoneProjectId', (string) $this->project->id)
        ->set('name', 'Calendar delivery')
        ->set('responsibleUserId', (string) insertHrEmployee())
        ->set('milestoneActivityId', (string) $this->fundingActivity->id)
        ->call('saveMilestone')
        ->assertHasNoErrors();

    expect(Milestone::query()->where('name', 'Calendar delivery')->sole()->result_id)->toBe($this->fundingActivity->id);
});

test('the batch link check flags exactly what the per-milestone check flags', function () {
    $fine = Milestone::factory()->create(['project_id' => $this->project->id, 'result_id' => $this->fundingActivity->id]);
    $programLink = Milestone::factory()->create(['project_id' => $this->project->id, 'result_id' => $this->programActivity->id]);
    $notFunding = Milestone::factory()->create(['project_id' => $this->project->id, 'result_id' => $this->strangerActivity->id]);
    $notActivity = Milestone::factory()->create(['project_id' => $this->project->id, 'result_id' => $this->fundingActivity->parent_id]);
    $ownProposal = Milestone::factory()->create(['project_id' => $this->project->id, 'proposal_id' => $this->funding->id, 'result_id' => $this->programActivity->id]);
    $movedProject = Project::factory()->create(['program_id' => Program::factory()->create()->id]);
    $moved = Milestone::factory()->create(['project_id' => $movedProject->id, 'result_id' => $this->programActivity->id]);
    $unlinked = $this->milestone;

    $milestones = Milestone::query()->with('project')->orderBy('id')->get();

    expect(Milestone::activityLinkIssuesFor($milestones))
        ->toBe($milestones->mapWithKeys(fn (Milestone $milestone): array => [$milestone->id => $milestone->activityLinkIssues()])->all())
        ->and(Milestone::activityLinkIssuesFor($milestones)[$fine->id])->toBe([])
        ->and(Milestone::activityLinkIssuesFor($milestones)[$programLink->id])->toBe([])
        ->and(Milestone::activityLinkIssuesFor($milestones)[$unlinked->id])->toBe([])
        ->and(Milestone::activityLinkIssuesFor($milestones)[$notFunding->id])->toBe(['milestones.activity_link_not_funding'])
        ->and(Milestone::activityLinkIssuesFor($milestones)[$notActivity->id])->toBe(['milestones.activity_link_not_activity'])
        ->and(Milestone::activityLinkIssuesFor($milestones)[$ownProposal->id])->toBe(['milestones.activity_link_program_moved'])
        ->and(Milestone::activityLinkIssuesFor($milestones)[$moved->id])->toBe(['milestones.activity_link_program_moved']);
});

test('the batch link check reads a fixed number of queries', function () {
    $count = function (int $milestones): int {
        Milestone::query()->delete();
        Milestone::factory()->count($milestones)->create(['project_id' => $this->project->id, 'result_id' => $this->strangerActivity->id]);
        $loaded = Milestone::query()->with('project')->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Milestone::activityLinkIssuesFor($loaded);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(2))->toBe($count(12))->toBeLessThanOrEqual(3);
});

test('weighted progress counts reached weight over every weight that still counts', function () {
    $make = fn (MilestoneStatus $status, string $weight): Milestone => Milestone::factory()->make(['status' => $status, 'weight_percentage' => $weight]);

    expect(Milestone::weightedProgress([
        $make(MilestoneStatus::Reached, '30'),
        $make(MilestoneStatus::Planned, '50'),
        $make(MilestoneStatus::Cancelled, '20'),
    ]))->toBe(37.5)
        ->and(Milestone::weightedProgress([$make(MilestoneStatus::Cancelled, '40'), $make(MilestoneStatus::Planned, '0')]))->toBeNull()
        ->and(Milestone::weightedProgress([]))->toBeNull()
        ->and(Milestone::weightedProgress([$make(MilestoneStatus::Reached, '60'), $make(MilestoneStatus::Ongoing, '40')]))->toBe(60.0);
});
