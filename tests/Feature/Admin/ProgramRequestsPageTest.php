<?php

use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\ProgramRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Support\ProposalLocationRows;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| W-34a slice 3: /admin/program-requests lists the pending program requests
| for `programs.update` holders, who link the HR program HR created (the row
| takes it, the proposal can be approved) or decline the request with a
| reason. On a proposal the donor already approved, linking also gives the
| row its project, dated by the grant.
*/

beforeEach(function () {
    $this->travelTo('2026-11-12 10:00:00');

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->program = Program::factory()->create();
    $this->manager = userWithRole('Managers');
});

/**
 * A pending request on a new Aleppo row of a proposal in the given status,
 * the row run from the Aleppo center by the program's manager.
 */
function pendingProgramRequest(ProposalStatus $status = ProposalStatus::Submitted, string $name = 'Alternative Energy'): ProgramRequest
{
    return Activity::withoutRecording(function () use ($status, $name): ProgramRequest {
        $proposal = Proposal::factory()->create([
            'status' => $status,
            'planned_date_begin' => '2026-11-01',
            'planned_date_end' => '2027-10-31',
            'actual_date_begin' => $status->isApprovedByDonor() ? '2026-11-01' : null,
            'actual_date_end' => $status->isApprovedByDonor() ? '2027-10-31' : null,
        ]);

        $location = ProposalLocation::factory()->for($proposal)->isNew()->create([
            'city_id' => test()->aleppo,
            'program_id' => null,
            'responsible_employee_id' => test()->program->manager_id,
        ]);
        $location->centers()->create(['center_id' => test()->aleppoCenter]);

        return ProgramRequest::factory()->create([
            'proposal_location_id' => $location->id,
            'name_en' => $name,
            'name_ar' => 'برنامج '.$name,
        ]);
    });
}

test('the page lists pending requests with their proposal and city, not handled ones', function () {
    $pending = pendingProgramRequest(name: 'Wells');
    $handled = pendingProgramRequest(name: 'Parenting');
    $handled->forceFill(['status' => 'declined'])->save();

    Livewire::actingAs($this->manager)
        ->test('pages::admin.program-requests')
        ->assertSee('Wells')
        ->assertSee($pending->location->proposal->name)
        ->assertSee('Aleppo')
        ->assertDontSee('Parenting');
});

test('people without programs.update cannot open the page', function () {
    $this->actingAs(userWithRole('Public Relations'))
        ->get(route('admin.program-requests.index'))
        ->assertForbidden();
});

test('a manager reaches the page from the admin navigation', function () {
    $this->actingAs($this->manager)
        ->get(route('admin.program-requests.index'))
        ->assertOk()
        ->assertSee(route('admin.program-requests.index'), false)
        ->assertSeeText('Program requests');
});

test('linking gives the row its program, marks the request created and records it on the proposal', function () {
    $request = pendingProgramRequest();
    $proposal = $request->location->proposal;

    Livewire::actingAs($this->manager)
        ->test('pages::admin.program-requests')
        ->call('openLinkModal', $request->id)
        ->assertSet('showLinkModal', true)
        ->set('programId', (string) $this->program->id)
        ->call('linkRequest')
        ->assertHasNoErrors()
        ->assertSet('showLinkModal', false);

    $stored = DB::table('program_requests')->find($request->id);

    expect($stored->status)->toBe('created')
        ->and((int) $stored->hr_program_id)->toBe($this->program->id)
        ->and((int) DB::table('proposal_locations')->where('id', $request->proposal_location_id)->value('program_id'))->toBe($this->program->id)
        ->and(ProposalLocationRows::incompleteRowIds($proposal))->toBe([])
        ->and(DB::table('projects')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'program_request.linked')->sole())
        ->subject_id->toBe($proposal->id)
        ->properties->toMatchArray(['name' => 'Alternative Energy', 'hr_program_id' => $this->program->id]);
});

test('linking needs a program and refuses one the proposal already has in that city', function () {
    $request = pendingProgramRequest();
    Activity::withoutRecording(fn () => ProposalLocation::factory()->for($request->location->proposal)->create([
        'city_id' => $this->aleppo,
        'program_id' => $this->program->id,
        'sort' => 1,
    ]));

    Livewire::actingAs($this->manager)
        ->test('pages::admin.program-requests')
        ->call('openLinkModal', $request->id)
        ->call('linkRequest')
        ->assertHasErrors(['programId'])
        ->set('programId', (string) $this->program->id)
        ->call('linkRequest')
        ->assertHasErrors(['programId'])
        ->assertSet('showLinkModal', true);

    expect(DB::table('program_requests')->find($request->id)->status)->toBe('pending');
});

test('declining needs a reason, keeps the row waiting and records the reason on the proposal', function () {
    $request = pendingProgramRequest();
    $proposal = $request->location->proposal;

    Livewire::actingAs($this->manager)
        ->test('pages::admin.program-requests')
        ->call('openDeclineModal', $request->id)
        ->assertSet('showDeclineModal', true)
        ->call('declineRequest')
        ->assertHasErrors(['declineReason'])
        ->set('declineReason', 'HR will not open a separate energy program this year.')
        ->call('declineRequest')
        ->assertHasNoErrors()
        ->assertSet('showDeclineModal', false)
        ->assertDontSee('Alternative Energy');

    expect(DB::table('program_requests')->find($request->id)->status)->toBe('declined')
        ->and(DB::table('proposal_locations')->where('id', $request->proposal_location_id)->value('program_id'))->toBeNull()
        ->and(ProposalLocationRows::incompleteRowIds($proposal))->toBe([$request->proposal_location_id])
        ->and(Activity::query()->where('event', 'program_request.declined')->sole())
        ->subject_id->toBe($proposal->id)
        ->properties->toMatchArray(['reason' => 'HR will not open a separate energy program this year.']);
});

test('a request someone already handled is not linked again', function () {
    $request = pendingProgramRequest();

    $page = Livewire::actingAs($this->manager)
        ->test('pages::admin.program-requests')
        ->call('openLinkModal', $request->id)
        ->set('programId', (string) $this->program->id);

    $request->forceFill(['status' => 'declined'])->save();

    $page->call('linkRequest')->assertSet('showLinkModal', false);

    expect(DB::table('program_requests')->find($request->id)->status)->toBe('declined')
        ->and(DB::table('proposal_locations')->where('id', $request->proposal_location_id)->value('program_id'))->toBeNull();
});

test('a manager linking on an approved proposal gives the row its project with the grant dates, and keeps no approval rights', function () {
    $request = pendingProgramRequest(ProposalStatus::Approved);
    $proposal = $request->location->proposal;

    Livewire::actingAs($this->manager)
        ->test('pages::admin.program-requests')
        ->call('openLinkModal', $request->id)
        ->set('programId', (string) $this->program->id)
        ->call('linkRequest')
        ->assertHasNoErrors();

    $project = DB::table('projects')->sole();

    expect((int) $project->program_id)->toBe($this->program->id)
        ->and((int) $project->center_id)->toBe($this->aleppoCenter)
        ->and($project->status)->toBe('draft')
        ->and(substr((string) $project->start_date, 0, 10))->toBe('2026-11-01')
        ->and(substr((string) $project->end_date, 0, 10))->toBe('2027-10-31')
        ->and((int) DB::table('proposal_locations')->where('id', $request->proposal_location_id)->value('project_id'))->toBe($project->id)
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->pluck('project_id')->all())->toBe([$project->id])
        ->and($this->manager->can('approve', $proposal->refresh()))->toBeFalse()
        ->and($this->manager->can('create', Project::class))->toBeFalse();
});
