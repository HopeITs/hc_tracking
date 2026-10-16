<?php

use App\Actions\ProposalPlan\RebaseProposalPlan;
use App\Actions\Proposals\ApproveProposal;
use App\Enums\AssignmentStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalPlanShift;
use App\Models\ProposalStatusNote;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * A submitted proposal planned from 2026-11-01 to 2027-10-31, linked to one
 * project whose owner has acknowledged it, so approving it goes through.
 */
function rebaseTestProposal(): Proposal
{
    return Activity::withoutRecording(function (): Proposal {
        $proposal = Proposal::factory()->create([
            'status' => ProposalStatus::Submitted,
            'planned_date_begin' => '2026-11-01',
            'planned_date_end' => '2027-10-31',
        ]);
        $project = Project::factory()->create();
        $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

        return $proposal;
    });
}

/**
 * A proposal-level milestone on the plan with the given planned dates.
 *
 * @param  array<string, mixed>  $attributes
 */
function rebaseTestMilestone(Proposal $proposal, ?string $start, ?string $end, array $attributes = []): Milestone
{
    return Activity::withoutRecording(fn (): Milestone => Milestone::factory()->forProposal($proposal)->create([
        'planned_start_date' => $start,
        'planned_end_date' => $end,
        ...$attributes,
    ]));
}

/**
 * A department task raised on the proposal with the given dates.
 *
 * @param  array<string, mixed>  $attributes
 */
function rebaseTestTask(Proposal $proposal, ?string $start, ?string $due, array $attributes = []): Task
{
    return Activity::withoutRecording(fn (): Task => Task::factory()->forProposal($proposal)->create([
        'start_date' => $start,
        'due_date' => $due,
        ...$attributes,
    ]));
}

/**
 * Record the donor approval through the real action, signed in as a Finance
 * user (department-scoped for tasks, so the rebase must not rely on the
 * signed-in user's task visibility).
 */
function rebaseTestApprove(Proposal $proposal, string $grantBegin = '2027-01-01', string $grantEnd = '2027-09-30'): void
{
    test()->actingAs(userWithRole('Financial'));

    app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
        'actual_date_begin' => $grantBegin,
        'actual_date_end' => $grantEnd,
        'decision_date' => '2026-10-13',
        'approval_note' => 'The donor confirmed the grant by email on 12 October.',
    ]);
}

/**
 * A milestone's stored planned dates, read past every cast.
 *
 * @return array{0: string|null, 1: string|null}
 */
function rebaseTestMilestoneDates(Milestone $milestone): array
{
    $row = DB::table('milestones')->find($milestone->id);

    return [$row->planned_start_date, $row->planned_end_date];
}

/**
 * A task's stored start and due dates, read past every cast.
 *
 * @return array{0: string|null, 1: string|null}
 */
function rebaseTestTaskDates(Task $task): array
{
    $row = DB::table('tasks')->find($task->id);

    return [$row->start_date, $row->due_date];
}

/**
 * The one rebase activity recorded on the proposal.
 */
function rebaseTestActivity(Proposal $proposal): Activity
{
    return Activity::query()->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->where('event', 'proposal.plan_rebased')->sole();
}

/**
 * The users whose inbox holds the given activity.
 *
 * @return list<int>
 */
function rebaseTestNotifiedUserIds(Activity $activity): array
{
    return UserNotification::query()->whereJsonContains('data->activity_ids', $activity->id)->pluck('notifiable_id')->map(fn (int|string $id): int => (int) $id)->all();
}

