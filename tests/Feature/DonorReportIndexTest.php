<?php

use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\User;
use Livewire\Livewire;

/*
| The donor reports register (W-16): every report the viewer may open, as
| DonorReportPolicy::view decides, with donor, proposal and state filters
| and a search by proposal name.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');

    [$this->open, $this->sent] = Activity::withoutRecording(function (): array {
        $donor = Donor::factory()->create(['name' => 'Global Fund for Children']);
        $proposal = Proposal::factory()->running()->create(['donor_id' => $donor->id, 'name' => 'Safe Schools']);
        $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $donor->id]);

        $otherDonor = Donor::factory()->create(['name' => 'Nordic Trust']);
        $otherProposal = Proposal::factory()->running()->create(['donor_id' => $otherDonor->id, 'name' => 'Winter Shelter']);
        $otherAgreement = DonorProposal::factory()->create(['proposal_id' => $otherProposal->id, 'donor_id' => $otherDonor->id]);

        return [
            DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Q1 narrative', 'due_on' => '2026-11-20']),
            DonorReport::factory()->submitted()->create(['donor_proposal_id' => $otherAgreement->id, 'title' => 'Final financial', 'due_on' => '2026-10-01']),
        ];
    });
});

test('a funding-agreements.view holder sees every report with its proposal name', function () {
    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::donor-reports.index')
        ->assertOk()
        ->assertSeeText('Safe Schools')
        ->assertSeeText('Q1 narrative')
        ->assertSeeText('Winter Shelter')
        ->assertSeeText('Final financial')
        ->assertSee(route('donor-reports.show', $this->open), false)
        ->assertSee(route('donor-reports.show', $this->sent), false);
});

test('the state filter hides reports in another state', function () {
    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::donor-reports.index')
        ->set('state', 'submitted')
        ->assertSeeText('Final financial')
        ->assertDontSeeText('Q1 narrative')
        ->set('state', 'due')
        ->assertSeeText('Q1 narrative')
        ->assertDontSeeText('Final financial')
        ->set('state', 'bogus')
        ->assertSet('state', '')
        ->assertSeeText('Q1 narrative')
        ->assertSeeText('Final financial');
});

test('the donor and proposal filters and the proposal search narrow the list', function () {
    $page = Livewire::actingAs(User::factory()->role('Financial')->create())->test('pages::donor-reports.index');

    $page->set('donor', (string) $this->open->donorProposal->donor_id)
        ->assertSeeText('Q1 narrative')
        ->assertDontSeeText('Final financial')
        ->set('donor', '')
        ->set('proposal', (string) $this->sent->donorProposal->proposal_id)
        ->assertSeeText('Final financial')
        ->assertDontSeeText('Q1 narrative')
        ->set('proposal', '')
        ->set('search', 'winter')
        ->assertSeeText('Final financial')
        ->assertDontSeeText('Q1 narrative');
});

test('a responsible person without any money permission sees only their own reports', function () {
    $employeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Owner');
    $responsible = userWithRole('Team Member', $employeeId);
    Activity::withoutRecording(fn () => $this->open->update(['responsible_employee_id' => $employeeId]));

    expect($responsible->can('funding-agreements.view'))->toBeFalse()
        ->and($responsible->can('reports.aggregate'))->toBeFalse();

    $this->actingAs($responsible)->get(route('donor-reports.index'))
        ->assertOk()
        ->assertSee('data-donor-reports', false)
        ->assertSee('Q1 narrative')
        ->assertSee('Lina Owner')
        ->assertDontSee('Final financial')
        ->assertDontSee(route('proposals.show', $this->open->donorProposal->proposal), false);
});

test('a user who may open no donor report is refused', function () {
    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('donor-reports.index'))
        ->assertForbidden();
});

test('GET /donor-reports is the register, not the show page binding', function () {
    expect(route('donor-reports.index', absolute: false))->toBe('/donor-reports');

    $this->actingAs(User::factory()->role('Financial')->create())
        ->get('/donor-reports')
        ->assertOk()
        ->assertSee('data-donor-reports', false)
        ->assertSee('Safe Schools');
});
