<?php

use App\Enums\ArStatus;
use App\Enums\ChangeReviewVerdict;
use App\Enums\Currency;
use App\Enums\ProposalChangeRequestStatus;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Notifications\Alerts\NotificationSettings;
use App\Support\EmployeeProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15 09:00:00');
    $this->user = User::factory()->role('Financial')->create();
});

/**
 * The proposals page as the finance manager opens it.
 *
 * @param  array<string, string>  $query
 */
function proposalRegister(array $query = []): Testable
{
    return Livewire::actingAs(test()->user)->withQueryParams($query)->test('pages::proposals.index');
}

/**
 * A tranche in its agreement's own currency.
 *
 * @param  array<string, mixed>  $attributes
 */
function proposalTranche(DonorProposal $agreement, array $attributes): TransfersTracking
{
    return TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'amount_transfered_currency' => $agreement->currency,
        'amount_received_currency' => $agreement->currency,
        'transfer_date' => null,
        'receive_date' => null,
        'ar_status' => ArStatus::Pending,
        ...$attributes,
    ]);
}

/**
 * Water Response, active through 2026: ECHO commits 1,000 USD (400 received,
 * 390 net; 200 overdue; 100 due on 25 June) and GIZ commits 500 EUR with
 * nothing scheduled. It funds one project.
 */
function registerFundedProposal(): Proposal
{
    $proposal = Proposal::factory()->create([
        'name' => 'Water Response',
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);
    $proposal->projects()->attach(Project::factory()->create());

    $fundingDonorId = Donor::factory()->create(['name' => 'ECHO'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    $dollars = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $fundingDonorId,
        'budget' => '1000.00',
        'currency' => Currency::Usd,
    ]);
    // A proposal has one donor and one agreement, so the second currency is a
    // sibling proposal (a co-funder is a separate proposal, D-24).
    $coFunded = Proposal::factory()->create([
        'name' => 'Water Response',
        'donor_id' => Donor::factory()->create(['name' => 'GIZ'])->id,
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => $coFunded->id,
        'budget' => '500.00',
        'currency' => Currency::Eur,
    ]);

    proposalTranche($dollars, ['payment_amount' => '400.00', 'net_amount_received' => '390.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-05-15', 'transfer_date' => '2026-05-15', 'receive_date' => '2026-05-20']);
    proposalTranche($dollars, ['payment_amount' => '200.00', 'scheduled_date' => '2026-06-05']);
    proposalTranche($dollars, ['payment_amount' => '100.00', 'scheduled_date' => '2026-06-25']);

    return $proposal;
}

test('the page opens on the table with one row per proposal and currency', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response', 'status' => ProposalStatus::ConceptNote, 'planned_date_begin' => '2025-03-01', 'planned_date_end' => '2025-09-30']);

    $page = proposalRegister()
        ->assertSet('view', 'table')
        ->assertSeeHtml('data-register')
        ->assertDontSeeHtml('data-funding-position')
        ->assertSee('Water Response')
        ->assertSee('Shelter Response')
        ->assertSee('ECHO')
        ->assertSee('GIZ')
        // 2026-11-19: the list shows no money; the figures below are still read for sorting and the CSV export.
        ->assertDontSee('1,000.00')
        ->assertDontSee('39%');

    $rows = $page->instance()->rows->getCollection();

    expect($rows->map(fn (Proposal $row): string => $row->name.'|'.($row->money_currency ?? '-'))->all())
        ->toBe(['Water Response|USD', 'Water Response|EUR', 'Shelter Response|-'])
        ->and($page->instance()->proposalCount)->toBe(3);

    $dollars = $rows->firstWhere('money_currency', 'USD');

    expect((int) $dollars->money_agreements)->toBe(1)
        ->and((float) $dollars->money_committed)->toBe(1000.0)
        ->and((float) $dollars->money_received)->toBe(390.0)
        ->and((float) $dollars->money_outstanding)->toBe(600.0)
        ->and((float) $dollars->money_overdue)->toBe(200.0)
        ->and($dollars->money_next_due)->toBe('2026-06-05')
        ->and((int) $dollars->projects_count)->toBe(1)
        ->and($rows->last()->id)->toBe($idea->id);
});

