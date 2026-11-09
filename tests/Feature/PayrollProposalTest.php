<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CloneProposalForRenewal;
use App\Actions\Proposals\CorrectProposalStatus;
use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\RecordFinalReportAcceptance;
use App\Actions\Proposals\SaveProposal;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Donor;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use App\Support\ProposalTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * The narrative every payroll fixture carries: long enough for the key.
 */
function payrollNarrative(): string
{
    return 'Volunteer salaries and equipment for the Micro Aleppo center during 2027.';
}

/**
 * Create data for a payroll proposal from the proposal form.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payrollFormData(array $overrides = []): array
{
    return [
        'kind' => ProposalKind::Payroll->value,
        'name' => 'Micro Aleppo payroll 2027',
        'internalProjectNumber' => 'HC-2026-961',
        'donorId' => Donor::factory()->create()->id,
        'narrative' => payrollNarrative(),
        'plannedDateBegin' => '2027-01-01',
        'plannedDateEnd' => '2027-12-31',
        'notes' => '',
        ...$overrides,
    ];
}

/**
 * A payroll proposal at the given status with a budget and planned dates.
 */
function payrollProposal(ProposalStatus $status = ProposalStatus::ProposalDraft): Proposal
{
    return Proposal::factory()->payroll()->create([
        'status' => $status,
        'requested_budget' => '48000.00',
        'requested_currency' => Currency::Usd,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);
}

/**
 * Run a payroll move through TransitionProposal as the given user.
 *
 * @param  array{reason?: string, effective_date?: string}  $input
 */
function movePayroll(User $actor, Proposal $proposal, ProposalStatus $to, array $input = []): mixed
{
    test()->actingAs($actor);

    return app(TransitionProposal::class)->handle($proposal, $to, $proposal->status, $input);
}

/**
 * The validation errors a refused call reports.
 *
 * @return array<string, list<string>>
 */
function payrollRefusal(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The call was not refused.');
}

/**
 * File the final report on a proposal.
 */
function fileFinalReport(Proposal $proposal, User $uploader): void
{
    Media::query()->findOrFail(insertMedia('proposal', $proposal->id, $uploader->id))->update(['collection' => MediaCollection::FinalReport]);
}

test('a payroll proposal is created in proposal draft with its narrative and no project', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $project = Project::factory()->create();

    $proposal = app(SaveProposal::class)->handle(null, payrollFormData([
        'projectIds' => [$project->id],
        'beneficiaries' => [$project->id => 120],
        'startingStage' => ProposalStatus::ConceptNote->value,
    ]), $actor);

    $notes = $proposal->statusNotes()->orderBy('id')->get();

    expect($proposal->kind)->toBe(ProposalKind::Payroll)
        ->and($proposal->narrative)->toBe(payrollNarrative())
        ->and($proposal->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($proposal->projects()->count())->toBe(0)
        ->and($notes->pluck('status')->all())->toBe([ProposalStatus::ConceptNote, ProposalStatus::ProposalDraft])
        ->and($notes->last()->context)->toBe(__('proposals.payroll_start_note'));
});

test('a payroll proposal needs a narrative of at least 50 characters and writes nothing without one', function (?string $narrative) {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);

    $errors = payrollRefusal(fn () => app(SaveProposal::class)->handle(null, payrollFormData(['narrative' => $narrative]), $actor));

    expect($errors['narrative'])->toBe([__('proposals.narrative_required')])
        ->and(Proposal::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'blank' => ['   '],
    'too short' => ['Salaries for the center.'],
]);

test('a project proposal is still created at the concept note and keeps the project kind', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $project = Project::factory()->create();

    $proposal = app(SaveProposal::class)->handle(null, payrollFormData([
        'kind' => null,
        'narrative' => null,
        'projectIds' => [$project->id],
        'startingStage' => ProposalStatus::ConceptNote->value,
    ]), $actor);

    expect($proposal->kind)->toBe(ProposalKind::Project)
        ->and($proposal->narrative)->toBeNull()
        ->and($proposal->status)->toBe(ProposalStatus::ConceptNote)
        ->and($proposal->projects()->count())->toBe(1);
});

test('an unknown kind is refused', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);

    $errors = payrollRefusal(fn () => app(SaveProposal::class)->handle(null, payrollFormData(['kind' => 'salary']), $actor));

    expect($errors)->toHaveKey('kind')
        ->and(Proposal::query()->count())->toBe(0);
});

