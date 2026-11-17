<?php

use App\Enums\ProposalStatus;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
| Y-01 slice 2 (D-83): the "Add centers" dialog on the proposal show page. Only
| rows with no center and not field work take input; the rest are locked.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->role('Financial')->create();

    $this->aleppo = insertHrCity('Aleppo', 'حلب');
    $this->homs = insertHrCity('Homs', 'حمص');
    $this->aleppoCenter = insertHrCenter($this->aleppo, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->hama = insertHrCity('Hama', 'حماة');
    $this->homsCenter = insertHrCenter($this->homs, 'Hope Center Homs', 'مركز الأمل حمص');
    $this->hamaCenter = insertHrCenter($this->hama, 'Hope Center Hama', 'مركز الأمل حماة');
    $this->employee = insertHrEmployee($this->aleppo);
    $this->program = Program::factory()->create(['manager_id' => $this->employee]);
});

/**
 * An approved proposal with one row per case: a filled row, a field-work row
 * and an empty one. Returns the proposal and its three rows.
 *
 * @return array{0: Proposal, 1: ProposalLocation, 2: ProposalLocation, 3: ProposalLocation}
 */
function proposalWithRowsToFill(ProposalStatus $status = ProposalStatus::Approved): array
{
    $proposal = Proposal::factory()->create(['status' => $status]);
    $base = ['proposal_id' => $proposal->id, 'program_id' => test()->program->id, 'responsible_employee_id' => test()->employee];

    $filled = ProposalLocation::factory()->create($base + ['city_id' => test()->aleppo, 'sort' => 0]);
    $filled->centers()->create(['center_id' => test()->aleppoCenter]);
    $fieldWork = ProposalLocation::factory()->cityWide()->create($base + ['city_id' => test()->homs, 'sort' => 1]);
    $empty = ProposalLocation::factory()->create($base + ['city_id' => test()->hama, 'sort' => 2]);

    return [$proposal, $filled, $fieldWork, $empty];
}

function centerFillPage(Proposal $proposal, ?User $user = null)
{
    return Livewire::actingAs($user ?? test()->user)->test('pages::proposals.show', ['proposal' => $proposal])->set('tab', 'projects');
}

test('the Add centers button shows for an approved proposal, a permitted user and a row missing a center', function () {
    [$proposal] = proposalWithRowsToFill();

    centerFillPage($proposal)->assertSeeHtml('data-test="add-centers"');
});

test('the Add centers button is hidden when no row is missing a center', function () {
    [$proposal, , , $empty] = proposalWithRowsToFill();
    $empty->update(['city_wide' => true]);

    centerFillPage($proposal)->assertDontSeeHtml('data-test="add-centers"');
});

test('the Add centers button is hidden before approval', function () {
    [$proposal] = proposalWithRowsToFill(ProposalStatus::Submitted);

    centerFillPage($proposal)->assertDontSeeHtml('data-test="add-centers"');
});

test('the Add centers button is hidden for a user who cannot update the proposal', function () {
    [$proposal] = proposalWithRowsToFill();

    centerFillPage($proposal, User::factory()->role('Team Member')->create())->assertDontSeeHtml('data-test="add-centers"');
});

test('opening the dialog lists every row but gives a picker to the empty one only', function () {
    [$proposal, $filled, $fieldWork, $empty] = proposalWithRowsToFill();

    $page = centerFillPage($proposal)->call('openCenterFill')
        ->assertSet('showCenterFillModal', true)
        ->assertSet('centerFill', [$empty->id => ['center_ids' => [], 'city_wide' => false]])
        ->assertSeeHtml('data-center-fill-row="'.$filled->id.'"')
        ->assertSeeHtml('data-center-fill-row="'.$fieldWork->id.'"')
        ->assertSeeHtml('data-center-fill-row="'.$empty->id.'"');

    $html = $page->html();

    expect(substr_count($html, 'data-center-fill-locked'))->toBe(2)
        ->and(substr_count($html, 'data-fillable ') + substr_count($html, 'data-fillable>'))->toBe(1)
        ->and($html)->toContain('Hope Center Aleppo')
        ->and(substr_count($html, 'name="centerFill'))->toBe(0);
    $page->assertSeeHtml('centerFill.'.$empty->id.'.center_ids')
        ->assertDontSeeHtml('centerFill.'.$filled->id)
        ->assertDontSeeHtml('centerFill.'.$fieldWork->id);
});

test('ticking field work clears the picked centers', function () {
    [$proposal, , , $empty] = proposalWithRowsToFill();

    centerFillPage($proposal)->call('openCenterFill')
        ->set('centerFill.'.$empty->id.'.center_ids', [(string) $this->hamaCenter])
        ->set('centerFill.'.$empty->id.'.city_wide', true)
        ->assertSet('centerFill.'.$empty->id.'.center_ids', []);
});

test('saving writes the center on the empty row only, and a foreign row id is ignored', function () {
    [$proposal, $filled, $fieldWork, $empty] = proposalWithRowsToFill();
    $foreign = ProposalLocation::factory()->create(['city_id' => $this->homs]);

    centerFillPage($proposal)->call('openCenterFill')
        ->set('centerFill.'.$empty->id.'.center_ids', [(string) $this->hamaCenter])
        ->set('centerFill.'.$foreign->id, ['center_ids' => [(string) $this->homsCenter], 'city_wide' => false])
        ->call('saveCenterFill')
        ->assertHasNoErrors()
        ->assertSet('showCenterFillModal', false);

    expect($empty->fresh()->centerIds())->toBe([(int) $this->hamaCenter])
        ->and($filled->fresh()->centerIds())->toBe([(int) $this->aleppoCenter])
        ->and($fieldWork->fresh()->city_wide)->toBeTrue()
        ->and($foreign->fresh()->centers)->toHaveCount(0);
});

test('a user without proposals.update is refused on both actions', function () {
    [$proposal] = proposalWithRowsToFill();
    $member = User::factory()->role('Team Member')->create();

    Livewire::actingAs($member)->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openCenterFill')
        ->assertForbidden();

    Livewire::actingAs($member)->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('saveCenterFill')
        ->assertForbidden();
});