test('the overview view shows the funding position and the dossiers instead of the table', function () {
    registerFundedProposal();

    proposalRegister()
        ->set('view', 'overview')
        ->assertSeeHtml('data-funding-position')
        ->assertDontSeeHtml('data-register');
});

test('a row offers the view, change status, edit and delete actions the dossier offered', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response']);

    proposalRegister()
        ->assertSeeHtml('aria-label="View Water Response"')
        ->assertSeeHtml('href="'.route('proposals.show', $funded).'"')
        ->assertSeeHtml('data-test="advance-proposal-'.$funded->id.'"')
        ->assertSeeHtml('wire:click="confirmDelete('.$idea->id.')"')
        ->assertDontSeeHtml('wire:click="confirmDelete('.$funded->id.')"');
});

test('currency, donor and the flags narrow the rows', function () {
    $funded = registerFundedProposal();
    $idea = Proposal::factory()->create(['name' => 'Shelter Response']);
    $reporting = Proposal::factory()->create(['name' => 'Clinic Rebuild']);
    DonorReport::factory()->create([
        'donor_proposal_id' => DonorProposal::factory()->create(['proposal_id' => $reporting->id, 'currency' => Currency::Usd])->id,
        'due_on' => '2026-06-01',
    ]);

    $proposalIds = fn (Testable $page): array => $page->instance()->rows->pluck('id')->unique()->sort()->values()->all();
    $echo = Donor::query()->where('name', 'ECHO')->sole();

    expect($proposalIds(proposalRegister()->call('toggleFlag', 'no_agreement')))->toBe([$idea->id])
        ->and($proposalIds(proposalRegister()->call('toggleFlag', 'overdue')))->toBe([$funded->id])
        ->and($proposalIds(proposalRegister()->call('toggleFlag', 'report_overdue')))->toBe([$reporting->id])
        ->and($proposalIds(proposalRegister()->set('donor', (string) $echo->id)))->toBe([$funded->id]);

    proposalRegister()->set('donor', (string) $echo->id)->assertSee('Funded by ECHO')->assertSeeHtml('data-donor-chip');

    // A currency keeps only that currency's line of each proposal.
    $euros = proposalRegister()->set('currency', 'EUR')->instance()->rows->getCollection();

    expect($euros)->toHaveCount(1)
        ->and($euros->first()->money_currency)->toBe('EUR');
});

test('choosing a year adds that year beside the lifetime figures and hides lines with nothing in it', function () {
    registerFundedProposal();
    $earlier = Proposal::factory()->create(['name' => 'Old Grant']);
    proposalTranche(
        DonorProposal::factory()->create(['proposal_id' => $earlier->id, 'budget' => '700.00', 'currency' => Currency::Usd]),
        ['payment_amount' => '700.00', 'net_amount_received' => '700.00', 'ar_status' => ArStatus::Received, 'scheduled_date' => '2024-02-01', 'receive_date' => '2024-02-05'],
    );

    $page = proposalRegister()->set('year', '2026');
    $row = $page->instance()->rows->getCollection()->sole();

    expect($row->name)->toBe('Water Response')
        ->and($row->money_currency)->toBe('USD')
        ->and((float) $row->money_due_in_year)->toBe(700.0)
        ->and((float) $row->money_received_in_year)->toBe(390.0);

    // 2026-11-19: the year figures are no longer columns in the list; the row still carries them for the CSV export.

    $only = proposalRegister()->set('year', '2024')->set('figures', 'year')->instance()->rows->getCollection()->sole();

    expect($only->name)->toBe('Old Grant')
        ->and((float) $only->money_received)->toBe(700.0);
});

test('totals are kept per currency and add up to the rows shown', function () {
    registerFundedProposal();
    DonorProposal::factory()->create(['budget' => '2000.00', 'currency' => Currency::Usd]);

    $page = proposalRegister();
    $totals = $page->instance()->totals->keyBy('currency');

    expect($totals->keys()->all())->toBe(['EUR', 'USD'])
        ->and($totals['USD'])->toMatchArray(['proposals' => 2, 'agreements' => 2, 'committed' => 3000.0, 'received' => 390.0, 'outstanding' => 2600.0, 'overdue' => 200.0])
        ->and($totals['EUR'])->toMatchArray(['proposals' => 1, 'committed' => 500.0]);

    // 2026-11-19: the per-currency totals block was removed from the list (user decision); the totals are still computed.
    $page->assertDontSeeHtml('data-register-total=')->assertDontSeeHtml('data-totals-currency=');
});

