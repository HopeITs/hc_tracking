<?php

use App\Actions\Proposals\ApproveProposal;
use App\Enums\AssignmentStatus;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProjectPeriodChanged;
use App\Events\ProposalStatusChanged;
use App\Models\Assignment;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\QuickActions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * A submitted proposal planned from 2026-11-01 to 2027-10-31.
 */
function submittedProposal(ProposalStatus $status = ProposalStatus::Submitted): Proposal
{
    return Proposal::factory()->create([
        'status' => $status,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);
}

/**
 * Link a project to a proposal; the owner has acknowledged unless told otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function linkedProject(Proposal $proposal, bool $ownerAcknowledged = true, array $attributes = [], ?ProjectStatus $state = null): Project
{
    $factory = Project::factory();
    $factory = match ($state) {
        ProjectStatus::Approved => $factory->approved(),
        ProjectStatus::Active => $factory->active(),
        ProjectStatus::Closed => $factory->closed(),
        default => $factory,
    };

    $project = $factory->create($attributes);

    if ($ownerAcknowledged) {
        $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    }

    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

    return $project;
}

/**
 * @param  array<string, string|null>  $overrides
 * @return array{actual_date_begin: string, actual_date_end: string, decision_date: string, note?: ?string, approval_note?: ?string}
 */
function approvalInput(array $overrides = []): array
{
    return [
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
        'decision_date' => '2026-10-13',
        'approval_note' => 'The donor confirmed the grant by email on 12 October.',
        ...$overrides,
    ];
}

/**
 * @param  array<string, string|null>  $input
 */
function approve(User $actor, Proposal $proposal, array $input = [], ?ProposalStatus $expectedFrom = null): mixed
{
    test()->actingAs($actor);

    return app(ApproveProposal::class)->handle($proposal, $expectedFrom ?? $proposal->status, approvalInput($input));
}

/**
 * @return array<string, list<string>>
 */
function refusedApproval(callable $move): array
{
    try {
        $move();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The approval was not refused.');
}

test('approving fixes the grant dates, writes one note and funds an acknowledged project without activating it', function () {
    $actor = userWithRole('Financial');
    $proposal = submittedProposal();
    $project = linkedProject($proposal);
    Event::fake([ProposalStatusChanged::class]);

    $result = approve($actor, $proposal);

    $proposal->refresh();
    $project->refresh();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($proposal->actual_date_begin->toDateString())->toBe('2026-11-01')
        ->and($proposal->actual_date_end->toDateString())->toBe('2027-10-31')
        ->and($proposal->approval_note)->toBe('The donor confirmed the grant by email on 12 October.')
        ->and($proposal->statusNotes()->count())->toBe(1)
        ->and($proposal->statusNotes()->sole()->changed_by)->toBe($actor->id)
        ->and($proposal->statusNotes()->sole()->date->toDateString())->toBe('2026-10-13')
        ->and($project->status)->toBe(ProjectStatus::Approved)
        ->and($project->start_date->toDateString())->toBe('2026-11-01')
        ->and($project->end_date->toDateString())->toBe('2027-10-31')
        ->and($result->approvedProjectIds)->toBe([$project->id])
        ->and($result->waitingProjectIds)->toBe([])
        ->and($result->skippedProjectIds)->toBe([])
        ->and($result->warnings)->toContain('no_agreement');

    Event::assertDispatchedTimes(ProposalStatusChanged::class, 1);
    Event::assertDispatched(ProposalStatusChanged::class, fn (ProposalStatusChanged $event): bool => $event->to === ProposalStatus::Approved
        && $event->from === ProposalStatus::Submitted && $event->actorId === $actor->id);
});

test('an agreement on record removes the no-agreement warning', function () {
    $proposal = submittedProposal();
    linkedProject($proposal);
    DonorProposal::factory()->create(['proposal_id' => $proposal->id]);

    expect(approve(userWithRole('Financial'), $proposal)->warnings)->not->toContain('no_agreement');
});

test('a project whose owner has not acknowledged gets the dates and stays a draft until they do', function () {
    $proposal = submittedProposal();
    $project = linkedProject($proposal, ownerAcknowledged: false);

    $result = approve(userWithRole('Financial'), $proposal);

    $project->refresh();

    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->start_date->toDateString())->toBe('2026-11-01')
        ->and($result->waitingProjectIds)->toBe([$project->id])
        ->and($result->approvedProjectIds)->toBe([]);

    $owner = userWithRole('Managers', (int) $project->user_id);
    $this->actingAs($owner);
    $project->currentAssignment('user_id')->acknowledge($owner);

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('acknowledging from the notification quick action approves the waiting project', function () {
    $proposal = submittedProposal();
    $employee = insertHrEmployee();
    $owner = userWithRole('Managers', $employee);
    $project = linkedProject($proposal, ownerAcknowledged: false, attributes: ['user_id' => $employee]);
    approve(userWithRole('Financial'), $proposal);

    $this->actingAs($owner);
    $notification = UserNotification::query()->forUser($owner)->get()
        ->first(fn (UserNotification $row): bool => in_array('assignment.acknowledge', array_column($row->data['actions'] ?? [], 'type'), true));

    expect(app(QuickActions::class)->run($owner, $notification, 'assignment.acknowledge')['ok'])->toBeTrue()
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('putting yourself in charge of a waiting project approves it', function () {
    $proposal = submittedProposal();
    $project = linkedProject($proposal, ownerAcknowledged: false);
    approve(userWithRole('Financial'), $proposal);

    $employee = insertHrEmployee();
    $self = userWithRole('General Manager', $employee);
    $this->actingAs($self);
    $project->update(['user_id' => $employee]);

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('a rejected owner keeps the project a draft and the project is skipped', function () {
    $proposal = submittedProposal();
    $project = linkedProject($proposal, ownerAcknowledged: false);
    $project->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Rejected])->save();

    $result = approve(userWithRole('Financial'), $proposal);

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($project->fresh()->start_date)->toBeNull()
        ->and($result->skippedProjectIds)->toBe([$project->id]);
});

test('an unblocked owner who acknowledges again approves the project', function () {
    $proposal = submittedProposal();
    $project = linkedProject($proposal, ownerAcknowledged: false);
    $project->currentAssignment('user_id')->forceFill(['status' => AssignmentStatus::Rejected])->save();
    $proposal->forceFill(['status' => ProposalStatus::Approved])->save();
    $proposal->forceFill(['actual_date_begin' => '2026-11-01', 'actual_date_end' => '2027-10-31'])->save();

    $ceoEmployee = insertHrEmployee();
    $ceo = userWithRole('General Manager', $ceoEmployee);
    $project->currentAssignment('user_id')->unblock($ceo);

    $owner = userWithRole('Managers', (int) $project->user_id);
    $this->actingAs($owner);
    $project->currentAssignment('user_id')->acknowledge($owner);

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('approved and active projects are extended, never shortened', function () {
    $proposal = submittedProposal();
    $approved = linkedProject($proposal, attributes: ['start_date' => '2026-12-01', 'end_date' => '2027-06-30'], state: ProjectStatus::Approved);
    $active = linkedProject($proposal, attributes: ['start_date' => '2026-01-01', 'end_date' => '2028-03-31'], state: ProjectStatus::Active);
    Event::fake([ProjectPeriodChanged::class, ProposalStatusChanged::class]);

    approve(userWithRole('Financial'), $proposal);

    expect($approved->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($approved->fresh()->start_date->toDateString())->toBe('2026-11-01')
        ->and($approved->fresh()->end_date->toDateString())->toBe('2027-10-31')
        ->and($active->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($active->fresh()->start_date->toDateString())->toBe('2026-01-01')
        ->and($active->fresh()->end_date->toDateString())->toBe('2028-03-31');

    Event::assertNotDispatched(ProjectPeriodChanged::class, fn (ProjectPeriodChanged $event): bool => $event->projectId === $active->id);
    Event::assertNotDispatched(ProjectPeriodChanged::class, fn (ProjectPeriodChanged $event): bool => $event->projectId === $approved->id);
});

test('an active project whose end moves raises ProjectPeriodChanged once', function () {
    $proposal = submittedProposal();
    $active = linkedProject($proposal, attributes: ['start_date' => '2026-01-01', 'end_date' => '2027-03-31'], state: ProjectStatus::Active);
    Event::fake([ProjectPeriodChanged::class, ProposalStatusChanged::class]);

    approve(userWithRole('Financial'), $proposal);

    Event::assertDispatchedTimes(ProjectPeriodChanged::class, 1);
    Event::assertDispatched(ProjectPeriodChanged::class, fn (ProjectPeriodChanged $event): bool => $event->projectId === $active->id
        && $event->oldEnd->toDateString() === '2027-03-31' && $event->newEnd->toDateString() === '2027-10-31'
        && $event->oldStart->toDateString() === '2026-01-01' && $event->newStart->toDateString() === '2026-01-01');
});

test('archived and closed projects are skipped and named', function () {
    $proposal = submittedProposal();
    $archived = linkedProject($proposal, attributes: ['status' => ProjectStatus::Archived]);
    $closed = linkedProject($proposal, state: ProjectStatus::Closed);

    $result = approve(userWithRole('Financial'), $proposal);

    expect($result->skippedProjectIds)->toEqualCanonicalizing([$archived->id, $closed->id])
        ->and($archived->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('approval is allowed from on hold entered from submitted', function () {
    $proposal = submittedProposal(ProposalStatus::OnHold);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Submitted, 'context' => 'Sent.']);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::OnHold, 'context' => 'Paused.']);
    linkedProject($proposal);

    approve(userWithRole('Financial'), $proposal);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved);
});

test('approval is refused with no write from the wrong status', function (ProposalStatus $status, ?ProposalStatus $preHold) {
    $proposal = submittedProposal($status);
    $project = linkedProject($proposal);

    if ($preHold !== null) {
        ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $preHold, 'context' => 'Sent.']);
        ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::OnHold, 'context' => 'Paused.']);
    }

    $notes = $proposal->statusNotes()->count();
    Event::fake([ProposalStatusChanged::class]);

    $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal));

    expect($errors)->toHaveKey('status')
        ->and($proposal->fresh()->status)->toBe($status)
        ->and($proposal->statusNotes()->count())->toBe($notes)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Draft);

    Event::assertNotDispatched(ProposalStatusChanged::class);
})->with([
    'proposal draft' => [ProposalStatus::ProposalDraft, null],
    'on hold from concept note submitted' => [ProposalStatus::OnHold, ProposalStatus::ConceptNoteSubmitted],
    'rejected' => [ProposalStatus::Rejected, null],
]);

