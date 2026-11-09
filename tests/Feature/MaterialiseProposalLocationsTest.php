<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\MaterialiseProposalLocations;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Activity;
use App\Models\Program;
use App\Models\ProgramRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| W-34a slice 2 (D-62): approving a proposal materialises the projects its
| location rows still lack, inside the approval transaction and before the
| linked projects are approved. Drafts are created only through SaveProject
| and linked only through LinkProposalProject; an open project already at the
| row's center and program is reused with a warning; rows that carry a
| project are skipped, so a second run writes nothing.
*/

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->aleppoEast = insertHrCenter($this->aleppo, 'Hope Center Aleppo East', 'مركز الأمل حلب الشرقي');
    $this->program = Program::factory()->create();
    $this->manager = (int) $this->program->manager_id;
    $this->employee = insertHrEmployee($this->aleppo, 'Rana', 'Saleh', 'رنا', 'صالح');
    $this->approver = userWithRole('Public Relations Manager');
});

/**
 * A submitted proposal with the given location rows (each row: attributes
 * plus an optional `center_ids` list).
 *
 * @param  list<array<string, mixed>>  $rows
 */
function proposalWithRows(array $rows, ProposalStatus $status = ProposalStatus::Submitted): Proposal
{
    $proposal = Proposal::factory()->create([
        'status' => $status,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);

    foreach ($rows as $sort => $row) {
        $centerIds = $row['center_ids'] ?? [];
        unset($row['center_ids']);

        $location = ProposalLocation::factory()->for($proposal)->create([
            'program_id' => test()->program->id,
            'responsible_employee_id' => test()->manager,
            'is_new' => true,
            'sort' => $sort,
            ...$row,
        ]);

        foreach ($centerIds as $centerId) {
            $location->centers()->create(['center_id' => $centerId]);
        }
    }

    return $proposal;
}

/**
 * One new Aleppo row under the test program, run from the given centers.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newRow(array $overrides = []): array
{
    return ['city_id' => test()->aleppo, 'center_ids' => [test()->aleppoCenter], ...$overrides];
}

/**
 * Approve as the given user with valid donor input.
 *
 * @param  array<string, string|null>  $input
 */
function approveWithRows(User $actor, Proposal $proposal, array $input = [], ?ProposalStatus $expectedFrom = null): mixed
{
    test()->actingAs($actor);

    return app(ApproveProposal::class)->handle($proposal, $expectedFrom ?? $proposal->status, [
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
        'decision_date' => '2026-10-13',
        'approval_note' => 'The donor confirmed the grant by email on 12 October.',
        ...$input,
    ]);
}

/**
 * @return array<string, list<string>>
 */
function refusedRowsApproval(callable $move): array
{
    try {
        $move();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The approval was not refused.');
}

/**
 * The stored rows of a proposal, ordered as the page shows them.
 *
 * @return list<object>
 */
function rowsOf(Proposal $proposal): array
{
    return DB::table('proposal_locations')->where('proposal_id', $proposal->id)->orderBy('sort')->orderBy('id')->get()->all();
}

// ── Creating ────────────────────────────────────────────────────────────

test('a new row becomes a draft through SaveProject, linked to the proposal and written back on the row', function () {
    $proposal = proposalWithRows([newRow()]);

    $result = approveWithRows($this->approver, $proposal);

    $project = DB::table('projects')->sole();
    $row = rowsOf($proposal)[0];

    expect($project->status)->toBe('draft')
        ->and((int) $project->center_id)->toBe($this->aleppoCenter)
        ->and($project->host_center_id)->toBeNull()
        ->and((int) $project->city_id)->toBe($this->aleppo)
        ->and((int) $project->program_id)->toBe($this->program->id)
        ->and((int) $project->user_id)->toBe($this->manager)
        ->and($project->start_date)->not->toBeNull()
        ->and(DB::table('project_sector')->count())->toBe(0)
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->pluck('project_id')->all())->toEqual([$project->id])
        ->and((int) $row->project_id)->toBe((int) $project->id)
        ->and((bool) $row->is_new)->toBeFalse()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($result->approvedProjectIds)->toBe([])
        ->and($result->waitingProjectIds)->toBe([(int) $project->id])
        ->and($result->warnings)->not->toContain('location_linked_existing');

    expect(Activity::query()->where('subject_type', 'project')->where('event', 'project.created')->count())->toBe(1)
        ->and(Activity::query()->where('subject_type', 'proposal')->where('event', 'proposal.project_linked')->count())->toBe(1);
});

test('a row with two centers creates one draft per center and keeps the first on the row', function () {
    $proposal = proposalWithRows([newRow(['center_ids' => [$this->aleppoCenter, $this->aleppoEast]])]);

    approveWithRows($this->approver, $proposal);

    $projects = DB::table('projects')->orderBy('id')->get();
    $rows = rowsOf($proposal);

    expect($projects)->toHaveCount(2)
        ->and($projects->pluck('center_id')->map(fn ($id): int => (int) $id)->all())->toBe([$this->aleppoCenter, $this->aleppoEast])
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->count())->toBe(2)
        ->and($rows)->toHaveCount(1)
        ->and((int) $rows[0]->project_id)->toBe((int) $projects[0]->id)
        ->and(DB::table('proposal_location_centers')->where('proposal_location_id', $rows[0]->id)->count())->toBe(2);
});