test('the kind never changes on edit', function (ProposalKind $from, ProposalKind $sent) {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $proposal = $from === ProposalKind::Payroll ? payrollProposal() : Proposal::factory()->create();

    app(SaveProposal::class)->handle($proposal, [
        'kind' => $sent->value,
        'name' => $proposal->name,
        'internalProjectNumber' => $proposal->internal_project_number,
        'donorId' => $proposal->donor_id,
        'narrative' => payrollNarrative(),
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
    ], $actor);

    expect($proposal->fresh()->kind)->toBe($from);
})->with([
    'payroll sent as project' => [ProposalKind::Payroll, ProposalKind::Project],
    'project sent as payroll' => [ProposalKind::Project, ProposalKind::Payroll],
]);

test('editing a payroll proposal keeps the narrative rule', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $proposal = payrollProposal();

    $errors = payrollRefusal(fn () => app(SaveProposal::class)->handle($proposal, [
        'name' => $proposal->name,
        'internalProjectNumber' => $proposal->internal_project_number,
        'donorId' => $proposal->donor_id,
        'narrative' => 'Too short.',
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
    ], $actor));

    expect($errors['narrative'])->toBe([__('proposals.narrative_required')])
        ->and($proposal->fresh()->narrative)->toBe(payrollNarrative());
});

test('a payroll proposal never links a project', function () {
    $this->actingAs(userWithRole('Financial'));
    $proposal = payrollProposal();

    $errors = payrollRefusal(fn () => app(LinkProposalProject::class)->handle($proposal, Project::factory()->create()->id, 50));

    expect($errors['projectIds'])->toBe([__('proposals.payroll_has_no_projects')])
        ->and($proposal->projects()->count())->toBe(0);
});

test('the payroll table offers only the payroll path', function () {
    expect(ProposalTransitions::targets(ProposalStatus::ProposalDraft, ProposalKind::Payroll))->toBe([ProposalStatus::Submitted])
        ->and(ProposalTransitions::targets(ProposalStatus::Submitted, ProposalKind::Payroll))->toBe([ProposalStatus::ProposalDraft, ProposalStatus::Rejected])
        ->and(ProposalTransitions::targets(ProposalStatus::Active, ProposalKind::Payroll))->toBe([ProposalStatus::Completed])
        ->and(ProposalTransitions::requirements(ProposalStatus::ProposalDraft, ProposalStatus::Submitted, ProposalKind::Payroll))->toBe(['budget', 'planned_dates', 'narrative', 'effective_date'])
        ->and(ProposalTransitions::requirements(ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview, ProposalKind::Payroll))->toBeNull()
        ->and(payrollProposal()->transitionTargets())->toBe([ProposalStatus::Submitted]);
});