test('approving an approved proposal says so and writes nothing', function () {
    $proposal = submittedProposal(ProposalStatus::Approved);
    linkedProject($proposal);

    $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal, expectedFrom: ProposalStatus::Submitted));

    expect($errors['status'][0])->toBe(__('proposals.already_approved'))
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('double approval writes one note and one event', function () {
    $actor = userWithRole('Financial');
    $proposal = submittedProposal();
    linkedProject($proposal);
    Event::fake([ProposalStatusChanged::class]);

    approve($actor, $proposal);
    $errors = refusedApproval(fn () => approve($actor, $proposal->fresh(), expectedFrom: ProposalStatus::Submitted));

    expect($errors)->toHaveKey('status')
        ->and($proposal->statusNotes()->count())->toBe(1);

    Event::assertDispatchedTimes(ProposalStatusChanged::class, 1);
});

test('a proposal with no linked project cannot be approved', function () {
    $proposal = submittedProposal();

    $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal));

    expect($errors['linked_project'][0])->toBe(__('proposals.link_project_first'))
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the donor approval needs a file or a note of at least ten characters', function () {
    $proposal = submittedProposal();
    linkedProject($proposal);

    foreach ([null, '', 'too short'] as $note) {
        $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal, ['approval_note' => $note]));

        expect($errors['approval_note'][0])->toBe(__('proposals.approval_evidence_required'));
    }

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('a donor approval file alone is enough', function () {
    $proposal = submittedProposal();
    linkedProject($proposal);
    Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $proposal->id, 'collection' => MediaCollection::DonorApproval]);

    approve(userWithRole('Financial'), $proposal, ['approval_note' => null]);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($proposal->fresh()->approval_note)->toBeNull();
});

