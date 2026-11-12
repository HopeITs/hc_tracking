<?php

use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| W-34a slice 3: the proposal form enters the place itself as location rows
| (city, program or a new program request, responsible, centers) instead of a
| project picker; an approved proposal's rows still take centers; the register
| lists the proposals whose place is not known down to the center.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->aleppoSecondCenter = insertHrCenter($this->aleppo, 'Study Zone Aleppo', 'ستادي زون حلب');
    $this->homsCenter = insertHrCenter($this->homs, 'Hope Center Homs', 'مركز الأمل حمص');
    $this->employee = insertHrEmployee($this->aleppo);
    $this->program = Program::factory()->create(['manager_id' => $this->employee]);
});

function locationRowsForm(): Testable
{
    return Livewire::actingAs(test()->user)->test('pages::proposals.index')
        ->call('openCreateModal')
        ->set('name', 'Winter Shelter Response')
        ->set('donorId', (string) Donor::factory()->create()->id)
        ->set('plannedDateBegin', '2026-11-01')
        ->set('plannedDateEnd', '2027-10-31');
}

test('a new proposal is created from location rows with their centers, without a project', function () {
    locationRowsForm()
        ->assertDontSeeHtml('data-test="proposal-project-picker"')
        ->assertSeeHtml('data-test="locations-section"')
        ->set('locations.0.city_id', (string) $this->aleppo)
        ->set('locations.0.program_id', (string) $this->program->id)
        ->assertSet('locations.0.responsible_employee_id', (string) $this->employee)
        ->set('locations.0.center_ids', [(string) $this->aleppoCenter, (string) $this->aleppoSecondCenter])
        ->call('addLocationRow')
        ->set('locations.1.city_id', (string) $this->homs)
        ->set('locations.1.program_id', 'new')
        ->set('locations.1.new_program_name_en', 'Youth Clubs')
        ->set('locations.1.new_program_name_ar', 'نوادي الشباب')
        ->set('locations.1.center_ids', [(string) $this->homsCenter])
        ->call('saveProposal')
        ->assertHasNoErrors()
        ->assertSet('showProposalModal', false);

    $proposal = Proposal::query()->sole();
    $rows = $proposal->locations()->with(['centers', 'programRequest'])->get();

    expect($proposal->status)->toBe(ProposalStatus::ConceptNote)
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->count())->toBe(0)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->city_id)->toBe($this->aleppo)
        ->and($rows[0]->program_id)->toBe($this->program->id)
        ->and($rows[0]->responsible_employee_id)->toBe($this->employee)
        ->and($rows[0]->centerIds())->toBe([$this->aleppoCenter, $this->aleppoSecondCenter])
        ->and($rows[1]->program_id)->toBeNull()
        ->and($rows[1]->programRequest->name_ar)->toBe('نوادي الشباب')
        ->and($rows[1]->centerIds())->toBe([$this->homsCenter]);
});

test('a create without any place is refused under the location rows, in English and Arabic', function () {
    locationRowsForm()
        ->call('saveProposal')
        ->assertHasErrors(['locations' => __('proposals.locations_required')])
        ->assertHasNoErrors(['projectIds'])
        ->assertSeeText(__('proposals.locations_required'));

    $this->user->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');

    locationRowsForm()
        ->assertSeeText(trans('proposals.add_location', [], 'ar'))
        ->call('saveProposal')
        ->assertSeeText(trans('proposals.locations_required', [], 'ar'));

    expect(trans('proposals.locations_required', [], 'ar'))->not->toBe(trans('proposals.locations_required', [], 'en'))
        ->and(Proposal::query()->count())->toBe(0);
});