test('the table opens newest planned start first and a heading sorts it', function () {
    registerFundedProposal();
    $older = Proposal::factory()->create(['name' => 'Alpha Appeal', 'planned_date_begin' => '2025-01-01', 'planned_date_end' => '2025-12-31']);
    DonorProposal::factory()->create(['proposal_id' => $older->id, 'budget' => '9000.00', 'currency' => Currency::Usd]);

    $lines = fn (Testable $page): array => $page->instance()->rows->getCollection()->map(fn (Proposal $row): string => $row->name.'|'.$row->money_currency)->all();

    $page = proposalRegister();
    expect($lines($page))->toBe(['Water Response|USD', 'Water Response|EUR', 'Alpha Appeal|USD']);

    $page->call('sortRegister', 'proposal');
    expect($lines($page))->toBe(['Alpha Appeal|USD', 'Water Response|USD', 'Water Response|EUR']);

    $page->call('sortRegister', 'committed');
    expect($lines($page))->toBe(['Water Response|EUR', 'Alpha Appeal|USD', 'Water Response|USD']);
});

test('someone who may not see agreements gets the proposals with no donor, no money and no money query', function () {
    registerFundedProposal();

    $page = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::proposals.index');

    $page->assertOk()
        ->assertSee('Water Response')
        ->assertDontSee('ECHO')
        ->assertDontSee('1,000.00')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-register-totals');

    expect($page->instance()->rows->getCollection())->toHaveCount(2)
        ->and($page->instance()->totals)->toBeEmpty()
        ->and($page->instance()->filterDonors)->toBeEmpty()
        ->and(array_key_exists('money_committed', $page->instance()->rows->first()->getAttributes()))->toBeFalse()
        ->and($page->instance()->rows->first()->relationLoaded('donorProposals'))->toBeFalse();
});

test('someone who may see agreements but not transfers gets budgets without tranche figures', function () {
    registerFundedProposal();
    $viewer = userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $page = Livewire::actingAs($viewer)->test('pages::proposals.index');

    // 2026-11-19: the list shows no money at all now; the donor still shows to an agreement viewer.
    $page->assertSee('ECHO')
        ->assertDontSee('390.00')
        ->assertDontSeeHtml('data-cell="received"');

    expect((float) $page->instance()->rows->getCollection()->firstWhere('money_currency', 'USD')->money_received)->toBe(0.0);
});

test('values typed into the address bar fall back to the defaults', function () {
    registerFundedProposal();

    proposalRegister(['view' => 'gallery', 'status' => 'lost', 'phase' => 'moon', 'donor' => 'me', 'currency' => 'GBP', 'flag' => 'rich', 'sortBy' => 'notes', 'perPage' => '2'])
        ->assertSet('view', 'table')
        ->assertSet('status', '')
        ->assertSet('phase', '')
        ->assertSet('donor', '')
        ->assertSet('currency', '')
        ->assertSet('flag', '')
        ->assertSet('sortBy', '')
        ->assertSet('perPage', '')
        ->assertOk();
});

test('the export holds every filtered line and only the columns the viewer may see', function () {
    registerFundedProposal();
    Proposal::factory()->count(30)->create(['planned_date_begin' => '2020-01-01', 'planned_date_end' => '2020-12-31']);

    $page = proposalRegister()->call('exportCsv')->assertFileDownloaded('proposals-2026-06-15.csv');
    $csv = downloadedCsv($page);
    $dollars = collect($csv)->first(fn (array $row): bool => $row[0] === 'Water Response' && $row[8] === 'USD');

    expect($csv)->toHaveCount(33)
        ->and($csv[0][0])->toBe('Proposal')
        ->and($csv[0][9])->toBe('Committed')
        ->and($dollars[1])->toBe('Active')
        ->and($dollars[3])->toBe('2026-01-01')
        ->and($dollars[4])->toBe('2026-12-31')
        ->and($dollars[5])->toBe('1')
        ->and($dollars[6])->toBe('ECHO')
        ->and($dollars[7])->toBe('1')
        ->and($dollars[9])->toBe('1000.00')
        ->and($dollars[10])->toBe('390.00')
        ->and($dollars[11])->toBe('39')
        ->and($dollars[12])->toBe('600.00')
        ->and($dollars[13])->toBe('200.00')
        ->and($dollars[14])->toBe('2026-06-05');

    $fundraiser = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))->test('pages::proposals.index')->call('exportCsv');
    $plain = downloadedCsv($fundraiser);

    expect($plain[0])->toHaveCount(6)
        ->and(collect($plain)->flatten()->contains('ECHO'))->toBeFalse()
        ->and(collect($plain)->flatten()->contains('1000.00'))->toBeFalse();
});

