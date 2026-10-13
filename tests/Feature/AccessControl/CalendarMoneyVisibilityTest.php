<?php

use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Livewire\Livewire;

use function Livewire\invade;

/**
 * One project owned by $employeeId, funded by one proposal that carries every
 * money-adjacent calendar item on 2026-06-15: a proposal deadline, a transfer
 * of 123,457.89, a donor follow-up and a donor report deadline.
 *
 * @return array{proposal: Proposal, transfer: TransfersTracking, donor: Donor, report: DonorReport}
 */
function seedCalendarMoneyFixture(int $employeeId): array
{
    $project = Project::factory()->create(['user_id' => $employeeId]);
    $proposal = Proposal::factory()->create(['name' => 'Harbour Clean Water', 'planned_date_end' => '2026-06-15']);
    $proposal->projects()->attach($project);

    $donor = Donor::factory()->create(['name' => 'Aurora Foundation', 'next_step_due_on' => '2026-06-15']);
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $donor->id]);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $donor->id]);

    $transfer = TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '123457.89',
        'scheduled_date' => '2026-06-15',
    ]);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim donor report', 'due_on' => '2026-06-15']);

    return compact('proposal', 'transfer', 'donor', 'report');
}

/** The data-test marker a calendar chip carries. */
function calendarChip(string $kind, int $id): string
{
    return 'data-test="calendar-'.$kind.'-'.$id.'"';
}

test('managers see none of the money items on the calendar, whatever the toggles say', function () {
    $employeeId = insertHrEmployee();
    $fixture = seedCalendarMoneyFixture($employeeId);

    Livewire::actingAs(userWithRole('Managers', $employeeId))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->set('showProposals', true)
        ->set('showTransfers', true)
        ->set('showDonors', true)
        ->assertDontSee(calendarChip('proposal', $fixture['proposal']->id), false)
        ->assertDontSee(calendarChip('transfer', $fixture['transfer']->id), false)
        ->assertDontSee(calendarChip('donor-followup', $fixture['donor']->id), false)
        ->assertDontSee(calendarChip('donor-report', $fixture['report']->id), false)
        ->assertDontSee('123,457.89')
        ->assertDontSee('Aurora Foundation')
        ->assertDontSee('Interim donor report');
});

test('managers get no filter entries or legend for the money types', function () {
    $employeeId = insertHrEmployee();

    Livewire::actingAs(userWithRole('Managers', $employeeId))->test('pages::calendar.index')
        ->set('showTypeFilterMenu', true)
        ->assertSee('data-test="calendar-filter-projects"', false)
        ->assertDontSee('data-test="calendar-filter-proposals"', false)
        ->assertDontSee('data-test="calendar-filter-transfers"', false)
        ->assertDontSee('data-test="calendar-filter-donors"', false)
        ->assertDontSee(__('calendar.proposal_deadline'))
        ->assertDontSee(__('calendar.transfer'))
        ->assertDontSee(__('calendar.donor_follow_up'));
});

test('a hidden type cannot be switched on, focused or reached from the browser', function () {
    $employeeId = insertHrEmployee();
    $fixture = seedCalendarMoneyFixture($employeeId);

    $component = Livewire::actingAs(userWithRole('Managers', $employeeId))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->set('showProposals', false)
        ->call('toggleType', 'proposal')
        ->assertSet('showProposals', false)
        ->call('focusOnlyType', 'transfer')
        ->assertSet('showTransfers', true)
        ->assertSet('showProjects', true)
        ->assertDontSee('data-test="calendar-focused-banner"', false);

    // Hiding every visible type must not leave a hidden one "focused".
    $component->set('showMilestones', false)
        ->set('showTasks', false)
        ->set('showReports', false)
        ->set('showProposals', true)
        ->set('showTransfers', true)
        ->set('showDonors', true)
        ->call('toggleType', 'project')
        ->assertDontSee(calendarChip('transfer', $fixture['transfer']->id), false)
        ->assertDontSee(calendarChip('donor-followup', $fixture['donor']->id), false)
        ->assertDontSee('123,457.89');

    expect(invade($component->instance())->focusedItems)->toBeEmpty();
});

test('the day detail and the related-dates list carry no money for managers', function () {
    $employeeId = insertHrEmployee();
    seedCalendarMoneyFixture($employeeId);

    $component = Livewire::actingAs(userWithRole('Managers', $employeeId))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->call('openDayDetail', '2026-06-15');

    expect($component->instance()->dayDetailItems)->toBeEmpty();
    $component->assertDontSee('Interim donor report')->assertDontSee('123,457.89');
});

test('financial sees every money item, the transfer with its amount, linked to its agreement', function () {
    $employeeId = insertHrEmployee();
    $fixture = seedCalendarMoneyFixture($employeeId);

    Livewire::actingAs(userWithRole('Financial', $employeeId))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee(calendarChip('proposal', $fixture['proposal']->id), false)
        ->assertSee(calendarChip('transfer', $fixture['transfer']->id), false)
        ->assertSee(calendarChip('donor-followup', $fixture['donor']->id), false)
        ->assertSee(calendarChip('donor-report', $fixture['report']->id), false)
        ->assertSee('123,457.89')
        ->assertSeeHtml(route('funding-agreements.show', $fixture['transfer']->donorProposal))
        ->set('showTypeFilterMenu', true)
        ->assertSee('data-test="calendar-filter-proposals"', false)
        ->assertSee('data-test="calendar-filter-transfers"', false)
        ->assertSee('data-test="calendar-filter-donors"', false)
        ->call('focusOnlyType', 'transfer')
        ->assertSee('data-test="calendar-focused-banner"', false)
        ->assertSee('123,457.89');
});

test('public relations manager sees proposals and donor follow-ups but no transfers or donor reports', function () {
    $employeeId = insertHrEmployee();
    $fixture = seedCalendarMoneyFixture($employeeId);

    $component = Livewire::actingAs(userWithRole('Public Relations Manager', $employeeId))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee(calendarChip('proposal', $fixture['proposal']->id), false)
        ->assertSee(calendarChip('donor-followup', $fixture['donor']->id), false)
        ->assertDontSee(calendarChip('transfer', $fixture['transfer']->id), false)
        ->assertDontSee(calendarChip('donor-report', $fixture['report']->id), false)
        ->assertDontSee('123,457.89')
        ->assertDontSee('Interim donor report')
        ->call('focusOnlyType', 'donor')
        ->assertSee('data-test="calendar-focused-banner"', false)
        ->assertSee(__('calendar.donor_follow_up'))
        ->assertDontSee(__('calendar.donor_report_due'));

    expect(invade($component->instance())->focusedItems)->toHaveCount(1);
});

test('a transfer is plain text, not a link, for someone who may not open funding agreements', function () {
    $employeeId = insertHrEmployee();
    $fixture = seedCalendarMoneyFixture($employeeId);
    $viewer = userWithRole('Managers', $employeeId);
    $viewer->givePermissionTo('transfers.view');

    Livewire::actingAs($viewer)->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->assertSee(calendarChip('transfer', $fixture['transfer']->id), false)
        ->assertDontSeeHtml(route('funding-agreements.show', $fixture['transfer']->donorProposal))
        ->assertDontSee(calendarChip('donor-report', $fixture['report']->id), false)
        ->call('openDayDetail', '2026-06-15')
        ->assertSee('data-test="calendar-daydetail-item-'.$fixture['transfer']->id.'"', false)
        ->assertDontSeeHtml(route('funding-agreements.show', $fixture['transfer']->donorProposal));
});