test('approval moves the plan to the grant period once and cuts dates at the grant end', function () {
    $proposal = rebaseTestProposal();
    $early = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');
    $late = rebaseTestMilestone($proposal, '2027-09-01', '2027-10-31');
    $proposalTask = rebaseTestTask($proposal, '2026-11-10', '2026-12-15');
    // A general task on a plan milestone: the approver cannot see it, so the rebase must not read tasks as them.
    $milestoneTask = Activity::withoutRecording(fn (): Task => Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $early->id,
        'start_date' => null,
        'due_date' => '2027-10-15',
    ]));

    rebaseTestApprove($proposal);

    $approvalNote = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->where('status', ProposalStatus::Approved)->sole();
    $shift = DB::table('proposal_plan_shifts')->where('proposal_id', $proposal->id)->sole();

    expect(rebaseTestMilestoneDates($early))->toBe(['2027-01-01', '2027-01-30'])
        ->and(rebaseTestMilestoneDates($late))->toBe(['2027-09-30', '2027-09-30'])
        ->and(rebaseTestTaskDates($proposalTask))->toBe(['2027-01-10', '2027-02-14'])
        ->and(rebaseTestTaskDates($milestoneTask))->toBe([null, '2027-09-30'])
        ->and($shift->approval_note_id)->toBe($approvalNote->id)
        ->and($shift->delta_days)->toBe(61)
        ->and($shift->shifted)->toBe(4)
        ->and(DB::table('milestones')->whereNotNull('planned_dates_edited_at')->exists())->toBeFalse()
        ->and(DB::table('tasks')->whereNotNull('planned_dates_edited_at')->exists())->toBeFalse();
});

test('the system logs the rebase with the cut rows and tells the project owner', function () {
    $proposal = rebaseTestProposal();
    $project = $proposal->projects()->sole();
    $owner = userWithRole('Managers', (int) $project->user_id);
    rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');
    $late = rebaseTestMilestone($proposal, '2027-09-01', '2027-10-31', ['name' => 'Endline survey']);
    $lateTask = rebaseTestTask($proposal, null, '2027-10-15', ['title' => 'Print the endline banners']);

    rebaseTestApprove($proposal);

    $activity = rebaseTestActivity($proposal);

    expect($activity->actor_id)->toBeNull()
        ->and($activity->module)->toBe('proposals')
        ->and($activity->properties['delta_days'])->toBe(61)
        ->and($activity->properties['shifted'])->toBe(3)
        ->and($activity->properties['clipped_milestones'])->toEqual([['id' => $late->id, 'name' => 'Endline survey']])
        ->and($activity->properties['clipped_tasks'])->toEqual([['id' => $lateTask->id, 'name' => 'Print the endline banners']])
        ->and($activity->properties['project_id'])->toBe($project->id)
        ->and(rebaseTestNotifiedUserIds($activity))->toBe([$owner->id])
        ->and(sentencesFor($owner))->toContain("The plan of “{$proposal->name}” moved to the grant period; milestones and tasks cut at the grant end: 2");
});

test('nobody is told when no date reaches past the grant end', function () {
    $proposal = rebaseTestProposal();
    userWithRole('Managers', (int) $proposal->projects()->sole()->user_id);
    $milestone = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');

    rebaseTestApprove($proposal, grantEnd: '2027-12-31');

    $activity = rebaseTestActivity($proposal);

    expect(rebaseTestMilestoneDates($milestone))->toBe(['2027-01-01', '2027-01-30'])
        ->and($activity->properties['clipped_milestones'])->toBe([])
        ->and($activity->properties['clipped_tasks'])->toBe([])
        ->and(rebaseTestNotifiedUserIds($activity))->toBe([]);
});

test('a second approval event or a direct re-run shifts nothing', function () {
    $proposal = rebaseTestProposal();
    $milestone = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');
    $task = rebaseTestTask($proposal, '2026-11-10', '2026-12-15');
    rebaseTestApprove($proposal);
    $approvalNote = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->where('status', ProposalStatus::Approved)->sole();

    ProposalStatusChanged::dispatch($proposal->id, ProposalStatus::Submitted, ProposalStatus::Approved, null);
    $rerun = app(RebaseProposalPlan::class)->handle($proposal->fresh(), $approvalNote);

    expect($rerun)->toBe(['shifted' => 0, 'clipped' => [], 'skipped' => true])
        ->and(rebaseTestMilestoneDates($milestone))->toBe(['2027-01-01', '2027-01-30'])
        ->and(rebaseTestTaskDates($task))->toBe(['2027-01-10', '2027-02-14'])
        ->and(ProposalPlanShift::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'proposal.plan_rebased')->count())->toBe(1);
});

