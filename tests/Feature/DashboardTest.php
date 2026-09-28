<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users see the application navigation on the dashboard', function () {
    $user = User::factory()->role('General Manager')->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response
        ->assertSee('data-app-page-container', false)
        ->assertSee('data-navigation-sidebar', false)
        ->assertSee('data-mobile-navigation-close', false)
        ->assertSee('data-mobile-navigation-toggle', false)
        ->assertSeeText('Overview')
        ->assertSeeText('Dashboard')
        ->assertDontSeeText('Repository')
        ->assertDontSeeText('Documentation');
});

test('authenticated pages show the copyright footer', function () {
    $user = User::factory()->role('General Manager')->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertSeeText(__('authentication.copyright_notice', ['year' => now()->year]));
});

test('an empty portfolio renders every empty state', function () {
    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->assertOk()
        ->assertSee('Nothing is late today')
        ->assertSee('Nothing needs attention right now')
        ->assertSee('No funding recorded yet')
        ->assertSee('Nothing due in the next 30 days')
        ->assertSee('No activity yet');
});

test('the attention queue lists late work and materialised risks, most overdue first', function () {
    $task = Task::factory()->create(['title' => 'Submit the water survey', 'due_date' => '2026-09-10']);
    Task::factory()->create(['title' => 'Finished task', 'due_date' => '2026-09-01', 'status' => TaskStatus::Done]);
    Task::factory()->create(['title' => 'Future task', 'due_date' => '2026-11-20']);
    Milestone::factory()->create(['name' => 'Handover of wells', 'planned_start_date' => '2026-08-16', 'planned_end_date' => '2026-08-16']);
    Risk::factory()->happened()->create(['name' => 'Road closure']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->assertSee('3 items need attention')
        ->assertSeeInOrder(['Handover of wells', '30 days late', 'Submit the water survey', '5 days late', 'Road closure', 'Materialised'])
        ->assertSee(route('tasks.show', $task), false)
        ->assertDontSee('Finished task')
        ->assertDontSee('Future task');
});

test('late transfers join the attention queue tagged for the funding filter', function () {
    TransfersTracking::factory()->create([
        'payment_amount' => 25000,
        'amount_transfered_currency' => Currency::Eur->value,
        'scheduled_date' => '2026-09-05',
    ]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->assertSeeInOrder(['data-attention-group="funding"', '25,000 EUR from', '10 days late'], false);
});

test('the all view keeps room for materialised risks when late work fills the queue', function () {
    Task::factory(9)->create(['due_date' => '2026-09-01']);
    Risk::factory()->happened()->create(['name' => 'Road closure']);

    $items = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->instance()
        ->attentionItems;

    expect($items->whereNotNull('allRank'))->toHaveCount(8)
        ->and($items->firstWhere('title', 'Road closure')['allRank'])->toBe(7);
});

test('funding position keeps each currency on its own line', function () {
    $dollarAgreement = DonorProposal::factory()->create(['budget' => 1000000, 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['budget' => 400000, 'currency' => Currency::Eur]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $dollarAgreement->id,
        'payment_amount' => 250000,
        'amount_received' => 250000,
        'amount_received_currency' => Currency::Usd->value,
        'scheduled_date' => '2026-06-01',
    ]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('pages::dashboard.index');

    $lines = $component->instance()->fundingPosition->keyBy('currency');

    expect($lines->keys()->all())->toBe(['EUR', 'USD'])
        ->and($lines['USD']['committed'])->toBe(1000000.0)
        ->and($lines['USD']['share'])->toBe(24)
        ->and($lines['EUR']['committed'])->toBe(400000.0)
        ->and($lines['EUR']['received'])->toBe(0.0);

    $component->assertDontSee('1.4M')->assertSee('1,000,000');
});

test('my work counts only the signed-in person\'s open tasks', function () {
    $employeeId = insertHrEmployee();
    $myTask = Task::factory()->create(['due_date' => '2026-09-01']);
    $myTask->taskAssignees()->delete();
    $myTask->taskAssignees()->create(['employee_id' => $employeeId]);

    $dueThisWeek = Task::factory()->create(['due_date' => '2026-09-18']);
    $dueThisWeek->taskAssignees()->delete();
    $dueThisWeek->taskAssignees()->create(['employee_id' => $employeeId]);

    $doneTask = Task::factory()->create(['due_date' => '2026-09-02', 'status' => TaskStatus::Done]);
    $doneTask->taskAssignees()->delete();
    $doneTask->taskAssignees()->create(['employee_id' => $employeeId]);

    Task::factory()->create(['due_date' => '2026-09-01']);
    $user = User::factory()->role('General Manager')->create(['employee_id' => $employeeId]);

    $myWork = Livewire::actingAs($user)->test('pages::dashboard.index')->instance()->myWork;

    expect($myWork['overdue'])->toBe(1)
        ->and($myWork['dueThisWeek'])->toBe(1);
});

test('my draft reports links to the signed-in person\'s drafts only', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard.index')
        ->assertSee(e(route('reports.index', ['status' => 'draft', 'author' => $user->id])), false);
});

test('the proposal pipeline counts only open proposals', function () {
    Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    Proposal::factory()->create(['status' => ProposalStatus::Rejected]);
    Proposal::factory()->create(['status' => ProposalStatus::Completed]);

    $pipeline = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->instance()
        ->pipelineChart;

    expect($pipeline['categories'])->toBe(['Planning', 'Implementation', 'Monitoring', 'Closure'])
        ->and($pipeline['series'][0]['data'])->toBe([0, 1, 0, 0]);
});

test('recent activity describes proposal status changes', function () {
    $proposal = Proposal::factory()->create(['name' => 'Winter shelter appeal']);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'status' => ProposalStatus::Submitted,
        'date' => '2026-09-14',
    ]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->assertSee('Winter shelter appeal moved to Submitted');
});

test('pending transfers are not counted as money received', function () {
    TransfersTracking::factory()->create([
        'payment_amount' => 90000,
        'amount_received' => 90000,
        'net_amount_received' => 90000,
        'scheduled_date' => '2026-10-01',
        'ar_status' => ArStatus::Pending,
    ]);

    $receipts = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::dashboard.index')
        ->instance()
        ->receiptsChart;

    expect($receipts['currencies'])->toBe([]);
});