test('an open project already at the center and program is linked instead of created, with a warning', function () {
    $open = Project::factory()->create(['city_id' => $this->aleppo, 'center_id' => $this->aleppoCenter, 'program_id' => $this->program->id]);
    $proposal = proposalWithRows([newRow()]);

    $result = approveWithRows($this->approver, $proposal);

    expect(DB::table('projects')->count())->toBe(1)
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->pluck('project_id')->all())->toEqual([$open->id])
        ->and((int) rowsOf($proposal)[0]->project_id)->toBe($open->id)
        ->and($result->warnings)->toContain('location_linked_existing')
        ->and($result->waitingProjectIds)->toBe([$open->id]);
});

test('a row that already carries a project is skipped', function () {
    $project = Project::factory()->create(['city_id' => $this->aleppo, 'center_id' => $this->aleppoCenter, 'program_id' => $this->program->id]);
    $proposal = proposalWithRows([['city_id' => $this->aleppo, 'project_id' => $project->id, 'is_new' => false, 'center_ids' => [$this->aleppoCenter]]]);
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

    approveWithRows($this->approver, $proposal);

    expect(DB::table('projects')->count())->toBe(1)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved);
});

// ── Completeness ────────────────────────────────────────────────────────

test('a row whose program request is still pending blocks the approval and writes nothing', function () {
    $proposal = proposalWithRows([newRow(['program_id' => null])]);
    $rowId = (int) rowsOf($proposal)[0]->id;
    ProgramRequest::factory()->create(['proposal_location_id' => $rowId]);
    Event::fake([ProposalStatusChanged::class]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal));

    expect($errors['locations_complete'])->toBe([__('proposals.locations_incomplete')])
        ->and(DB::table('projects')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(0);

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('a new row without a center cannot become a project and blocks the approval', function () {
    $proposal = proposalWithRows([newRow(['center_ids' => []])]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal));

    expect($errors['locations_complete'])->toBe([__('proposals.locations_incomplete')])
        ->and(DB::table('projects')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

// ── Owner ───────────────────────────────────────────────────────────────

test('the responsible owns the project when they manage a program, else the program manager does', function () {
    $otherProgram = Program::factory()->create(['manager_id' => $this->employee]);
    $plainEmployee = insertHrEmployee($this->aleppo, 'Omar', 'Nasser', 'عمر', 'ناصر');
    $proposal = proposalWithRows([
        newRow(['responsible_employee_id' => $plainEmployee]),
        ['city_id' => $this->aleppo, 'program_id' => $otherProgram->id, 'responsible_employee_id' => $this->employee, 'center_ids' => [$this->aleppoEast]],
    ]);

    approveWithRows($this->approver, $proposal);

    $owners = DB::table('projects')->orderBy('id')->pluck('user_id', 'program_id')->map(fn ($id): int => (int) $id)->all();

    expect($owners)->toBe([$this->program->id => $this->manager, $otherProgram->id => $this->employee]);
});

test('a row whose program has no manager and whose responsible manages none is refused', function () {
    $this->program->forceFill(['manager_id' => null])->save();
    $proposal = proposalWithRows([newRow(['responsible_employee_id' => $this->employee])]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal));

    expect($errors['locations_complete'])->toBe([__('proposals.location_owner_required', ['city' => 'Aleppo'])])
        ->and(DB::table('projects')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

// ── Idempotency, lock, rollback ─────────────────────────────────────────

test('materialising twice creates and links nothing the second time', function () {
    $proposal = proposalWithRows([newRow()]);
    $this->actingAs($this->approver);

    $first = app(MaterialiseProposalLocations::class)->handle($proposal, $this->approver->id);
    $second = app(MaterialiseProposalLocations::class)->handle($proposal->fresh(), $this->approver->id);

    expect($first->createdProjectIds)->toHaveCount(1)
        ->and($second->createdProjectIds)->toBe([])
        ->and($second->linkedProjectIds)->toBe([])
        ->and($second->warnings)->toBe([])
        ->and(DB::table('projects')->count())->toBe(1)
        ->and(DB::table('project_proposal')->count())->toBe(1);
});

test('a double submit approves once: one note, one project, one event', function () {
    $proposal = proposalWithRows([newRow()]);
    Event::fake([ProposalStatusChanged::class]);

    approveWithRows($this->approver, $proposal);
    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal->fresh(), expectedFrom: ProposalStatus::Submitted));

    expect($errors['status'][0])->toBe(__('proposals.already_approved'))
        ->and($proposal->statusNotes()->count())->toBe(1)
        ->and(DB::table('projects')->count())->toBe(1);

    Event::assertDispatchedTimes(ProposalStatusChanged::class, 1);
});

test('a failure after the approval rolls the created projects back and dispatches nothing', function () {
    $proposal = proposalWithRows([newRow()]);
    Event::fake([ProposalStatusChanged::class]);

    try {
        DB::transaction(function () use ($proposal): void {
            approveWithRows($this->approver, $proposal);

            throw new RuntimeException('Caller failed after approval.');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('projects')->count())->toBe(0)
        ->and(rowsOf($proposal)[0]->project_id)->toBeNull()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('a refused donor input leaves no materialised project behind', function () {
    $proposal = proposalWithRows([newRow()]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal, ['approval_note' => 'short']));

    expect($errors)->toHaveKey('approval_note')
        ->and(DB::table('projects')->count())->toBe(0)
        ->and(rowsOf($proposal)[0]->project_id)->toBeNull();
});

// ── Authorization ───────────────────────────────────────────────────────

test('approving without proposals.update is forbidden and creates nothing', function () {
    $proposal = proposalWithRows([newRow()]);

    expect(fn () => approveWithRows(userWithRole('Team Member'), $proposal))->toThrow(AuthorizationException::class);

    expect(DB::table('projects')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the Public Relations Manager may approve rows that create projects: the role holds projects.create', function () {
    $proposal = proposalWithRows([newRow()]);

    approveWithRows(userWithRole('Public Relations Manager'), $proposal);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and(DB::table('projects')->count())->toBe(1);
});

test('an approver without projects.create cannot approve rows that would create projects', function () {
    $proposal = proposalWithRows([newRow()]);
    $approver = userWithRole('Team Member');
    $approver->givePermissionTo(['proposals.update', 'proposals.approve']);

    expect(fn () => approveWithRows($approver, $proposal))->toThrow(AuthorizationException::class);

    expect(DB::table('projects')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(0);
});

test('an approver without projects.create still approves rows that only reuse open projects', function () {
    $open = Project::factory()->create(['city_id' => $this->aleppo, 'center_id' => $this->aleppoCenter, 'program_id' => $this->program->id]);
    $proposal = proposalWithRows([newRow()]);
    $approver = userWithRole('Team Member');
    $approver->givePermissionTo(['proposals.update', 'proposals.approve']);

    approveWithRows($approver, $proposal);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and((int) rowsOf($proposal)[0]->project_id)->toBe($open->id);
});

test('the materialiser itself refuses a user who may not approve the proposal', function () {
    $proposal = proposalWithRows([newRow()]);
    $this->actingAs(userWithRole('Team Member'));

    expect(fn () => app(MaterialiseProposalLocations::class)->handle($proposal, auth()->id()))->toThrow(AuthorizationException::class);

    expect(DB::table('projects')->count())->toBe(0);
});

// ── Preview ─────────────────────────────────────────────────────────────

test('the preview counts what approval would create, reuse and wait for, and writes nothing', function () {
    $open = Project::factory()->create(['city_id' => $this->aleppo, 'center_id' => $this->aleppoCenter, 'program_id' => $this->program->id]);
    $homs = insertHrCity('Homs', 'حمص');
    $homsCenter = insertHrCenter($homs, 'Hope Center Homs', 'مركز الأمل حمص');
    $homsEast = insertHrCenter($homs, 'Hope Center Homs East', 'مركز الأمل حمص الشرقي');
    $proposal = proposalWithRows([
        newRow(),
        ['city_id' => $homs, 'center_ids' => [$homsCenter, $homsEast]],
        ['city_id' => $homs, 'program_id' => null, 'center_ids' => [$homsCenter]],
    ]);
    $rows = rowsOf($proposal);
    $this->actingAs($this->approver);

    $preview = app(MaterialiseProposalLocations::class)->preview($proposal);

    expect($preview->createdProjectIds)->toBe([(int) $rows[1]->id, (int) $rows[1]->id])
        ->and($preview->linkedProjectIds)->toBe([$open->id])
        ->and($preview->waitingLocationIds)->toBe([(int) $rows[2]->id])
        ->and($preview->warnings)->toBe(['location_linked_existing'])
        ->and(DB::table('projects')->count())->toBe(1)
        ->and(DB::table('project_proposal')->count())->toBe(0)
        ->and(rowsOf($proposal)[0]->project_id)->toBeNull();
});
