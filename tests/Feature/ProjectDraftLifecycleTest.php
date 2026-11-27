<?php

use App\Actions\Proposals\ApproveProposal;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| contracts.md §3: a place is a dateless draft until its grant is approved,
| and dates arrive only with approval. Y-17 moved that invariant onto the
| location row.
|
| Ten cases went with the features they guarded, named in the Y-17 handoff:
| the six that drove the create form and an active place's editable dates
| (the [contract] drops `projects.create` and the grant sets the period), the
| one that asserted rule L94 on restore (withdrawn, D-77), and the four on
| restore and archive that `LocationLifecycleTest` now covers transition by
| transition.
*/

/**
 * Rows that hold dates without a donor-approved proposal behind them.
 *
 * The invariant of §3, on the row (Y-17): a draft holds dates only while its
 * proposal is donor-approved and it waits for its responsible to
 * acknowledge. A draft whose responsible has already acknowledged would have
 * been approved, so it is a violation too.
 *
 * @return list<int>
 */
function rowsHoldingDatesWithoutApproval(): array
{
    $approved = array_map(
        fn (ProposalStatus $status): string => $status->value,
        array_filter(ProposalStatus::cases(), fn (ProposalStatus $status): bool => $status->isApprovedByDonor()),
    );

    $dated = ProposalLocation::query()
        ->where('status', ProjectStatus::Draft->value)
        ->where(fn ($query) => $query->whereNotNull('start_date')->orWhereNotNull('end_date'))
        ->with('ownerAssignment')
        ->get();

    $behindApproval = DB::table('proposals')->whereIn('status', $approved)->pluck('id')->map(intval(...))->all();

    $ids = $dated
        ->filter(fn (ProposalLocation $row): bool => ! in_array((int) $row->proposal_id, $behindApproval, true)
            || ($row->responsible_employee_id !== null && $row->isAssignmentAcknowledged('responsible_employee_id')))
        ->modelKeys();

    sort($ids);

    /** @var list<int> $ids */
    return $ids;
}

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
});

test('the edit dialog never offers a place its dates', function () {
    $draft = ProposalLocation::factory()->complete()->create(['city_id' => $this->cityId]);
    $active = ProposalLocation::factory()->active()->complete()->create(['city_id' => $this->cityId]);

    // Neither a draft nor a dated place: the grant sets the period, so the
    // dialog says where it comes from instead of offering an input.
    foreach ([$draft, $active] as $row) {
        Livewire::actingAs($this->user)->test('pages::projects.index')
            ->call('openEditModal', $row->id)
            ->assertDontSeeHtml('wire:model="startDate"')
            ->assertDontSeeHtml('wire:model="endDate"');
    }

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $draft->id)
        ->assertSee('Project dates are set when the proposal is approved.');

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $active->id)
        ->assertSee('Project dates come from the approved grant');
});

test('editing a draft place keeps its status and writes no dates', function () {
    $row = ProposalLocation::factory()->complete()->create(['city_id' => $this->cityId]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $row->id)
        ->set('description', 'Two wells and a pump house.')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($row->fresh())
        ->description->toBe('Two wells and a pump house.')
        ->status->toBe(ProjectStatus::Draft)
        ->start_date->toBeNull();
    expect(rowsHoldingDatesWithoutApproval())->toBe([]);
});

test('the retire migration maps legacy submitted and approved projects', function () {
    $row = fn (string $status, ?string $start) => projectWithRow([
        'status' => $status,
        'start_date' => $start,
        'end_date' => $start === null ? null : '2027-01-01',
    ])->id;

    $ids = [
        'submitted undated' => $row('submitted', null),
        'submitted dated' => $row('submitted', '2026-01-01'),
        'approved undated' => $row('approved', null),
        'approved dated' => $row('approved', '2026-01-01'),
        'active' => $row('active', '2026-01-01'),
        'draft' => $row('draft', null),
    ];

    (require database_path('migrations/2026_10_14_121002_retire_submitted_project_status.php'))->up();

    $status = fn (string $key): string => DB::table('projects')->where('id', $ids[$key])->value('status');

    expect($status('submitted undated'))->toBe('draft')
        ->and($status('submitted dated'))->toBe('active')
        ->and($status('approved undated'))->toBe('draft')
        ->and($status('approved dated'))->toBe('active')
        ->and($status('active'))->toBe('active')
        ->and($status('draft'))->toBe('draft');
    expect(DB::table('projects')->where('status', 'submitted')->count())->toBe(0);
});

test('a draft place with dates is only allowed behind a donor-approved proposal', function () {
    $loose = ProposalLocation::factory()->complete()->create([
        'proposal_id' => Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft])->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    ProposalLocation::factory()->complete()->create([
        'proposal_id' => Proposal::factory()->create(['status' => ProposalStatus::Approved])->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    expect(rowsHoldingDatesWithoutApproval())->toBe([$loose->id]);
});

test('a draft place page shows the no-active-proposal badge', function () {
    // Y-16, D-93: the badge reads the ONE proposal behind the row, so the row
    // is built on a terminal proposal to have none in play, then on a live one.
    $draft = Project::factory()->create();
    $row = rowFor($draft, Proposal::factory()->create(['status' => ProposalStatus::Rejected]));

    Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $row->slug])
        ->assertSeeHtml('data-test="no-active-proposal"');

    DB::table('proposals')->where('id', $row->proposal_id)->update(['status' => ProposalStatus::ProposalDraft->value]);

    Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $row->slug])
        ->assertDontSeeHtml('data-test="no-active-proposal"');
});

test('the draft messages read in Arabic', function () {
    app()->setLocale('ar');

    $row = ProposalLocation::factory()->complete()->create(['city_id' => $this->cityId]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $row->id)
        ->assertSee('تُحدَّد تواريخ المشروع عند اعتماد المقترح');

    $draft = Project::factory()->create();
    $terminal = rowFor($draft, Proposal::factory()->create(['status' => ProposalStatus::Rejected]));

    Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $terminal->slug])
        ->assertSee('لا يوجد مقترح فعّال');
});

test('the draft invariant holds after an approval: waiting rows hold dates, acknowledged ones are approved', function () {
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Submitted,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);

    $place = fn (): ProposalLocation => tap(ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => insertHrCity('Place '.Str::random(5), 'مكان'),
        'program_id' => $this->program->id,
        'responsible_employee_id' => $this->owner,
        'target_beneficiaries' => 10,
    ]), fn (ProposalLocation $row) => $row->centers()->create(['center_id' => insertHrCenter((int) $row->city_id, 'C'.Str::random(5))]));

    $waiting = $place();
    $acknowledged = $place();
    $acknowledged->currentAssignment('responsible_employee_id')->forceFill(['status' => AssignmentStatus::Acknowledged])->save();

    $this->actingAs(userWithRole('Public Relations Manager'));
    app(ApproveProposal::class)->handle($proposal, ProposalStatus::Submitted, [
        'actual_date_begin' => today()->toDateString(),
        'actual_date_end' => today()->addYear()->toDateString(),
        'decision_date' => today()->toDateString(),
        'approval_note' => 'The donor confirmed by email.',
    ]);

    expect($waiting->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($waiting->fresh()->start_date)->not->toBeNull()
        ->and($acknowledged->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and(rowsHoldingDatesWithoutApproval())->toBe([]);
});
