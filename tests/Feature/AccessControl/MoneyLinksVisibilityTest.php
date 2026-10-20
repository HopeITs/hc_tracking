<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * One donor funding one proposal for 731,942, with a target set for that
 * proposal, a task raised on it and a report filed against the target: the
 * non-money pages that mention the proposal, and the figure they must not leak.
 *
 * @return array{donor: Donor, proposal: Proposal, target: PlanIndicator, task: Task, report: Report}
 */
function seedProposalSourcedWork(): array
{
    $donor = Donor::factory()->create(['name' => 'Aurora Foundation']);
    $proposal = Proposal::factory()->create(['name' => 'Harbour Clean Water']);
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
        'budget' => '731942.00',
        'currency' => Currency::Usd,
    ]);

    $target = PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Households connected'])->id,
    ]);
    $task = Task::factory()->create([
        'title' => 'Draft the water survey',
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
    ]);
    $report = Report::factory()->create(['plan_indicator_id' => $target->id]);

    return compact('donor', 'proposal', 'target', 'task', 'report');
}

/** The href attribute a link to $url carries. */
function hrefTo(string $url): string
{
    return 'href="'.$url.'"';
}

test('MEAL sees a proposal-sourced target with its proposal and donor names but no budget and no links', function () {
    ['donor' => $donor, 'proposal' => $proposal] = seedProposalSourcedWork();

    $page = Livewire::actingAs(userWithRole('MEAL'))->test('pages::targets.index');

    $page->assertOk()
        ->assertSee('Households connected')
        ->assertSee('Harbour Clean Water')
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSeeHtml(hrefTo(route('proposals.show', $proposal)))
        ->assertDontSeeHtml(hrefTo(route('donors.show', $donor)));

    expect($page->instance()->targets->first()->source->relationLoaded('donors'))->toBeFalse();
});

test('Financial sees the same target with the proposal link, the donor link and the budget', function () {
    ['donor' => $donor, 'proposal' => $proposal] = seedProposalSourcedWork();

    Livewire::actingAs(userWithRole('Financial'))->test('pages::targets.index')
        ->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942')
        ->assertSeeHtml(hrefTo(route('proposals.show', $proposal)))
        ->assertSeeHtml(hrefTo(route('donors.show', $donor)));
});

test('a fundraiser without agreements gets the proposal and donor links on targets but not the budget', function () {
    ['donor' => $donor, 'proposal' => $proposal] = seedProposalSourcedWork();

    Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::targets.index')
        ->assertOk()
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertSeeHtml(hrefTo(route('proposals.show', $proposal)))
        ->assertSeeHtml(hrefTo(route('donors.show', $donor)));
});

test('the indicator and results pages show the funding donor by name and never a budget', function (string $page) {
    seedProposalSourcedWork();

    Livewire::actingAs(userWithRole('Managers'))->test($page)
        ->assertOk()
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942');
})->with([
    'indicators' => 'pages::indicators.index',
    'results' => 'pages::results.index',
]);

test('a task raised on a proposal names it for a Manager without linking to the proposal', function () {
    ['proposal' => $proposal, 'task' => $task] = seedProposalSourcedWork();

    Livewire::actingAs(userWithRole('Managers'))->test('pages::tasks.show', ['task' => $task])
        ->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertDontSeeHtml(hrefTo(route('proposals.show', $proposal)));

    Livewire::actingAs(userWithRole('Managers'))->test('pages::tasks.index')
        ->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertDontSeeHtml(hrefTo(route('proposals.show', $proposal)));
});

test('a report filed against a proposal target names the proposal, and links to it only for those who may open proposals', function () {
    ['proposal' => $proposal, 'report' => $report] = seedProposalSourcedWork();
    $link = hrefTo(route('proposals.show', $proposal));

    Livewire::actingAs(userWithRole('Managers'))->test('pages::reports.show', ['report' => $report])
        ->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertDontSeeHtml($link);

    Livewire::actingAs(userWithRole('Financial'))->test('pages::reports.show', ['report' => $report])
        ->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertSeeHtml($link);
});

