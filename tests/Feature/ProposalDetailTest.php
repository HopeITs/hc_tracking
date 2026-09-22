<?php

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected from proposal detail', function () {
    $proposal = Proposal::factory()->create();

    $this->get(route('proposals.show', $proposal))->assertRedirect(route('login'));
});

test('proposal detail shows its name status and derived phase', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create([
        'name' => 'Emergency WASH Response',
        'status' => ProposalStatus::Active,
    ]);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeText('Emergency WASH Response')
        ->assertSeeText('Active')
        // ProposalStatus::Active belongs to ProposalPhase::Monitoring.
        ->assertSeeText('Monitoring');
});

test('proposal detail shows only its own status notes newest first', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();
    $other = Proposal::factory()->create();

    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Older note',
        'date' => '2026-01-01',
    ]);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $proposal->id,
        'context' => 'Newer note',
        'date' => '2026-06-01',
    ]);
    ProposalStatusNote::factory()->create([
        'proposal_id' => $other->id,
        'context' => 'Someone elses note',
    ]);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeTextInOrder(['Newer note', 'Older note'])
        ->assertDontSeeText('Someone elses note');
});

test('changing status updates the proposal and appends an audit note in one act', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '  Grant agreement countersigned.  ')
        ->set('statusDate', '2026-04-01')
        ->call('changeStatus')
        ->assertHasNoErrors();

    $proposal->refresh();
    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($proposal->status)->toBe(ProposalStatus::Approved)
        ->and($note->status)->toBe(ProposalStatus::Approved)
        ->and($note->context)->toBe('Grant agreement countersigned.')
        ->and($note->date->toDateString())->toBe('2026-04-01');
});

test('a status change with no context is rejected and changes nothing', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('newStatus', ProposalStatus::Approved->value)
        ->set('statusContext', '   ')
        ->call('changeStatus')
        ->assertHasErrors(['statusContext']);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('funding is totalled per currency and never summed across them', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'UNICEF'])->id,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => '200000.00',
        'currency' => Currency::Eur,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding')
        ->assertSee('UNICEF')
        ->assertSee('ECHO')
        ->assertSee('500,000.00')
        ->assertSee('200,000.00')
        ->assertSee('USD')
        ->assertSee('EUR')
        // 700,000 would be the forbidden cross-currency sum.
        ->assertDontSee('700,000.00');
});

test('proposal detail shows targets committed in this proposal', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'Households assisted']);

    PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'indicator_id' => $indicator->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'targets')
        ->assertSee('Households assisted');
});

test('proposal detail renders a proposal with nothing attached', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['name' => 'Bare Proposal']);

    $this->actingAs($user)
        ->get(route('proposals.show', $proposal))
        ->assertOk()
        ->assertSeeText('Bare Proposal')
        ->assertSeeText('No status history yet');
});

test('the money reading is the received share of the committed budget', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'budget' => '100000.00',
        'currency' => Currency::Usd,
    ]);

    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '40000.00',
        'amount_received' => '40000.00',
        'net_amount_received' => '40000.00',
        'fees' => '0.00',
        'scheduled_date' => '2026-03-01',
    ]);

    $vitals = collect(Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->instance()
        ->vitals)
        ->keyBy('key');

    expect($vitals['money']['figure'])->toBe('40')
        ->and($vitals['money']['suffix'])->toBe('%')
        ->and($vitals['money']['caption'])->toContain('100,000')
        ->and($vitals['money']['caption'])->toContain('USD');
});

test('a pending tranche whose scheduled date has passed puts the money reading in alarm', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'budget' => '100000.00',
        'currency' => Currency::Usd,
    ]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '60000.00',
        'scheduled_date' => now()->subMonth()->toDateString(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding');

    $money = collect($component->instance()->vitals)->firstWhere('key', 'money');

    expect($money['alarm'])->toBeTrue();

    $component->assertSeeText('Overdue');
});