test('exporting needs the proposals view permission', function () {
    registerFundedProposal();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::proposals.index')
        ->call('exportCsv')
        ->assertForbidden();
});

test('the proposal form is rendered only while its modal is open', function () {
    proposalRegister()
        ->assertDontSeeHtml('wire:model="plannedDateBegin"')
        ->call('openCreateModal')
        ->assertSeeHtml('wire:model="plannedDateBegin"');
});

test('the status form is rendered only while its modal is open', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    proposalRegister()
        ->assertDontSeeHtml('wire:model="statusContext"')
        ->call('openStatusModal', $proposal->id)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->assertSeeHtml('wire:model="statusContext"');
});

/*
 * W-06 slice 2 (D-58): "On hold" is a flag facet and a chip, never a status
 * option and never a board column.
 */

test('the board has a column per live status and none for the retired ones', function () {
    $held = Proposal::factory()->create(['name' => 'Held proposal', 'status' => ProposalStatus::Submitted]);
    $held->forceFill(['on_hold_since' => today()])->save();

    $page = proposalRegister()->set('view', 'board');
    $keys = collect($page->instance()->boardColumns)->pluck('key')->all();

    expect($keys)->not->toContain('on hold', 'final reports')
        ->and($keys)->toContain('submitted')
        // The held proposal counts inside its own status, not apart from it.
        ->and(collect($page->instance()->boardColumns)->firstWhere('key', 'submitted')['items']->pluck('id')->all())
        ->toBe([$held->id]);

    // The card carries the chip, so a hold is visible without opening the proposal.
    $page->assertSeeHtml('data-test="on-hold-chip"');
});

test('the on hold facet keeps only held proposals and the row reads Submitted (On hold)', function () {
    $held = Proposal::factory()->create(['name' => 'Waiting on donor', 'status' => ProposalStatus::Submitted]);
    $held->forceFill(['on_hold_since' => today()])->save();
    $running = Proposal::factory()->create(['name' => 'Still moving', 'status' => ProposalStatus::Submitted]);

    proposalRegister()
        ->assertSeeText('Waiting on donor')
        ->assertSeeText('Still moving')
        ->assertSeeText($held->fresh()->statusLabel());

    // The facet travels in the address bar, like every other register filter.
    proposalRegister(['flag' => 'on_hold'])
        ->assertSet('flag', 'on_hold')
        ->assertSeeText('Waiting on donor')
        ->assertDontSeeText('Still moving');

    // The status picker offers no hold option: a hold is not a status.
    proposalRegister()->assertDontSeeHtml('"value":"on hold"');
});

/*
| W-32 slice 2: payroll proposals carry a chip, and a kind facet narrows the
| table, the board and the calendar alike.
*/

test('the kind filter narrows the rows and the board, and a payroll row carries its chip', function () {
    $project = Proposal::factory()->create(['name' => 'Shelter Response', 'planned_date_begin' => '2026-06-10']);
    $payroll = Proposal::factory()->payroll()->create(['name' => 'Micro Aleppo payroll', 'status' => ProposalStatus::ProposalDraft, 'planned_date_begin' => '2026-06-12']);

    $rowIds = fn (Testable $page): array => $page->instance()->rows->pluck('id')->unique()->sort()->values()->all();
    $boardIds = fn (Testable $page): array => collect($page->instance()->boardColumns)->flatMap(fn (array $column) => $column['items']->pluck('id'))->sort()->values()->all();

    expect($rowIds(proposalRegister()))->toBe([$project->id, $payroll->id])
        ->and($rowIds(proposalRegister()->set('proposalKind', 'payroll')))->toBe([$payroll->id])
        ->and($rowIds(proposalRegister(['kind' => 'project'])))->toBe([$project->id])
        ->and($boardIds(proposalRegister(['kind' => 'payroll', 'view' => 'board'])))->toBe([$payroll->id]);

    proposalRegister()
        ->assertSeeHtml('data-payroll-chip="'.$payroll->id.'"')
        ->assertDontSeeHtml('data-payroll-chip="'.$project->id.'"')
        ->assertSeeHtml('data-test="kind-filter-payroll"');

    proposalRegister(['view' => 'board'])->assertSeeHtml('data-payroll-chip="'.$payroll->id.'"');
});

