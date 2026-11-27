<?php

use App\Actions\Locations\ActivateLocation;
use App\Actions\Locations\ApproveLocationFromProposal;
use App\Actions\Locations\ArchiveLocation;
use App\Actions\Locations\CloseLocation;
use App\Actions\Locations\ReopenLocationAsDraft;
use App\Actions\Locations\RestoreLocation;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Events\LocationActivated;
use App\Models\Assignment;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| contracts.md §3 (Y-17): the location row holds the statuses, and these
| actions are their only writers. The row is of exactly one proposal (D-93),
| so its dates come from that proposal and nothing is merged across grants.
| Built from ProjectLifecycleTest and ProjectDraftLifecycleTest.
*/

/**
 * A complete row of a donor-approved proposal, with its responsible asked to
 * acknowledge but not yet answering.
 *
 * @param  array<string, mixed>  $rowAttributes
 * @param  array<string, mixed>  $proposalAttributes
 */
function approvableRow(array $rowAttributes = [], array $proposalAttributes = []): ProposalLocation
{
    $cityId = insertHrCity('Place '.Str::random(5), 'مكان');
    $employeeId = insertHrEmployee($cityId);
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Approved,
        'actual_date_begin' => '2026-01-01',
        'actual_date_end' => '2026-12-31',
        ...$proposalAttributes,
    ]);

    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $cityId,
        'program_id' => Program::factory()->create(['manager_id' => $employeeId])->id,
        'responsible_employee_id' => $employeeId,
        ...$rowAttributes,
    ]);
    $row->centers()->create(['center_id' => insertHrCenter($cityId, 'Center '.Str::random(6))]);

    return $row->fresh();
}

/**
 * Let the row's responsible acknowledge being put in charge.
 */
function acknowledgeRow(ProposalLocation $row): Assignment
{
    $assignment = $row->currentAssignment('responsible_employee_id');

    $assignment->forceFill([
        'status' => AssignmentStatus::Acknowledged->value,
        'responded_at' => now(),
    ])->save();

    return $assignment;
}

beforeEach(function () {
    $this->approver = userWithRole('Public Relations Manager');
    $this->manager = userWithRole('General Manager');
    $this->meal = userWithRole('MEAL');
});

test('a new row is a draft with no dates', function () {
    $row = ProposalLocation::factory()->create();

    expect($row->fresh())->status->toBe(ProjectStatus::Draft)->start_date->toBeNull()->end_date->toBeNull();
});

test('writing a row asks its responsible to acknowledge being in charge', function () {
    $row = approvableRow();

    expect($row->currentAssignment('responsible_employee_id'))->not->toBeNull()
        ->and($row->currentAssignment('responsible_employee_id')->status)->toBe(AssignmentStatus::Pending)
        ->and($row->isAssignmentAcknowledged('responsible_employee_id'))->toBeFalse();
});

test('approval dates a waiting row but leaves it a draft', function () {
    $row = approvableRow();

    $this->actingAs($this->approver);

    expect(app(ApproveLocationFromProposal::class)->handle($row, 'proposal_approval'))->toBeFalse();

    expect($row->fresh())
        ->status->toBe(ProjectStatus::Draft)
        ->start_date->toDateString()->toBe('2026-01-01')
        ->end_date->toDateString()->toBe('2026-12-31');
});