test('the work reading counts finished tasks and groups them under the project their milestone reaches', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $project = Project::factory()->create(['name' => 'Aleppo Water Response']);
    $proposal->projects()->attach($project);

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Task::factory()->completed()->create([
        'proposal_id' => $proposal->id,
        'milestone_id' => $milestone->id,
        'title' => 'Verify beneficiary list',
        'due_date' => null,
    ]);
    Task::factory()->create([
        'proposal_id' => $proposal->id,
        'milestone_id' => $milestone->id,
        'title' => 'Procure water pumps',
        'due_date' => null,
        'progress_percentage' => 0,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'tasks');

    $work = collect($component->instance()->vitals)->firstWhere('key', 'work');

    expect($work['figure'])->toBe('1')
        ->and($work['suffix'])->toBe('/2');

    $component->assertSeeTextInOrder(['Aleppo Water Response', 'Verify beneficiary list'])
        // Undated tasks cannot be placed on the schedule, and the page says so.
        ->assertSeeText('be placed on the schedule');
});

test('a task whose milestone belongs to a project this proposal does not fund is listed apart', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $unfunded = Project::factory()->create(['name' => 'Someone Elses Project']);
    $milestone = Milestone::factory()->create(['project_id' => $unfunded->id]);

    Task::factory()->create([
        'proposal_id' => $proposal->id,
        'milestone_id' => $milestone->id,
        'title' => 'Stranded task',
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'tasks')
        ->assertSeeText('Work not tied to a funded project')
        ->assertSeeText('Stranded task');
});

test('the promises reading counts targets carrying a report and flags one committed off the proposal', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $funded = Project::factory()->create();
    $proposal->projects()->attach($funded);

    $reported = PlanIndicator::factory()->forProposal($proposal)->create([
        'project_id' => $funded->id,
        'target' => '100.00',
    ]);
    Report::factory()->approved()->create([
        'plan_indicator_id' => $reported->id,
        'value' => '25.00',
    ]);

    // Committed against a project this proposal does not fund: a real state the
    // page must name rather than quietly render as if it were sound.
    PlanIndicator::factory()->forProposal($proposal)->create([
        'project_id' => Project::factory()->create()->id,
        'target' => '50.00',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'targets');

    $promises = collect($component->instance()->vitals)->firstWhere('key', 'promises');

    expect($promises['figure'])->toBe('1')
        ->and($promises['suffix'])->toBe('/2');

    $component->assertSeeText('The project is not funded by this proposal.');
});

test('the time reading places today inside the grant term', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create([
        'planned_date_begin' => now()->subDays(9)->toDateString(),
        'planned_date_end' => now()->addDays(90)->toDateString(),
        'actual_date_begin' => null,
        'actual_date_end' => null,
    ]);

    $time = collect(Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->instance()
        ->vitals)
        ->firstWhere('key', 'time');

    expect($time['figure'])->toBe('10')
        ->and($time['suffix'])->toBe('/100')
        ->and($time['alarm'])->toBeFalse();
});

test('an unknown tab from an old bookmark falls back to status history rather than failing the page', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'money'])
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSet('tab', 'status')
        ->set('tab', 'promises')
        ->assertSet('tab', 'status')
        ->assertSeeText('No status history yet');
});

test('each tab shows only its own content and the strip counts its records', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'context' => 'Board signed off']);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'UNICEF'])->id,
    ]);
    Task::factory()->create(['proposal_id' => $proposal->id, 'title' => 'Draft the budget narrative']);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal]);

    expect(collect($component->instance()->tabs)->pluck('count', 'key')->all())
        ->toBe(['status' => 1, 'deadlines' => 1, 'funding' => 1, 'projects' => 0, 'tasks' => 1, 'targets' => 0, 'reports' => 0, 'documents' => 0]);

    $component->assertSeeText('Board signed off')
        ->assertDontSeeText('UNICEF')
        ->assertDontSeeText('Draft the budget narrative')
        ->set('tab', 'funding')
        ->assertSeeText('UNICEF')
        ->assertDontSeeText('Board signed off')
        ->set('tab', 'tasks')
        ->assertSeeText('Draft the budget narrative')
        ->assertDontSeeText('UNICEF');
});

test('the arrivals climb accumulates only received tranches, in the order they landed', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create([
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'budget' => '100000.00',
        'currency' => Currency::Usd,
    ]);

    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '25000.00',
        'amount_received' => '25000.00',
        'net_amount_received' => '25000.00',
        'fees' => '0.00',
        'scheduled_date' => '2026-04-01',
    ]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '15000.00',
        'amount_received' => '15000.00',
        'net_amount_received' => '15000.00',
        'fees' => '0.00',
        'scheduled_date' => '2026-07-01',
    ]);
    // Still pending, so it belongs to the ceiling gap and never to the climb.
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '60000.00',
        'scheduled_date' => '2026-10-01',
    ]);

    $climb = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->instance()
        ->arrivals['climbs'][0];

    expect($climb['currency'])->toBe('USD')
        ->and($climb['landed'])->toBe(40000.0)
        ->and($climb['committed'])->toBe(100000.0)
        // Two received tranches draw two risers, so four points sit between the
        // baseline and the closing run to the end of the scale.
        ->and(substr_count($climb['line'], ' '))->toBe(5)
        ->and($climb['area'])->toEndWith('L1000,100 Z');
});