test('an unknown kind in the address bar falls back to both kinds', function () {
    proposalRegister(['kind' => 'salary'])->assertSet('proposalKind', '');
});

test('clearing the filters clears the kind', function () {
    proposalRegister(['kind' => 'payroll'])->call('clearFilters')->assertSet('proposalKind', '');
});

/*
| Y-09 slice 2: the change requests waiting on an approved proposal. The PR
| Manager's "Changes to approve" lists every pending request; the Finance
| and MEAL Managers' "Changes to review" lists those waiting in their own
| slot. Each button's count equals its list.
*/

/**
 * Put one HR employee on each change-review slot's position.
 *
 * @return array{finance: int, meal: int}
 */
function registerHoldChangeSlots(): array
{
    $finance = insertHrEmployee(firstNameEn: 'Fadi', lastNameEn: 'Finance');
    $financePosition = insertHrPosition(insertHrDepartment('Finance'), 'Finance Manager');
    insertHrContract($finance, $financePosition);

    $meal = insertHrEmployee(firstNameEn: 'Maha', lastNameEn: 'Manager');
    $mealPosition = insertHrPosition(insertHrDepartment('Monitoring & Evaluation'), 'MEAL Manager');
    insertHrContract($meal, $mealPosition);

    app(NotificationSettings::class)->save(['proposal_default_reviewers' => [
        'meal_manager' => $mealPosition, 'finance_manager' => $financePosition, 'fundraising_manager' => null, 'ceo' => null,
    ]]);

    EmployeeProfile::forgetRememberedLookup();

    return ['finance' => $finance, 'meal' => $meal];
}

/**
 * The register as the given user opens it.
 *
 * @param  array<string, string>  $query
 */
function changeRegisterAs(User $user, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test('pages::proposals.index');
}

/**
 * Give one review of the request a verdict.
 */
function registerReviewIn(ProposalChangeRequest $request, string $slot): void
{
    $request->reviews()->where('slot', $slot)->update(['verdict' => ChangeReviewVerdict::NoObjection->value, 'reviewed_at' => now()]);
}

test('changes to approve counts every pending request and is absent without proposals.approve', function () {
    $manager = userWithRole('Public Relations Manager');
    $pending = ProposalChangeRequest::factory()->withReviews()->count(2)->create();
    $decided = ProposalChangeRequest::factory()->create(['status' => ProposalChangeRequestStatus::Approved]);

    $page = changeRegisterAs($manager, ['focus' => 'approve'])
        ->assertSeeHtml('data-test="change-focus-approve"')
        ->assertSeeHtml('data-change-count="2"')
        ->assertSeeHtml('data-change-queue-row="'.$pending[0]->id.'"')
        ->assertSeeHtml('data-change-queue-row="'.$pending[1]->id.'"')
        ->assertDontSeeHtml('data-change-queue-row="'.$decided->id.'"')
        ->assertDontSeeHtml('data-test="change-focus-review"');

    expect($page->instance()->changesToApprove)->toHaveCount(2);

    changeRegisterAs(userWithRole('Public Relations'), ['focus' => 'approve'])
        ->assertSet('focus', '')
        ->assertDontSeeHtml('data-test="change-focus-approve"')
        ->assertDontSeeHtml('data-change-queue-row="');
});

test('pressing a change button again shows the register again', function () {
    $manager = userWithRole('Public Relations Manager');
    ProposalChangeRequest::factory()->withReviews()->create();

    changeRegisterAs($manager)
        ->call('setFocus', 'approve')->assertSet('focus', 'approve')
        ->call('setFocus', 'approve')->assertSet('focus', '')
        ->call('setFocus', 'anything')->assertSet('focus', '');
});

