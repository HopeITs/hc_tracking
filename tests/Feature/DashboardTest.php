<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Proposal;
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

test('the home page is the desk: greeting, the role layout and the add tile', function () {
    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-desk-hello', false)
        ->assertSee('data-panel="glance"', false)
        ->assertSee('data-panel="projects"', false)
        ->assertSee('data-desk-add', false)
        ->assertSeeText(__('desk.customize'));
});

test('desk pages keep the copyright footer', function () {
    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('projects.index'))
        ->assertSeeText(__('authentication.copyright_notice', ['year' => now()->year]));
});

test('an empty portfolio renders the empty lines of the work panels', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('desk.panels.needs', ['size' => 'L'])->assertSee(__('desk.panel.needs.empty'));
    Livewire::actingAs($user)->test('desk.panels.coming', ['size' => 'M'])->assertSee(__('desk.panel.coming.empty'));
});

test('needs you lists late work and materialised risks, most overdue first', function () {
    $task = Task::factory()->create(['title' => 'Submit the water survey', 'due_date' => '2026-09-10']);
    Task::factory()->create(['title' => 'Finished task', 'due_date' => '2026-09-01', 'status' => TaskStatus::Done]);
    Task::factory()->create(['title' => 'Future task', 'due_date' => '2026-11-20']);
    Milestone::factory()->create(['name' => 'Handover of wells', 'planned_start_date' => '2026-08-16', 'planned_end_date' => '2026-08-16']);
    Risk::factory()->happened()->create(['name' => 'Road closure']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('desk.panels.needs', ['size' => 'L'])
        ->assertSeeInOrder(['Handover of wells', '30 days late', 'Submit the water survey', '5 days late', 'Road closure', 'Materialised'])
        ->assertSee(route('tasks.show', $task), false)
        ->assertDontSee('Finished task')
        ->assertDontSee('Future task');
});

test('late transfers join needs you', function () {
    TransfersTracking::factory()->create([
        'payment_amount' => 25000,
        'amount_transfered_currency' => Currency::Eur->value,
        'scheduled_date' => '2026-09-05',
    ]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('desk.panels.needs', ['size' => 'L'])
        ->assertSeeInOrder(['25,000 EUR from', '10 days late']);
});

test('the attention queue keeps room for materialised risks when late work fills it', function () {
    Task::factory(9)->create(['due_date' => '2026-09-01']);
    Risk::factory()->happened()->create(['name' => 'Road closure']);

    $items = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('desk.panels.needs', ['size' => 'L'])
        ->instance()
        ->attentionItems;

    expect($items->whereNotNull('allRank'))->toHaveCount(8)
        ->and($items->firstWhere('title', 'Road closure')['allRank'])->toBe(7);
});

test('funding position keeps each currency on its own line, in full figures', function () {
    $dollarAgreement = DonorProposal::factory()->create(['budget' => 1000000, 'currency' => Currency::Usd]);
    DonorProposal::factory()->create(['budget' => 400000, 'currency' => Currency::Eur]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $dollarAgreement->id,
        'payment_amount' => 250000,
        'amount_received' => 250000,
        'amount_received_currency' => Currency::Usd->value,
        'scheduled_date' => '2026-06-01',
    ]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.money', ['size' => 'L']);

    $lines = $component->instance()->fundingPosition->keyBy('currency');

    expect($lines->keys()->all())->toBe(['EUR', 'USD'])
        ->and($lines['USD']['committed'])->toBe(1000000.0)
        ->and($lines['USD']['share'])->toBe(24)
        ->and($lines['EUR']['committed'])->toBe(400000.0)
        ->and($lines['EUR']['received'])->toBe(0.0);

    $component->assertDontSee('1.4M')->assertSee('$1,000,000')->assertSee('€400,000');
});

test('pending transfers are not counted as money received', function () {
    TransfersTracking::factory()->create([
        'payment_amount' => 90000,
        'amount_received' => 90000,
        'net_amount_received' => 90000,
        'scheduled_date' => '2026-10-01',
        'ar_status' => ArStatus::Pending,
    ]);

    $lines = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('desk.panels.money', ['size' => 'L'])
        ->instance()
        ->fundingPosition;

    expect($lines->sum('received'))->toBe(0.0);
});

test('the proposal pipeline counts every status and its bars filter the list', function () {
    Proposal::factory()->create(['name' => 'Winter shelter appeal', 'status' => ProposalStatus::Submitted]);
    Proposal::factory()->create(['name' => 'Closed appeal', 'status' => ProposalStatus::Rejected]);

    $panel = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.pipeline', ['size' => 'L']);

    expect($panel->instance()->counts['submitted'])->toBe(1)
        ->and($panel->instance()->counts['rejected'])->toBe(1);

    $panel->call('setFilter', 'submitted')
        ->assertSee('Winter shelter appeal')
        ->assertDontSee('Closed appeal');
});

test('what happened lists activity log sentences and filters by module', function () {
    $user = User::factory()->role('General Manager')->create();
    $this->actingAs($user);
    Proposal::factory()->create(['name' => 'Winter shelter appeal']);
    Activity::withoutRecording(fn () => Task::factory()->create());

    Livewire::actingAs($user)->test('desk.panels.activity', ['size' => 'L'])
        ->assertSee('Winter shelter appeal')
        ->call('setFilter', 'tasks')
        ->assertDontSee('Winter shelter appeal');
});