test('a file in another category does not count as donor approval evidence', function () {
    $proposal = submittedProposal();
    linkedProject($proposal);
    Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $proposal->id, 'collection' => MediaCollection::Other]);

    $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal, ['approval_note' => null]));

    expect($errors)->toHaveKey('approval_note');
});

test('the dates and the decision date are validated with translated messages', function (array $input, string $key, string $message) {
    $proposal = submittedProposal();
    linkedProject($proposal);

    $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal, $input));

    expect($errors[$key][0])->toBe(__($message));
})->with([
    'no start' => [['actual_date_begin' => null], 'actual_date_begin', 'proposals.grant_start_required'],
    'end before start' => [['actual_date_end' => '2026-10-31'], 'actual_date_end', 'proposals.grant_end_before_start'],
    'decision in the future' => [['decision_date' => '2026-10-15'], 'decision_date', 'proposals.decision_not_future'],
    'start before the decision needs a note' => [['actual_date_begin' => '2026-10-01'], 'actual_date_begin', 'proposals.grant_start_late_note_required'],
]);

test('a start before the decision date is accepted with an explanatory note', function () {
    $proposal = submittedProposal();
    linkedProject($proposal);

    approve(userWithRole('Financial'), $proposal, ['actual_date_begin' => '2026-10-01', 'note' => 'Work started on the donor\'s verbal go-ahead.']);

    expect($proposal->fresh()->actual_date_begin->toDateString())->toBe('2026-10-01')
        ->and($proposal->statusNotes()->sole()->context)->toContain('verbal go-ahead');
});