test('a payroll draft goes to the donor once the budget, dates and narrative are in', function () {
    Event::fake([ProposalStatusChanged::class]);
    $actor = userWithRole('Financial');
    $proposal = payrollProposal();

    $result = movePayroll($actor, $proposal, ProposalStatus::Submitted, ['effective_date' => '2026-10-13']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($result->note->status)->toBe(ProposalStatus::Submitted)
        ->and($result->note->date->toDateString())->toBe('2026-10-13')
        ->and($proposal->reviewers()->count())->toBe(0);
    Event::assertDispatchedTimes(ProposalStatusChanged::class, 1);
});

test('a payroll draft without its budget or narrative stays in draft with each message', function (Closure $break, string $field, string $message) {
    $proposal = payrollProposal();
    $break($proposal);

    $errors = payrollRefusal(fn () => movePayroll(userWithRole('Financial'), $proposal, ProposalStatus::Submitted, ['effective_date' => '2026-10-13']));

    expect($errors[$field])->toBe([__($message)])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($proposal->statusNotes()->count())->toBe(0);
})->with([
    'budget' => [fn (Proposal $p) => $p->forceFill(['requested_budget' => null])->save(), 'budget', 'proposals.budget_required'],
    'narrative' => [fn (Proposal $p) => $p->forceFill(['narrative' => 'Short.'])->save(), 'narrative', 'proposals.narrative_required'],
]);

test('a payroll proposal never enters a review or concept-note status', function (ProposalStatus $from, ProposalStatus $to) {
    $proposal = payrollProposal($from);

    $errors = payrollRefusal(fn () => movePayroll(userWithRole('Financial'), $proposal, $to, ['reason' => 'Trying the project path.', 'effective_date' => '2026-10-13']));

    expect($errors['status'])->toBe([__('proposals.transition_not_allowed', ['from' => $from->label(), 'to' => $to->label()])])
        ->and($proposal->fresh()->status)->toBe($from);
})->with([
    'draft → proposal review' => [ProposalStatus::ProposalDraft, ProposalStatus::ProposalReview],
    'draft → concept note' => [ProposalStatus::ProposalDraft, ProposalStatus::ConceptNote],
    'submitted → approved (ApproveProposal only)' => [ProposalStatus::Submitted, ProposalStatus::Approved],
    'approved → active (ApproveProposal only)' => [ProposalStatus::Approved, ProposalStatus::Active],
]);

test('the donor can send a payroll proposal back or reject it', function (ProposalStatus $to, array $input) {
    $proposal = payrollProposal(ProposalStatus::Submitted);

    movePayroll(userWithRole('Financial'), $proposal, $to, $input);

    expect($proposal->fresh()->status)->toBe($to);
})->with([
    'back to draft' => [ProposalStatus::ProposalDraft, ['reason' => 'The donor asked for a new budget split.']],
    'rejected' => [ProposalStatus::Rejected, ['reason' => 'The donor closed the call.', 'effective_date' => '2026-10-13']],
]);

test('approving a payroll proposal makes it active at once with two notes and two events', function () {
    Event::fake([ProposalStatusChanged::class]);
    $actor = userWithRole('Public Relations Manager');
    $this->actingAs($actor);
    $proposal = payrollProposal(ProposalStatus::Submitted);

    $result = app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
        'decision_date' => '2026-10-13',
        'approval_note' => 'The donor confirmed the payroll grant by email.',
    ]);

    $proposal->refresh();
    $notes = $proposal->statusNotes()->orderBy('id')->get();

    expect($proposal->status)->toBe(ProposalStatus::Active)
        ->and($proposal->actual_date_begin->toDateString())->toBe('2026-11-01')
        ->and($proposal->actual_date_end->toDateString())->toBe('2027-10-31')
        ->and($notes->pluck('status')->all())->toBe([ProposalStatus::Approved, ProposalStatus::Active])
        ->and($notes->last()->context)->toBe(__('proposals.payroll_active_on_approval'))
        ->and($notes->every(fn (ProposalStatusNote $note): bool => $note->changed_by === $actor->id))->toBeTrue()
        ->and($result->approvedProjectIds)->toBe([])
        ->and($result->waitingProjectIds)->toBe([])
        ->and($result->skippedProjectIds)->toBe([])
        ->and(Project::query()->count())->toBe(0);
    Event::assertDispatchedTimes(ProposalStatusChanged::class, 2);
    Event::assertDispatched(ProposalStatusChanged::class, fn (ProposalStatusChanged $event): bool => $event->from === ProposalStatus::Submitted && $event->to === ProposalStatus::Approved);
    Event::assertDispatched(ProposalStatusChanged::class, fn (ProposalStatusChanged $event): bool => $event->from === ProposalStatus::Approved && $event->to === ProposalStatus::Active);
});

test('a payroll approval keeps the grant dates it is given', function () {
    $this->actingAs(userWithRole('Public Relations Manager'));
    $proposal = payrollProposal(ProposalStatus::Submitted);

    app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
        'actual_date_begin' => '2026-12-01',
        'actual_date_end' => '2027-11-30',
        'decision_date' => '2026-10-13',
        'approval_note' => 'The donor confirmed the payroll grant by email.',
    ]);

    expect($proposal->fresh()->actual_date_begin->toDateString())->toBe('2026-12-01')
        ->and($proposal->fresh()->actual_date_end->toDateString())->toBe('2027-11-30');
});

test('a payroll approval without evidence or with a stale status writes nothing', function (array $input, ProposalStatus $expectedFrom, string $field) {
    Event::fake([ProposalStatusChanged::class]);
    $this->actingAs(userWithRole('Public Relations Manager'));
    $proposal = payrollProposal(ProposalStatus::Submitted);

    $errors = payrollRefusal(fn () => app(ApproveProposal::class)->handle($proposal, $expectedFrom, ['decision_date' => '2026-10-13', ...$input]));

    expect($errors)->toHaveKey($field)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(0);
    Event::assertNotDispatched(ProposalStatusChanged::class);
})->with([
    'no evidence' => [[], ProposalStatus::Submitted, 'approval_note'],
    'stale' => [['approval_note' => 'The donor confirmed the payroll grant by email.'], ProposalStatus::ProposalDraft, 'status'],
]);

