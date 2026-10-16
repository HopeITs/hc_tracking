<?php

use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
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

    Activity::withoutRecording(function () use ($proposal, $other): void {
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
    });

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
        ->call('openStatusModal', $proposal->id)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->set('statusContext', '  Donor asked to resubmit.  ')
        ->call('changeStatus')
        ->assertHasNoErrors()
        ->assertSet('showStatusModal', false);

    $proposal->refresh();
    $note = ProposalStatusNote::query()->where('proposal_id', $proposal->id)->sole();

    expect($proposal->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($note->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($note->context)->toBe('Donor asked to resubmit.')
        ->and($note->changed_by)->toBe($user->id)
        ->and($note->is_correction)->toBeFalse();
});

test('a status change with no context is rejected and changes nothing', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->call('openStatusModal', $proposal->id)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->set('statusContext', '   ')
        ->call('changeStatus')
        ->assertHasErrors(['statusContext']);

    expect($proposal->refresh()->status)->toBe(ProposalStatus::Submitted)
        ->and(ProposalStatusNote::query()->where('proposal_id', $proposal->id)->count())->toBe(0);
});

test('funding shows the agreement in its own currency', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $fundingDonorId = Donor::factory()->create(['name' => 'UNICEF'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $fundingDonorId,
        'budget' => '500000.00',
        'currency' => Currency::Usd,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'funding')
        ->assertSee('UNICEF')
        ->assertSee('500,000.00')
        ->assertSee('USD');
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

test('the work reading counts finished tasks and the plan tab lists the funded projects\' milestone work', function () {
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create();

    $project = Project::factory()->create(['name' => 'Aleppo Water Response']);
    $proposal->projects()->attach($project);

    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Task::factory()->completed()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'title' => 'Verify beneficiary list',
        'due_date' => null,
    ]);
    Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'title' => 'Procure water pumps',
        'due_date' => null,
        'progress_percentage' => 0,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan');

    $work = collect($component->instance()->vitals)->firstWhere('key', 'work');

    expect($work['figure'])->toBe('1')
        ->and($work['suffix'])->toBe('/2');

    $component->assertSeeText('Verify beneficiary list')
        ->assertSeeText('Procure water pumps')
        // Undated tasks cannot be placed on the schedule, and the page says so.
        ->assertSeeText('be placed on the schedule');
});

test('a task sourced from a milestone whose project this proposal does not fund is not shown', function () {
    // A task now has exactly one source, so a task sourced from a milestone can no
    // longer also carry an independent link to this proposal — it belongs to
    // whichever proposal funds its milestone's project, or to no proposal at all.
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    $unfunded = Project::factory()->create(['name' => 'Someone Elses Project']);
    $milestone = Milestone::factory()->create(['project_id' => $unfunded->id]);

    Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'title' => 'Stranded task',
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->assertDontSeeText('Stranded task');
});

test('a task sourced directly from the proposal is listed apart from funded-project work', function () {
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create();

    $funded = Project::factory()->create(['name' => 'Aleppo Water Response']);
    $proposal->projects()->attach($funded);
    $milestone = Milestone::factory()->create(['project_id' => $funded->id]);

    Task::factory()->create([
        'source_type' => 'milestone',
        'source_id' => $milestone->id,
        'title' => 'Milestone task',
    ]);
    Task::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'title' => 'Proposal-level task',
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->assertSeeHtml('data-plan-group="other"')
        ->assertSeeText('Milestone task')
        ->assertSeeText('Proposal-level task');
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
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create();

    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'context' => 'Board signed off']);
    $fundingDonorId = Donor::factory()->create(['name' => 'UNICEF'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $fundingDonorId,
    ]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Draft the budget narrative']);

    $component = Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal]);

    expect(collect($component->instance()->tabs)->pluck('count', 'key')->all())
        ->toBe(['status' => 1, 'deadlines' => 1, 'funding' => 1, 'projects' => 0, 'logframe' => null, 'targets' => 0, 'plan' => 1, 'reports' => 0, 'documents' => 0]);

    $component->assertSeeText('Board signed off')
        ->assertDontSeeText('UNICEF')
        ->assertDontSeeText('Draft the budget narrative')
        ->set('tab', 'funding')
        ->assertSeeText('UNICEF')
        ->assertDontSeeText('Board signed off')
        ->set('tab', 'plan')
        ->assertSeeText('Draft the budget narrative')
        ->assertDontSeeText('UNICEF');
});