test('each currency climbs against its own commitment and is never combined', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    foreach ([['USD', Currency::Usd, '100000.00'], ['EUR', Currency::Eur, '50000.00']] as [$code, $currency, $budget]) {
        DonorProposal::factory()->create([
            'proposal_id' => $proposal->id,
            'donor_id' => Donor::factory()->create()->id,
            'budget' => $budget,
            'currency' => $currency,
        ]);
    }

    $climbs = collect(Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->instance()
        ->arrivals['climbs'])
        ->keyBy('currency');

    expect($climbs->keys()->all())->toBe(['USD', 'EUR'])
        ->and($climbs['USD']['committed'])->toBe(100000.0)
        ->and($climbs['EUR']['committed'])->toBe(50000.0)
        // 150,000 would be the forbidden cross-currency total.
        ->and($climbs->sum('committed'))->not->toBe(0.0);
});

test('the scrubber payload dates a project that began before the scale with a negative offset', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create([
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ]);

    $project = Project::factory()->create([
        'start_date' => '2025-10-01',
        'end_date' => '2026-06-30',
    ]);
    $proposal->projects()->attach($project);

    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'budget' => '100000.00',
        'currency' => Currency::Usd,
    ]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '30000.00',
        'amount_received' => '30000.00',
        'fees' => '0.00',
        'scheduled_date' => '2026-03-02',
    ]);

    $scrub = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->instance()
        ->arrivals['scrub'];

    // The scale opens on the project's own start, so the grant term sits inside it.
    expect($scrub['start'])->toBe('2025-10-01')
        ->and($scrub['projects'][0]['from'])->toBe(0)
        ->and($scrub['projects'][0]['to'])->toBe(272)
        ->and($scrub['money'][0]['currency'])->toBe('USD')
        ->and($scrub['money'][0]['transfers'][0]['received'])->toBeTrue()
        ->and($scrub['money'][0]['transfers'][0]['at'])->toBe(152)
        ->and($scrub['today'])->toBeGreaterThanOrEqual(0)
        ->and($scrub['today'])->toBeLessThanOrEqual($scrub['days']);
});

test('a proposal with no money still renders the chronology and offers no climb', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['name' => 'Unfunded Concept']);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding')
        ->assertOk()
        ->assertSeeText('Unfunded Concept');

    $arrivals = $component->instance()->arrivals;

    expect($arrivals['climbs'])->toBe([])
        ->and($arrivals['scrub']['money'])->toBe([])
        ->and($arrivals['scrub']['days'])->toBeGreaterThan(0);
});

test('the tasks tab puts open work before finished work and flags overdue and lagging tasks', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Task::factory()->completed()->create(['proposal_id' => $proposal->id, 'milestone_id' => null, 'title' => 'Finished survey']);
    Task::factory()->create([
        'proposal_id' => $proposal->id,
        'milestone_id' => null,
        'title' => 'Late procurement',
        'status' => TaskStatus::InProgress,
        'progress_percentage' => 40,
        'due_date' => now()->subDays(3)->toDateString(),
    ]);
    Task::factory()->create([
        'proposal_id' => $proposal->id,
        'milestone_id' => null,
        'title' => 'Forgotten status',
        'status' => TaskStatus::Todo,
        'progress_percentage' => 100,
        'due_date' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'tasks')
        ->assertSeeTextInOrder(['Late procurement', 'Forgotten status', 'Finished survey'])
        ->assertSeeHtml('data-status-lags')
        ->assertSeeText('100% done, but the status is still Todo')
        ->assertSeeHtml('data-task-gap="overdue"');
});

test('a finished task waiting under review is not flagged as a lagging status', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Task::factory()->create([
        'proposal_id' => $proposal->id,
        'milestone_id' => null,
        'status' => TaskStatus::UnderReview,
        'progress_percentage' => 100,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'tasks')
        ->assertDontSeeHtml('data-status-lags');
});