test('changes to review holds only the requests waiting in the viewer own slot', function () {
    $holders = registerHoldChangeSlots();
    $financeManager = userWithRole('Financial', $holders['finance']);

    $waiting = ProposalChangeRequest::factory()->withReviews()->create();
    $reviewed = ProposalChangeRequest::factory()->withReviews()->create();
    registerReviewIn($reviewed, 'finance_manager');
    $onlyMealWaits = ProposalChangeRequest::factory()->withReviews()->create();
    registerReviewIn($onlyMealWaits, 'finance_manager');
    $decided = ProposalChangeRequest::factory()->withReviews()->create(['status' => ProposalChangeRequestStatus::Rejected]);

    $page = changeRegisterAs($financeManager, ['focus' => 'review'])
        ->assertSeeHtml('data-test="change-focus-review"')
        ->assertSeeHtml('data-change-count="1"')
        ->assertSeeHtml('data-change-queue-row="'.$waiting->id.'"')
        ->assertDontSeeHtml('data-change-queue-row="'.$reviewed->id.'"')
        ->assertDontSeeHtml('data-change-queue-row="'.$onlyMealWaits->id.'"')
        ->assertDontSeeHtml('data-change-queue-row="'.$decided->id.'"')
        // A Finance Manager does not decide: no approve list.
        ->assertDontSeeHtml('data-test="change-focus-approve"');

    expect($page->instance()->changesToReview->pluck('id')->all())->toBe([$waiting->id]);

    // The MEAL Manager still sees the requests whose finance review is in.
    $mealIds = changeRegisterAs(userWithRole('MEAL', $holders['meal']))->instance()->changesToReview->pluck('id')->sort()->values()->all();
    expect($mealIds)->toBe(collect([$waiting->id, $reviewed->id, $onlyMealWaits->id])->sort()->values()->all());

    // Someone who holds no slot gets neither button.
    proposalRegister()->assertDontSeeHtml('data-test="change-focus-review"')->assertDontSeeHtml('data-test="change-focus-approve"');
});

test('a queued request says which review it is waiting for and opens its proposal', function () {
    $manager = userWithRole('Public Relations Manager');
    $request = ProposalChangeRequest::factory()->withReviews()->create();
    registerReviewIn($request, 'meal_manager');
    $ready = ProposalChangeRequest::factory()->withReviews()->create();
    registerReviewIn($ready, 'meal_manager');
    registerReviewIn($ready, 'finance_manager');

    changeRegisterAs($manager, ['focus' => 'approve'])
        ->assertSeeInOrder([__('proposals.change.fields.name'), __('proposals.change.waiting_for_finance')])
        ->assertSeeHtml(e(route('proposals.show', ['proposal' => $request->proposal, 'change-request' => 1])))
        ->assertSee(__('proposals.change.queue_ready'))
        ->assertDontSee(__('proposals.change.waiting_for_meal'));
});

test('an empty change list says nothing is waiting', function () {
    changeRegisterAs(userWithRole('Public Relations Manager'), ['focus' => 'approve'])
        ->assertSee(__('proposals.change.queue_empty_approve'));
});

test('the change list reads a flat number of queries however many requests wait', function () {
    $manager = userWithRole('Public Relations Manager');

    $countQueries = function () use ($manager): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        changeRegisterAs($manager, ['focus' => 'approve']);

        return $queries;
    };

    ProposalChangeRequest::factory()->withReviews()->create();
    $one = $countQueries();

    ProposalChangeRequest::factory()->withReviews()->count(19)->create();
    $twenty = $countQueries();

    expect($twenty)->toBeLessThanOrEqual($one + 2);
});

// User decisions 2026-11-19: few columns, no sideways scroll, no money totals or committed column; the name opens the proposal.
test('the table keeps only the key columns, the name opens the proposal and the money stays on its page', function () {
    $proposal = registerFundedProposal();

    proposalRegister()
        ->assertSeeHtml('href="'.route('proposals.show', $proposal).'"')
        ->assertSeeHtml('data-test="proposal-name-link-'.$proposal->id.'"')
        ->assertSee('ECHO')
        ->assertDontSeeHtml('data-register-totals')
        ->assertDontSeeHtml('data-cell="committed"')
        ->assertDontSeeHtml('data-cell="percent"')
        ->assertDontSeeHtml('data-cell="outstanding"')
        ->assertDontSeeHtml('data-cell="received"')
        ->assertDontSeeHtml('data-label="'.__('proposals.phase').'"')
        ->assertDontSeeHtml('data-label="'.__('finance_tables.next_due').'"');
});