test('the responsible acknowledging an already approved proposal approves the row', function () {
    $row = approvableRow();

    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row, 'proposal_approval');

    $assignment = acknowledgeRow($row);
    $this->actingAs(User::factory()->create(['employee_id' => $assignment->employee_id]));

    expect(app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'owner_acknowledgement'))->toBeTrue();
    expect($row->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('an acknowledged row is approved the moment its proposal is', function () {
    $row = approvableRow();
    acknowledgeRow($row);

    $this->actingAs($this->approver);

    expect(app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval'))->toBeTrue();
    expect($row->fresh())->status->toBe(ProjectStatus::Approved)->start_date->not->toBeNull();
});

test('approving a row twice writes one change', function () {
    $row = approvableRow();
    acknowledgeRow($row);

    $this->actingAs($this->approver);
    $approve = fn () => app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    expect($approve())->toBeTrue()->and($approve())->toBeFalse();
    expect($row->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('a row whose proposal is not approved by the donor is left alone', function () {
    $row = approvableRow([], ['status' => ProposalStatus::Submitted, 'actual_date_begin' => null, 'actual_date_end' => null]);
    acknowledgeRow($row);

    $this->actingAs($this->approver);

    expect(app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval'))->toBeFalse();
    expect($row->fresh())->status->toBe(ProjectStatus::Draft)->start_date->toBeNull();
});

test('a row whose responsible rejected the appointment is skipped', function () {
    $row = approvableRow();
    $row->currentAssignment('responsible_employee_id')->forceFill(['status' => AssignmentStatus::Rejected->value])->save();

    $this->actingAs($this->approver);

    expect(app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval'))->toBeFalse();
    expect($row->fresh())->status->toBe(ProjectStatus::Draft)->start_date->toBeNull();
});

test('approval takes the dates of the row own proposal and never shortens them', function () {
    $row = approvableRow();
    acknowledgeRow($row);

    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    // The grant was shortened at the donor's request; the row keeps the
    // window it already committed to (contracts.md §3).
    DB::table('proposals')->where('id', $row->proposal_id)->update(['actual_date_end' => '2026-06-30']);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    expect($row->fresh()->end_date->toDateString())->toBe('2026-12-31');
});

test('an approved and dated row activates and dispatches LocationActivated', function () {
    Event::fake([LocationActivated::class]);

    $row = approvableRow();
    acknowledgeRow($row);
    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    $this->actingAs($this->meal);
    app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation');

    expect($row->fresh()->status)->toBe(ProjectStatus::Active);
    Event::assertDispatched(LocationActivated::class, fn (LocationActivated $event): bool => $event->row->is($row));
});

test('activating the last row of a proposal moves the proposal to active', function () {
    $row = approvableRow();
    $second = ProposalLocation::factory()->complete()->create(['proposal_id' => $row->proposal_id, 'city_id' => $row->city_id]);
    $second->centers()->create(['center_id' => insertHrCenter((int) $row->city_id, 'Second '.Str::random(6))]);

    foreach ([$row, $second] as $each) {
        acknowledgeRow($each->fresh());
        $this->actingAs($this->approver);
        app(ApproveLocationFromProposal::class)->handle($each->fresh(), 'proposal_approval');
    }

    $this->actingAs($this->meal);
    app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation');

    expect(Proposal::query()->find($row->proposal_id)->status)->toBe(ProposalStatus::Approved);

    app(ActivateLocation::class)->handle($second->fresh(), 'template_confirmation');

    expect(Proposal::query()->find($row->proposal_id)->status)->toBe(ProposalStatus::Active);
});

test('an archived row does not hold the proposal back from activating', function () {
    $row = approvableRow();
    $archived = ProposalLocation::factory()->complete()->create(['proposal_id' => $row->proposal_id, 'city_id' => $row->city_id]);

    acknowledgeRow($row);
    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    $this->actingAs($this->manager);
    app(ArchiveLocation::class)->handle($archived);

    $this->actingAs($this->meal);
    app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation');

    expect(Proposal::query()->find($row->proposal_id)->status)->toBe(ProposalStatus::Active);
});

test('activation is refused for a row that is not approved', function () {
    $row = approvableRow();

    $this->actingAs($this->meal);

    expect(fn () => app(ActivateLocation::class)->handle($row, 'template_confirmation'))
        ->toThrow(ValidationException::class, 'Only an approved project can be activated.');
    expect($row->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('activating a row twice raises one event', function () {
    Event::fake([LocationActivated::class]);

    $row = approvableRow();
    acknowledgeRow($row);
    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    $this->actingAs($this->meal);
    app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation');

    expect(fn () => app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation'))->toThrow(ValidationException::class);
    Event::assertDispatchedTimes(LocationActivated::class, 1);
});

test('activation needs the template right', function () {
    $row = approvableRow();
    acknowledgeRow($row);
    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    $this->actingAs(User::factory()->create());

    expect(fn () => app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation'))->toThrow(AuthorizationException::class);
});

test('only an active row can be closed', function () {
    $row = ProposalLocation::factory()->active()->complete()->create();

    $this->actingAs($this->manager);
    app(CloseLocation::class)->handle($row);

    expect($row->fresh())->status->toBe(ProjectStatus::Closed)->start_date->not->toBeNull();

    expect(fn () => app(CloseLocation::class)->handle($row->fresh()))
        ->toThrow(ValidationException::class, 'Only an active project can be closed.');
});

test('closing needs the right to change the row', function () {
    $row = ProposalLocation::factory()->active()->complete()->create();

    $this->actingAs(User::factory()->create());

    expect(fn () => app(CloseLocation::class)->handle($row))->toThrow(AuthorizationException::class);
    expect($row->fresh()->status)->toBe(ProjectStatus::Active);
});

test('archiving keeps the dates for a later restore', function () {
    $row = ProposalLocation::factory()->active()->complete()->create();

    $this->actingAs($this->manager);
    app(ArchiveLocation::class)->handle($row);

    expect($row->fresh())->status->toBe(ProjectStatus::Archived)->start_date->not->toBeNull();
});

test('restoring an archived row follows D-27', function (bool $dated, ProjectStatus $expected) {
    $row = ProposalLocation::factory()->complete()->create([
        'status' => ProjectStatus::Archived,
        ...($dated ? ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'] : []),
    ]);

    $this->actingAs($this->manager);

    expect(app(RestoreLocation::class)->handle($row))->toBe($expected);
    expect($row->fresh()->status)->toBe($expected);
})->with([
    'dated' => [true, ProjectStatus::Closed],
    'dateless' => [false, ProjectStatus::Draft],
]);

test('a second row at the same place no longer blocks a restore', function () {
    // The open-project rule L94 is withdrawn (Y-17 [contract], D-77): two
    // proposals may each deliver at one city, program and center.
    $open = approvableRow();
    $archived = ProposalLocation::factory()->create([
        'city_id' => $open->city_id,
        'program_id' => $open->program_id,
        'responsible_employee_id' => $open->responsible_employee_id,
        'status' => ProjectStatus::Archived,
    ]);
    $archived->centers()->create(['center_id' => $open->centerIds()[0]]);

    $this->actingAs($this->manager);

    expect(app(RestoreLocation::class)->handle($archived))->toBe(ProjectStatus::Draft);
});

test('restoring a row that is not archived changes nothing', function () {
    $row = ProposalLocation::factory()->active()->complete()->create();

    $this->actingAs($this->manager);

    expect(fn () => app(RestoreLocation::class)->handle($row))
        ->toThrow(ValidationException::class, 'Only an archived project can be restored.');
    expect($row->fresh()->status)->toBe(ProjectStatus::Active);
});

test('restore needs the archive right', function () {
    $row = ProposalLocation::factory()->complete()->create(['status' => ProjectStatus::Archived]);

    $this->actingAs(User::factory()->create());

    expect(fn () => app(RestoreLocation::class)->handle($row))->toThrow(AuthorizationException::class);
});

test('a closed row reopens as a dateless draft', function () {
    $row = ProposalLocation::factory()->closed()->complete()->create();

    $this->actingAs($this->approver);
    app(ReopenLocationAsDraft::class)->handle($row);

    expect($row->fresh())->status->toBe(ProjectStatus::Draft)->start_date->toBeNull()->end_date->toBeNull();
});

test('reopening a row that is already a draft is a no-op, and any other status is refused', function () {
    $draft = ProposalLocation::factory()->complete()->create();
    $active = ProposalLocation::factory()->active()->complete()->create();

    $this->actingAs($this->approver);
    app(ReopenLocationAsDraft::class)->handle($draft);

    expect($draft->fresh()->status)->toBe(ProjectStatus::Draft);
    expect(fn () => app(ReopenLocationAsDraft::class)->handle($active))->toThrow(ValidationException::class);
    expect($active->fresh()->status)->toBe(ProjectStatus::Active);
});

test('a concurrent activate and close never both win', function () {
    $row = approvableRow();
    acknowledgeRow($row);
    $this->actingAs($this->approver);
    app(ApproveLocationFromProposal::class)->handle($row->fresh(), 'proposal_approval');

    $this->actingAs($this->meal);
    app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation');

    $this->actingAs($this->manager);
    app(CloseLocation::class)->handle($row->fresh());

    // The second activation re-reads the locked row and finds it closed.
    $this->actingAs($this->meal);

    expect(fn () => app(ActivateLocation::class)->handle($row->fresh(), 'template_confirmation'))->toThrow(ValidationException::class);
    expect($row->fresh()->status)->toBe(ProjectStatus::Closed);
});

test('approve and acknowledge lock the row before its proposal', function () {
    // The two paths into ApproveLocationFromProposal must take the same
    // locks in the same order, or two users answering at once deadlock
    // (quality-bar.md §2). Asserted on the source, because a real deadlock
    // needs two connections.
    $source = file_get_contents(app_path('Actions/Locations/ApproveLocationFromProposal.php'));

    $rowLock = strpos($source, 'ProposalLocation::query()');
    $proposalLock = strpos($source, 'Proposal::query()');

    expect($rowLock)->not->toBeFalse()
        ->and($proposalLock)->not->toBeFalse()
        ->and($rowLock)->toBeLessThan($proposalLock);
});

test('an unknown approval path is a programming error', function () {
    $row = approvableRow();

    $this->actingAs($this->approver);

    expect(fn () => app(ApproveLocationFromProposal::class)->handle($row, 'by_hand'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(ActivateLocation::class)->handle($row, 'by_hand'))->toThrow(InvalidArgumentException::class);
});

test('the lifecycle messages read in Arabic', function () {
    app()->setLocale('ar');

    $row = ProposalLocation::factory()->complete()->create(['status' => ProjectStatus::Archived]);
    $active = ProposalLocation::factory()->active()->complete()->create();

    $this->actingAs($this->manager);
    app(RestoreLocation::class)->handle($row);

    expect(fn () => app(RestoreLocation::class)->handle($row->fresh()))
        ->toThrow(ValidationException::class, 'لا يُستعاد إلا مشروع مؤرشف');

    app(CloseLocation::class)->handle($active);

    expect(fn () => app(CloseLocation::class)->handle($active->fresh()))
        ->toThrow(ValidationException::class, 'لا يُغلق إلا مشروع نشط');
});