/**
 * A proposal in the pipeline with a 731,942 agreement, an overdue pending
 * tranche of 123,457 and a received one of 55,555: every figure and link the
 * dashboard could leak.
 *
 * @return array{proposal: Proposal, agreement: DonorProposal}
 */
function seedDashboardMoney(): array
{
    $proposal = Proposal::factory()->create(['name' => 'Harbour Clean Water', 'status' => ProposalStatus::Submitted]);
    $fundingDonorId = Donor::factory()->create(['name' => 'Aurora Foundation'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $fundingDonorId,
        'budget' => '731942.00',
        'currency' => Currency::Usd,
    ]);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => ProposalStatus::Submitted, 'date' => '2026-09-14']);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '123457.00',
        'scheduled_date' => '2026-09-05',
        'ar_status' => ArStatus::Pending,
    ]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '55555.00',
        'amount_received' => '55555.00',
        'net_amount_received' => '55555.00',
        'scheduled_date' => '2026-08-01',
    ]);

    return compact('proposal', 'agreement');
}

/**
 * The Desk with every panel the role may add placed on it, rendered as the given role.
 */
function fullDeskAs(string|User $role): TestResponse
{
    $user = $role instanceof User ? $role : userWithRole($role);
    $user->forceFill(['desk_layout' => array_map(fn (string $panel): array => ['panel' => $panel, 'size' => 'L'], ['glance', 'needs', 'waiting', 'coming', 'projects', 'pipeline', 'money', 'transfers', 'matrix', 'validate', 'templates', 'reviews', 'tasks', 'deliverables', 'myteam', 'risks', 'targets', 'duties', 'activity', 'inbox', 'milestones', 'plans', 'donors'])])->save();

    return test()->actingAs($user)->get(route('dashboard'))->assertOk();
}

test('Team Member and Managers see no funding, proposal or transfer panel, figure or link on the desk', function (string $role) {
    $this->travelTo('2026-09-15 09:00:00');
    ['proposal' => $proposal, 'agreement' => $agreement] = seedDashboardMoney();

    fullDeskAs($role)
        ->assertDontSee('data-panel="money"', false)
        ->assertDontSee('data-panel="transfers"', false)
        ->assertDontSee('data-panel="pipeline"', false)
        ->assertDontSee('data-panel="donors"', false)
        ->assertDontSee('Harbour Clean Water')
        ->assertDontSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSee('55,555')
        ->assertDontSee(route('proposals.index'), false)
        ->assertDontSee(route('proposals.show', $proposal), false)
        ->assertDontSee(route('funding-agreements.index'), false)
        ->assertDontSee(route('funding-agreements.show', $agreement), false)
        ->assertDontSee(route('transfers.index'), false);
})->with(['Team Member', 'Managers']);

test('Financial sees the funding position, the pipeline, the transfers and their links on the desk', function () {
    $this->travelTo('2026-09-15 09:00:00');
    ['proposal' => $proposal, 'agreement' => $agreement] = seedDashboardMoney();

    fullDeskAs('Financial')
        ->assertSee('data-panel="money"', false)
        ->assertSee('data-panel="pipeline"', false)
        ->assertSee('$731,942')
        ->assertSee('123,457 USD from Aurora Foundation')
        ->assertSee(route('proposals.index'), false)
        ->assertSee(route('proposals.show', $proposal), false)
        ->assertSee(route('funding-agreements.index'), false)
        ->assertSee(route('funding-agreements.show', $agreement), false);
});

test('a fundraiser without agreements sees the proposal pipeline on the desk but nothing from agreements or transfers', function () {
    $this->travelTo('2026-09-15 09:00:00');
    ['proposal' => $proposal, 'agreement' => $agreement] = seedDashboardMoney();

    fullDeskAs(fundraiserWithoutAgreements())
        ->assertSee('data-panel="pipeline"', false)
        ->assertSee(route('proposals.show', $proposal), false)
        ->assertDontSee('data-panel="money"', false)
        ->assertDontSee('data-panel="transfers"', false)
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSee(route('funding-agreements.index'), false)
        ->assertDontSee(route('funding-agreements.show', $agreement), false)
        ->assertDontSee(route('transfers.index'), false);
});