test('the note is capped at 2000 characters', function () {
    $proposal = submittedProposal();
    linkedProject($proposal);

    $errors = refusedApproval(fn () => approve(userWithRole('Financial'), $proposal, ['note' => str_repeat('x', 2001)]));

    expect($errors)->toHaveKey('note');
});

test('approving without proposals.update is forbidden and writes nothing', function () {
    $proposal = submittedProposal();
    $project = linkedProject($proposal);
    Event::fake([ProposalStatusChanged::class]);

    expect(fn () => approve(userWithRole('Team Member'), $proposal))->toThrow(AuthorizationException::class);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($project->fresh()->start_date)->toBeNull();

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('a failure inside approval rolls everything back and dispatches nothing', function () {
    $actor = userWithRole('Financial');
    $proposal = submittedProposal();
    $project = linkedProject($proposal);
    Event::fake([ProposalStatusChanged::class, ProjectPeriodChanged::class]);

    try {
        DB::transaction(function () use ($actor, $proposal): void {
            approve($actor, $proposal);

            throw new RuntimeException('Caller failed after approval.');
        });
    } catch (RuntimeException) {
    }

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($project->fresh()->start_date)->toBeNull();

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('the activity of the approval carries no amount', function () {
    $proposal = submittedProposal();
    $proposal->forceFill(['requested_budget' => '120000.00'])->save();
    linkedProject($proposal);

    approve(userWithRole('Financial'), $proposal);

    $activity = DB::table('activities')->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->where('event', 'proposal.approved')->first();

    expect($activity)->not->toBeNull()
        ->and(json_encode($activity))->not->toContain('requested_budget')
        ->and(json_encode($activity))->not->toContain('120000');
});

test('a project reached through the approval records project.approved with its via', function () {
    $proposal = submittedProposal();
    $project = linkedProject($proposal);

    approve(userWithRole('Financial'), $proposal);

    $activity = DB::table('activities')->where('subject_type', 'project')->where('subject_id', $project->id)->where('event', 'project.approved')->first();

    expect($activity)->not->toBeNull();

    $properties = json_decode($activity->properties, true);

    expect($properties['via'])->toBe('proposal_approval')
        ->and($properties['proposal_id'])->toBe($proposal->id);
});

test('Assignment is unchanged for a project that is not waiting on a donor-approved proposal', function () {
    $project = linkedProject(submittedProposal(), ownerAcknowledged: false);
    $owner = userWithRole('Managers', (int) $project->user_id);
    $this->actingAs($owner);

    $project->currentAssignment('user_id')->acknowledge($owner);

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($project->fresh()->start_date)->toBeNull()
        ->and(Assignment::query()->where('assignable_id', $project->id)->first()->status)->toBe(AssignmentStatus::Acknowledged);
});