test('dates a user moved and the plans of other proposals keep their dates', function () {
    $proposal = rebaseTestProposal();
    $edited = rebaseTestMilestone($proposal, '2026-12-01', '2026-12-20', ['planned_dates_edited_at' => '2026-10-10 09:00:00']);
    $editedTask = rebaseTestTask($proposal, '2026-12-01', '2026-12-05', ['planned_dates_edited_at' => '2026-10-10 09:00:00']);
    $moved = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');
    $other = rebaseTestProposal();
    $otherMilestone = rebaseTestMilestone($other, '2026-11-01', '2026-11-30');
    $otherTask = rebaseTestTask($other, '2026-11-10', '2026-12-15');

    rebaseTestApprove($proposal);

    expect(rebaseTestMilestoneDates($edited))->toBe(['2026-12-01', '2026-12-20'])
        ->and(rebaseTestTaskDates($editedTask))->toBe(['2026-12-01', '2026-12-05'])
        ->and(rebaseTestMilestoneDates($moved))->toBe(['2027-01-01', '2027-01-30'])
        ->and(rebaseTestMilestoneDates($otherMilestone))->toBe(['2026-11-01', '2026-11-30'])
        ->and(rebaseTestTaskDates($otherTask))->toBe(['2026-11-10', '2026-12-15'])
        ->and(DB::table('proposal_plan_shifts')->sole()->shifted)->toBe(1);
});

test('a move to any other status leaves the plan alone', function () {
    $proposal = rebaseTestProposal();
    $milestone = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');
    Activity::withoutRecording(function () use ($proposal): void {
        $proposal->forceFill(['actual_date_begin' => '2027-01-01', 'actual_date_end' => '2027-09-30'])->save();
        ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Approved]);
    });

    ProposalStatusChanged::dispatch($proposal->id, ProposalStatus::Submitted, ProposalStatus::OnHold, null);

    expect(rebaseTestMilestoneDates($milestone))->toBe(['2026-11-01', '2026-11-30'])
        ->and(ProposalPlanShift::query()->exists())->toBeFalse();
});

test('a proposal without grant dates is skipped with nothing written', function () {
    $proposal = rebaseTestProposal();
    $milestone = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');
    $approvalNote = Activity::withoutRecording(fn (): ProposalStatusNote => ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Approved]));

    $result = app(RebaseProposalPlan::class)->handle($proposal->fresh(), $approvalNote);

    expect($result)->toBe(['shifted' => 0, 'clipped' => [], 'skipped' => true])
        ->and(rebaseTestMilestoneDates($milestone))->toBe(['2026-11-01', '2026-11-30'])
        ->and(ProposalPlanShift::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'proposal.plan_rebased')->exists())->toBeFalse();
});

test('an approval note of another proposal is refused', function () {
    $proposal = rebaseTestProposal();
    $foreignNote = Activity::withoutRecording(fn (): ProposalStatusNote => ProposalStatusNote::factory()->create(['status' => ProposalStatus::Approved]));

    expect(fn () => app(RebaseProposalPlan::class)->handle($proposal, $foreignNote))->toThrow(InvalidArgumentException::class)
        ->and(ProposalPlanShift::query()->exists())->toBeFalse();
});

test('a failing rebase is reported and never undoes the approval', function () {
    Exceptions::fake();
    app()->bind(RebaseProposalPlan::class, fn () => throw new RuntimeException('Rebase failed.'));
    $proposal = rebaseTestProposal();
    $milestone = rebaseTestMilestone($proposal, '2026-11-01', '2026-11-30');

    rebaseTestApprove($proposal);

    $proposal->refresh();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($proposal->actual_date_begin->toDateString())->toBe('2027-01-01')
        ->and($proposal->statusNotes()->where('status', ProposalStatus::Approved)->count())->toBe(1)
        ->and(rebaseTestMilestoneDates($milestone))->toBe(['2026-11-01', '2026-11-30'])
        ->and(ProposalPlanShift::query()->exists())->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Rebase failed.');
});