test('a department task can be added from the plan tab pre-filled with this proposal', function () {
    $user = User::factory()->role('Financial')->create();
    $department = insertHrDepartment('Finance');
    $assignee = insertHrEmployee();
    insertHrContract($assignee, insertHrPosition($department));
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->call('openPlanTaskForm')
        ->assertSet('showPlanTaskModal', true)
        ->set('planTask.title', 'Draft the budget narrative')
        ->set('planTask.department_id', (string) $department)
        ->set('planTask.assignee_employee_id', (string) $assignee)
        ->set('planTask.due_date', now()->addDays(10)->toDateString())
        ->call('savePlanTask')
        ->assertHasNoErrors()
        ->assertSet('showPlanTaskModal', false);

    $task = Task::query()->where('title', 'Draft the budget narrative')->sole();

    expect($task->source_type)->toBe('proposal')
        ->and($task->source_id)->toBe($proposal->id)
        ->and($task->department_id)->toBe($department)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$assignee]);
});

test('adding a department task from the plan tab requires a title, a department and a due date', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->call('openPlanTaskForm')
        ->set('planTask.title', '   ')
        ->call('savePlanTask')
        ->assertHasErrors(['planTask.title', 'planTask.department_id', 'planTask.due_date'])
        ->assertSet('showPlanTaskModal', true);
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

test('the climb is drawn against the commitment in the agreement currency', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '100000.00', 'currency' => Currency::Usd]);

    $climbs = collect(Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->instance()
        ->arrivals['climbs'])
        ->keyBy('currency');

    expect($climbs->keys()->all())->toBe(['USD'])
        ->and($climbs['USD']['committed'])->toBe(100000.0);
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
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create();

    Task::factory()->completed()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Finished survey']);
    Task::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'title' => 'Late procurement',
        'status' => TaskStatus::InProgress,
        'progress_percentage' => 40,
        'due_date' => now()->subDays(3)->toDateString(),
    ]);
    Task::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'title' => 'Forgotten status',
        'status' => TaskStatus::Todo,
        'progress_percentage' => 100,
        'due_date' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->assertSeeTextInOrder(['Late procurement', 'Forgotten status', 'Finished survey'])
        ->assertSeeHtml('data-status-lags')
        ->assertSeeText('100% done, but the status is still Todo')
        ->assertSeeHtml('data-task-gap="overdue"');
});

test('a finished task waiting under review is not flagged as a lagging status', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Task::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $proposal->id,
        'status' => TaskStatus::UnderReview,
        'progress_percentage' => 100,
    ]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->assertDontSeeHtml('data-status-lags');
});

test('the proposal header shows its internal number and the agreement form names it', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'TEMP-0012']);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSee('Internal project number')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0012"')
        ->call('openAgreementCreate')
        ->assertSee('Winter Shelter Kit · TEMP-0012');
});

test('the department task form is rendered only while its modal is open', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->set('tab', 'plan')
        ->assertDontSeeHtml('wire:model="planTask.title"')
        ->call('openPlanTaskForm')
        ->assertSeeHtml('wire:model="planTask.title"');
});

test('the status and details forms are rendered only while their modals are open', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertDontSeeHtml('wire:model="statusContext"')
        ->call('openStatusModal', $proposal->id)
        ->set('newStatus', ProposalStatus::ProposalDraft->value)
        ->assertSeeHtml('wire:model="statusContext"');

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertDontSeeHtml('wire:model="notes"')
        ->call('openEditModal', $proposal->id)
        ->assertSeeHtml('wire:model="notes"');
});

test('the indicators and targets tab renders the targets component, no longer under construction', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSeeText('Indicators & Targets')
        ->set('tab', 'targets')
        ->assertSeeHtml('data-tab-panel="targets"')
        ->assertSeeLivewire('proposals.targets')
        ->assertDontSeeHtml('data-under-construction="targets"');
});

test('the logical framework tab renders the proposal framework, no longer under construction', function () {
    $user = User::factory()->role('Financial')->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $proposal])
        ->assertSeeText('Logical Framework')
        ->set('tab', 'logframe')
        ->assertSeeHtml('data-tab-panel="logframe"')
        ->assertDontSeeHtml('data-under-construction="logframe"')
        ->assertSeeLivewire('proposals.logframe');
});

test('the tab labels read in Arabic', function () {
    $user = User::factory()->role('Financial')->create(['locale' => 'ar']);
    app()->setLocale('ar');

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => Proposal::factory()->create()])
        ->assertSeeText('الإطار المنطقي')
        ->assertSeeText('المؤشرات والمستهدفات')
        ->set('tab', 'plan')
        ->assertSeeText('المراحل')
        ->assertDontSeeHtml('data-under-construction="plan"');
});