test('a user without proposals.update cannot approve a payroll proposal', function () {
    $this->actingAs(userWithRole('Team Member'));
    $proposal = payrollProposal(ProposalStatus::Submitted);

    expect(fn () => app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
        'decision_date' => '2026-10-13',
        'approval_note' => 'The donor confirmed the payroll grant by email.',
    ]))->toThrow(AuthorizationException::class);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('a payroll proposal completes with the final report file and the acceptance, no milestone', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $proposal = payrollProposal(ProposalStatus::Active);
    fileFinalReport($proposal, $actor);

    $errors = payrollRefusal(fn () => movePayroll($actor, $proposal, ProposalStatus::Completed, ['effective_date' => '2026-10-13']));
    expect($errors['final_report'])->toBe([__('proposals.final_report_required')]);

    app(RecordFinalReportAcceptance::class)->handle($proposal, CarbonImmutable::parse('2026-10-12'));
    movePayroll($actor, $proposal->fresh(), ProposalStatus::Completed, ['effective_date' => '2026-10-13']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed)
        ->and(Milestone::query()->count())->toBe(0);
});

test('a payroll proposal without the final report file cannot complete', function () {
    $actor = userWithRole('Financial');
    $proposal = payrollProposal(ProposalStatus::Active);
    $proposal->forceFill(['final_report_accepted_on' => '2026-10-12', 'final_report_accepted_by' => $actor->id])->save();

    $errors = payrollRefusal(fn () => movePayroll($actor, $proposal, ProposalStatus::Completed, ['effective_date' => '2026-10-13']));

    expect($errors['final_report'])->toBe([__('proposals.final_report_required')])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Active);
});

test('a self-funded payroll proposal completes with the final report file alone', function () {
    $actor = userWithRole('Financial');
    $proposal = payrollProposal(ProposalStatus::Active);
    $proposal->forceFill(['donor_id' => Donor::factory()->create(['is_internal' => true])->id])->save();
    fileFinalReport($proposal, $actor);

    movePayroll($actor, $proposal, ProposalStatus::Completed, ['effective_date' => '2026-10-13']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed);
});

test('correcting a payroll proposal offers only payroll statuses', function () {
    expect(ProposalTransitions::correctionTargets(ProposalStatus::Completed, ProposalKind::Payroll))
        ->toBe([ProposalStatus::ProposalDraft, ProposalStatus::Submitted, ProposalStatus::Active, ProposalStatus::Rejected])
        ->and(ProposalTransitions::correctionTargets(ProposalStatus::Rejected, ProposalKind::Payroll))
        ->toBe([ProposalStatus::ProposalDraft, ProposalStatus::Submitted, ProposalStatus::Active, ProposalStatus::Completed]);

    $this->actingAs(userWithRole('General Manager'));
    $proposal = payrollProposal(ProposalStatus::Completed);

    $errors = payrollRefusal(fn () => app(CorrectProposalStatus::class)->handle($proposal, ProposalStatus::ConceptNote, ProposalStatus::Completed, 'Closed by mistake.'));
    expect($errors)->toHaveKey('status');

    app(CorrectProposalStatus::class)->handle($proposal, ProposalStatus::Active, ProposalStatus::Completed, 'Closed by mistake.');
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Active);
});

test('renewing a payroll proposal starts a payroll draft with the narrative and no projects', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $previous = payrollProposal(ProposalStatus::Completed);

    $result = app(CloneProposalForRenewal::class)->handle($previous, [], $actor);
    $renewal = $result['proposal'];

    expect($renewal->kind)->toBe(ProposalKind::Payroll)
        ->and($renewal->internal_project_number)->toMatch('/^\d{2}-001$/')
        ->and($renewal->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($renewal->narrative)->toBe(payrollNarrative())
        ->and($renewal->previous_proposal_id)->toBe($previous->id)
        ->and($renewal->projects()->count())->toBe(0)
        ->and($result['copied'])->toBe(['results' => 0, 'indicators' => 0, 'milestones' => 0, 'tasks' => 0])
        ->and(app(CloneProposalForRenewal::class)->handle($previous, ['internalProjectNumber' => 'HC-2026-963'], $actor)['proposal']->id)->toBe($renewal->id);
});

test('payroll renewals count on their own YY-NNN sequence and leave the HC- numbers alone', function () {
    $actor = userWithRole('Financial');
    $this->actingAs($actor);
    $renew = fn (): Proposal => app(CloneProposalForRenewal::class)->handle(payrollProposal(ProposalStatus::Completed), [], $actor)['proposal'];

    $first = $renew();
    $second = $renew();
    $yy = $first->planned_date_begin->format('y');

    expect($first->internal_project_number)->toBe("{$yy}-001")
        ->and($second->internal_project_number)->toBe("{$yy}-002");

    $year = $first->planned_date_begin->year;
    $fresh = app(SaveProposal::class)->handle(null, payrollFormData(['plannedDateBegin' => "{$year}-02-01", 'plannedDateEnd' => "{$year}-12-31"]), $actor);

    expect($fresh->internal_project_number)->toBe("HC-{$year}-001");
});