test('a row offers only its city centers and drops them when the city changes', function () {
    $form = locationRowsForm()
        ->set('locations.0.city_id', (string) $this->aleppo)
        ->assertSeeText('Hope Center Aleppo')
        ->assertDontSeeText('Hope Center Homs')
        ->set('locations.0.center_ids', [(string) $this->aleppoCenter])
        ->set('locations.0.city_id', (string) $this->homs);

    $form->assertSet('locations.0.center_ids', [])
        ->assertSeeText('Hope Center Homs')
        ->assertDontSeeText('Hope Center Aleppo');
});

test('a center outside the row city is refused by the save', function () {
    locationRowsForm()
        ->set('locations.0.city_id', (string) $this->aleppo)
        ->set('locations.0.program_id', (string) $this->program->id)
        ->set('locations.0.center_ids', [(string) $this->homsCenter])
        ->call('saveProposal')
        ->assertHasErrors(['locations.0.center_ids' => __('proposals.location_center_not_in_city')]);

    expect(Proposal::query()->count())->toBe(0);
});

test('an approved proposal row of a linked project takes centers, keeping its city, program and project', function () {
    $project = Project::factory()->create(['city_id' => $this->aleppo, 'program_id' => $this->program->id, 'user_id' => $this->employee]);
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Approved]);
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 200]);
    $row = ProposalLocation::factory()->forProject($project)->create(['proposal_id' => $proposal->id]);

    Livewire::actingAs($this->user)->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->assertSet('locations.0.project', $project->name)
        ->assertSeeHtml('data-test="location-linked-project"')
        ->assertDontSeeHtml('data-test="remove-location"')
        ->call('removeLocationRow', 0)
        ->assertCount('locations', 1)
        ->set('locations.0.center_ids', [(string) $this->aleppoCenter])
        ->call('saveProposal')
        ->assertHasNoErrors();

    $row->refresh()->load('centers');

    expect($row->centerIds())->toBe([$this->aleppoCenter])
        ->and($row->project_id)->toBe($project->id)
        ->and($row->city_id)->toBe($this->aleppo)
        ->and($row->program_id)->toBe($this->program->id)
        ->and($proposal->locations()->count())->toBe(1)
        ->and($proposal->refresh()->status)->toBe(ProposalStatus::Approved);
});

test('editing a proposal without rows saves its details without asking for a place', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Approved, 'name' => 'Old name']);

    Livewire::actingAs($this->user)->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->assertSet('locations', [])
        ->assertSeeHtml('data-test="locations-none-yet"')
        ->set('name', 'New name')
        ->call('saveProposal')
        ->assertHasNoErrors();

    expect($proposal->refresh()->name)->toBe('New name')
        ->and($proposal->locations()->count())->toBe(0);
});

test('the without-a-center filter lists exactly the project proposals with a row lacking a center or no row at all', function () {
    $complete = Proposal::factory()->create(['name' => 'Complete Place']);
    ProposalLocation::factory()->complete()->create(['proposal_id' => $complete->id, 'city_id' => $this->aleppo])
        ->centers()->create(['center_id' => $this->aleppoCenter]);

    $partial = Proposal::factory()->create(['name' => 'Partial Place']);
    ProposalLocation::factory()->complete()->create(['proposal_id' => $partial->id, 'city_id' => $this->aleppo])
        ->centers()->create(['center_id' => $this->aleppoCenter]);
    ProposalLocation::factory()->complete()->create(['proposal_id' => $partial->id, 'city_id' => $this->homs]);

    $empty = Proposal::factory()->create(['name' => 'No Place Yet']);
    $payroll = Proposal::factory()->payroll()->create(['name' => 'Payroll Line']);

    expect(Proposal::query()->withoutCenter()->orderBy('id')->pluck('id')->all())->toBe([$partial->id, $empty->id]);

    Livewire::actingAs($this->user)->test('pages::proposals.index')
        ->assertSeeText(__('finance_tables.flag_no_center'))
        ->set('flag', 'no_center')
        ->assertSeeText('Partial Place')
        ->assertSeeText('No Place Yet')
        ->assertDontSeeText('Complete Place')
        ->assertDontSeeText($payroll->name);
});
