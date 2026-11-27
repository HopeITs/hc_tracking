<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\MaterialiseProposalLocations;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| W-34a slice 2 (D-62), as Y-17 leaves it: approving a proposal completes its
| location rows, inside the approval transaction. **No project is created or
| linked** — a place IS a row (D-93) — so the rows take their dates from the
| grant and reach `approved` as soon as their responsible has acknowledged.
|
| Seven cases went with the feature they guarded, named in the Y-17 handoff:
| the four on creating a draft through SaveProject and linking it, the two on
| reusing the open project of a place (rule L94 is withdrawn, D-77) and the
| one on `projects.create`, a permission §8 removes.
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
 * plus an optional `center_ids` list). Every row's responsible has
 * acknowledged being in charge unless the row says `acknowledged => false`.
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
        $acknowledged = $row['acknowledged'] ?? true;
        unset($row['center_ids'], $row['acknowledged']);

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

        if ($acknowledged) {
            $location->currentAssignment('responsible_employee_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
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

// ── Completing the rows ─────────────────────────────────────────────────

test('an approval creates no project at all and approves every complete row', function () {
    // One row per (proposal, city, program): the second place is another city.
    $homs = insertHrCity('Homs', 'حمص');
    $proposal = proposalWithRows([newRow(), ['city_id' => $homs, 'center_ids' => [insertHrCenter($homs, 'Hope Center Homs', 'مركز الأمل حمص')]]]);

    $result = approveWithRows($this->approver, $proposal);

    expect(DB::table('projects')->count())->toBe(0)
        ->and(DB::table('project_proposal')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($result->approvedLocationIds)->toHaveCount(2);

    foreach (rowsOf($proposal) as $row) {
        expect($row->status)->toBe(ProjectStatus::Approved->value)
            ->and($row->project_id)->toBeNull()
            ->and($row->start_date)->toBe('2026-11-01')
            ->and($row->end_date)->toBe('2027-10-31');
    }
});

test('a row whose responsible has not acknowledged is dated and left waiting', function () {
    $proposal = proposalWithRows([newRow(['acknowledged' => false])]);
    $this->actingAs($this->approver);

    approveWithRows($this->approver, $proposal);
    $result = app(MaterialiseProposalLocations::class)->handle($proposal->fresh(), $this->approver->id);

    expect($result->approvedLocationIds)->toBe([])
        ->and($result->waitingForOwnerLocationIds)->toBe([(int) rowsOf($proposal)[0]->id])
        ->and(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value)
        ->and(rowsOf($proposal)[0]->start_date)->toBe('2026-11-01');
});

test('a row already approved is skipped, so a second run writes nothing', function () {
    $proposal = proposalWithRows([newRow()]);
    $this->actingAs($this->approver);

    $first = app(MaterialiseProposalLocations::class)->handle($proposal, $this->approver->id);
    $second = app(MaterialiseProposalLocations::class)->handle($proposal->fresh(), $this->approver->id);

    // The proposal has no grant dates yet, so the first run only reports.
    expect($second->approvedLocationIds)->toBe([])
        ->and($second->waitingForOwnerLocationIds)->toBe($first->waitingForOwnerLocationIds)
        ->and(DB::table('projects')->count())->toBe(0);
});

// ── Completeness ────────────────────────────────────────────────────────

test('a row with no program blocks the approval and writes nothing', function () {
    $proposal = proposalWithRows([newRow(['program_id' => null])]);
    Event::fake([ProposalStatusChanged::class]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal));

    expect($errors['locations_complete'])->toBe([__('proposals.locations_incomplete')])
        ->and(DB::table('projects')->count())->toBe(0)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(0);

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('a new row without a center blocks the approval', function () {
    $proposal = proposalWithRows([newRow(['center_ids' => []])]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal));

    expect($errors['locations_complete'])->toBe([__('proposals.locations_incomplete')])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

// ── Somebody must answer for the place ──────────────────────────────────

test('a row whose program has no manager and no responsible is refused', function () {
    $this->program->forceFill(['manager_id' => null])->save();
    $proposal = proposalWithRows([newRow(['responsible_employee_id' => null])]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal));

    expect($errors['locations_complete'])->toBe([__('proposals.locations_incomplete')])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the materialiser refuses a row that names neither a responsible nor a program manager', function () {
    $this->program->forceFill(['manager_id' => null])->save();
    $proposal = proposalWithRows([newRow(['responsible_employee_id' => null])]);
    $this->actingAs($this->approver);

    $errors = refusedRowsApproval(fn () => app(MaterialiseProposalLocations::class)->handle($proposal, $this->approver->id));

    expect($errors['locations_complete'])->toBe([__('proposals.location_owner_required', ['city' => 'Aleppo'])])
        ->and(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value);
});

// ── Idempotency, lock, rollback ─────────────────────────────────────────

test('a double submit approves once: one note, one event, no project', function () {
    $proposal = proposalWithRows([newRow()]);
    Event::fake([ProposalStatusChanged::class]);

    approveWithRows($this->approver, $proposal);
    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal->fresh(), expectedFrom: ProposalStatus::Submitted));

    expect($errors['status'][0])->toBe(__('proposals.already_approved'))
        ->and($proposal->statusNotes()->count())->toBe(1)
        ->and(DB::table('projects')->count())->toBe(0);

    Event::assertDispatchedTimes(ProposalStatusChanged::class, 1);
});

test('a failure after the approval rolls the rows back and dispatches nothing', function () {
    $proposal = proposalWithRows([newRow()]);
    Event::fake([ProposalStatusChanged::class]);

    try {
        DB::transaction(function () use ($proposal): void {
            approveWithRows($this->approver, $proposal);

            throw new RuntimeException('Caller failed after approval.');
        });
    } catch (RuntimeException) {
    }

    expect(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value)
        ->and(rowsOf($proposal)[0]->start_date)->toBeNull()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);

    Event::assertNotDispatched(ProposalStatusChanged::class);
});

test('a refused donor input leaves the rows untouched', function () {
    $proposal = proposalWithRows([newRow()]);

    $errors = refusedRowsApproval(fn () => approveWithRows($this->approver, $proposal, ['approval_note' => 'short']));

    expect($errors)->toHaveKey('approval_note')
        ->and(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value)
        ->and(rowsOf($proposal)[0]->start_date)->toBeNull();
});

// ── Authorization ───────────────────────────────────────────────────────

test('approving without proposals.approve is forbidden and writes nothing', function () {
    $proposal = proposalWithRows([newRow()]);

    expect(fn () => approveWithRows(userWithRole('Team Member'), $proposal))->toThrow(AuthorizationException::class);

    expect(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the materialiser itself refuses a user who may not approve the proposal', function () {
    $proposal = proposalWithRows([newRow()]);
    $this->actingAs(userWithRole('Team Member'));

    expect(fn () => app(MaterialiseProposalLocations::class)->handle($proposal, auth()->id()))->toThrow(AuthorizationException::class);

    expect(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value);
});

// ── Preview ─────────────────────────────────────────────────────────────

test('the preview counts the places that would be approved, those waiting and those without a program, and writes nothing', function () {
    $homs = insertHrCity('Homs', 'حمص');
    $homsCenter = insertHrCenter($homs, 'Hope Center Homs', 'مركز الأمل حمص');
    $proposal = proposalWithRows([
        newRow(),
        ['city_id' => $homs, 'center_ids' => [$homsCenter], 'acknowledged' => false],
        ['city_id' => $homs, 'program_id' => null, 'center_ids' => [$homsCenter]],
    ]);
    $rows = rowsOf($proposal);
    $this->actingAs($this->approver);

    $preview = app(MaterialiseProposalLocations::class)->preview($proposal);

    expect($preview->approvedLocationIds)->toBe([(int) $rows[0]->id])
        ->and($preview->waitingForOwnerLocationIds)->toBe([(int) $rows[1]->id])
        ->and($preview->waitingForProgramLocationIds)->toBe([(int) $rows[2]->id])
        ->and($preview->warnings)->toBe([])
        ->and(DB::table('projects')->count())->toBe(0)
        ->and(rowsOf($proposal)[0]->status)->toBe(ProjectStatus::Draft->value);
});
