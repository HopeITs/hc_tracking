<?php

use App\Enums\ProposalStatus;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Y-01 slice 3: the "Missing a center" flag on the proposals register lists
| the proposals still needing a center, counts them on its pill, and offers
| "Add centers" once a donor approved the proposal.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->employee = insertHrEmployee($this->aleppo);
    $this->program = Program::factory()->create(['manager_id' => $this->employee]);
});

/**
 * A proposal with one row for the given city, optionally with a center or
 * as field work.
 */
function missingCenterProposal(string $name, ProposalStatus $status, bool $withCenter = false, bool $cityWide = false, ?int $cityId = null): Proposal
{
    $proposal = Proposal::factory()->create(['name' => $name, 'status' => $status]);
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $cityId ?? test()->aleppo,
        'program_id' => test()->program->id,
        'responsible_employee_id' => test()->employee,
        'city_wide' => $cityWide,
    ]);

    if ($withCenter) {
        $row->centers()->create(['center_id' => test()->aleppoCenter]);
    }

    return $proposal;
}

function missingCenterRegister(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? test()->user)->test('pages::proposals.index')->set('flag', 'no_center');
}

/**
 * @return list<int>
 */
function missingCenterListedIds(Testable $page): array
{
    return $page->instance()->rows->getCollection()->pluck('id')->unique()->sort()->values()->all();
}

test('the list holds exactly the non-rejected project proposals with a row lacking a center and not city-wide', function () {
    $missing = missingCenterProposal('Missing Place', ProposalStatus::Approved);
    $draft = missingCenterProposal('Draft Missing', ProposalStatus::ProposalDraft, cityId: $this->homs);
    missingCenterProposal('Field Work Place', ProposalStatus::Approved, cityWide: true);
    missingCenterProposal('Complete Place', ProposalStatus::Approved, withCenter: true);
    missingCenterProposal('Rejected Missing', ProposalStatus::Rejected);
    Proposal::factory()->create(['name' => 'No Rows At All', 'status' => ProposalStatus::Approved]);
    $payroll = Proposal::factory()->payroll()->create(['name' => 'Payroll Line']);
    ProposalLocation::factory()->create(['proposal_id' => $payroll->id, 'city_id' => $this->aleppo, 'program_id' => $this->program->id, 'responsible_employee_id' => $this->employee]);

    $page = missingCenterRegister();

    expect(missingCenterListedIds($page))->toBe(collect([$missing->id, $draft->id])->sort()->values()->all());

    $page->assertSeeText('Missing Place')
        ->assertSeeText('Draft Missing')
        ->assertDontSeeText('Field Work Place')
        ->assertDontSeeText('Complete Place')
        ->assertDontSeeText('Rejected Missing')
        ->assertDontSeeText('No Rows At All')
        ->assertDontSeeText('Payroll Line');
});

test('the pill count equals the listed proposals', function () {
    missingCenterProposal('One', ProposalStatus::Approved);
    missingCenterProposal('Two', ProposalStatus::Submitted);
    missingCenterProposal('Done', ProposalStatus::Approved, withCenter: true);
    missingCenterProposal('Rejected One', ProposalStatus::Rejected);

    $page = Livewire::actingAs($this->user)->test('pages::proposals.index');

    expect($page->instance()->missingCenterCount)->toBe(2);

    $page->assertSeeHtml('data-test="flag-no-center"')
        ->assertSeeText(__('finance_tables.flag_no_center'));

    $page->set('flag', 'no_center');
    expect(count(missingCenterListedIds($page)))->toBe($page->instance()->missingCenterCount);
});

test('someone who may not see agreements gets no money columns but still sees the pill and the list', function () {
    missingCenterProposal('Missing Place', ProposalStatus::Approved);
    $viewer = userWithRole(fundraiserWithoutAgreements());

    $page = Livewire::actingAs($viewer)->test('pages::proposals.index');

    $page->assertSeeHtml('data-test="flag-no-center"');

    $page->set('flag', 'no_center')
        ->assertSeeText('Missing Place')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-register-totals');
});

test('Add centers links to the proposal with the editor flag for an approved proposal only', function () {
    $approved = missingCenterProposal('Approved Missing', ProposalStatus::Approved);
    $draft = missingCenterProposal('Draft Missing', ProposalStatus::ProposalDraft);

    missingCenterRegister()
        ->assertSeeHtml('data-test="add-centers-link-'.$approved->id.'"')
        ->assertSeeHtml(route('proposals.show', ['proposal' => $approved, 'add-centers' => 1]))
        ->assertDontSeeHtml('data-test="add-centers-link-'.$draft->id.'"')
        ->assertSeeHtml('data-test="missing-center-rows-'.$draft->id.'"');
});

test('each listed proposal names the cities of its rows missing a center', function () {
    $proposal = missingCenterProposal('Approved Missing', ProposalStatus::Approved, cityId: $this->homs);

    missingCenterRegister()->assertSeeHtml('data-test="missing-center-rows-'.$proposal->id.'"')->assertSeeText('Homs');
});

test('opening the proposal with the add-centers flag opens the editor for an approved proposal', function () {
    $proposal = missingCenterProposal('Approved Missing', ProposalStatus::Approved);
    $draft = missingCenterProposal('Draft Missing', ProposalStatus::ProposalDraft);

    Livewire::actingAs($this->user)->withQueryParams(['add-centers' => 1])
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSet('showCenterFillModal', true);

    Livewire::actingAs($this->user)->withQueryParams(['add-centers' => 1])
        ->test('pages::proposals.show', ['proposal' => $draft])
        ->assertSet('showCenterFillModal', false);
});
