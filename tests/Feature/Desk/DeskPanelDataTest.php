<?php

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

test('a project with a late monthly report is off track, one with a milestone due this week needs a look', function () {
    $late = Project::factory()->create(['name' => 'Late Wells']);
    MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-07-01']);
    $soon = Project::factory()->create(['name' => 'Soon Clinic']);
    Milestone::factory()->create(['project_id' => $soon->id, 'status' => MilestoneStatus::Planned, 'planned_start_date' => '2026-09-01', 'planned_end_date' => '2026-09-18']);
    $calm = Project::factory()->create(['name' => 'Calm School']);

    $panel = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.projects', ['size' => 'XL'])->call('setScope', 'all');
    $lines = $panel->instance()->lines;

    expect($lines[$late->id]['health'])->toBe('bad')
        ->and($lines[$soon->id]['health'])->toBe('warn')
        ->and($lines[$calm->id]['health'])->toBe('good');

    $panel->assertSeeInOrder(['Late Wells', 'Soon Clinic', 'Calm School']);
});

test('a project takes its stage from its leading proposal', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalReview]);
    $project->proposals()->attach($proposal);

    $lines = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.projects', ['size' => 'XL'])->instance()->lines;

    expect($lines[$project->id]['stage'])->toBe(2);
});

test('the stepper moves a task assigned to me up to under review, never to done', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $mine = Activity::withoutRecording(function () use ($me): Task {
        $task = Task::factory()->create(['title' => 'Mine to finish', 'due_date' => '2026-09-20']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $me->employee_id]);

        return $task;
    });

    Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->assertSeeHtml("move({$mine->id}, 'under review')")
        ->call('move', $mine->id, TaskStatus::Done->value);

    expect($mine->fresh()->status)->not->toBe(TaskStatus::Done);

    Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])
        ->call('move', $mine->id, TaskStatus::UnderReview->value);

    expect($mine->fresh()->status)->toBe(TaskStatus::UnderReview);
});

test('a task outside the viewer\'s list cannot be completed from the panel', function () {
    $me = userWithRole('Team Member', insertHrEmployee());
    $other = Task::factory()->create(['title' => 'Somebody else']);

    Livewire::actingAs($me)->test('desk.panels.tasks', ['size' => 'L'])->call('move', $other->id, TaskStatus::Done->value);

    expect($other->fresh()->status)->not->toBe(TaskStatus::Done);
});

test('waiting on others lists my monthly reports at MEAL and my late tasks held by others', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $colleague = insertHrEmployee(firstNameEn: 'Rana');
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'user_id' => $me->employee_id]);
    MonthlyReport::factory()->submitted()->create(['project_id' => $project->id, 'period' => '2026-08-01', 'submitted_by' => $me->id]);
    Activity::withoutRecording(function () use ($me, $colleague): void {
        $task = Task::factory()->create(['title' => 'Book the venue', 'due_date' => '2026-09-01', 'created_by' => $me->id]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $colleague]);
    });

    Livewire::actingAs($me)->test('desk.panels.waiting', ['size' => 'M'])
        ->assertSee('Book the venue')
        ->assertSee('Aleppo Wells');
});

test('donor reporting shows every open report to agreement viewers and only one\'s own to others', function () {
    $employeeId = insertHrEmployee();
    $responsible = userWithRole('Team Member', $employeeId);
    DonorReport::factory()->create(['title' => 'Mine to write', 'responsible_employee_id' => $employeeId, 'due_on' => '2026-09-30']);
    DonorReport::factory()->create(['title' => 'Someone else\'s', 'due_on' => '2026-09-30']);

    Livewire::actingAs($responsible)->test('desk.panels.duties', ['size' => 'M'])
        ->assertSee('Mine to write')
        ->assertDontSee('Someone else');

    Livewire::actingAs(userWithRole('Financial'))->test('desk.panels.duties', ['size' => 'M'])
        ->assertSee('Mine to write')
        ->assertSee('Someone else');
});

test('department deliverables show the Media Team media work only and hide finance work from those without money', function () {
    Task::factory()->mediaDeliverable()->forProposal(Proposal::factory()->create())->create(['title' => 'Shoot the opening']);
    Task::factory()->financial()->forProposal(Proposal::factory()->create())->create(['title' => 'Pay the contractor']);

    Livewire::actingAs(userWithRole('Media Team'))->test('desk.panels.deliverables', ['size' => 'M'])
        ->assertDontSee('Pay the contractor');

    Livewire::actingAs(userWithRole('Financial'))->test('desk.panels.deliverables', ['size' => 'M'])
        ->assertSee('Pay the contractor')
        ->assertDontSee('Shoot the opening');
});

test('the dates lens opens the timeline as its full register', function () {
    Project::factory()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.projects', ['size' => 'XL'])
        ->assertSeeHtml('href="'.route('projects.index').'"')
        ->call('setLens', 'dates')
        ->assertSeeHtml('href="'.route('timeline.index').'"');
});

test('someone who owns no project sees every project without picking a scope', function () {
    Project::factory()->create(['name' => 'Aleppo Wells']);

    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.projects', ['size' => 'XL'])
        ->assertSee('Aleppo Wells')
        ->assertDontSeeHtml('data-model="scope"');
});

test('an owner starts on their own projects and can switch to all', function () {
    $owner = userWithRole('Managers', insertHrEmployee());
    Project::factory()->create(['name' => 'My Wells', 'user_id' => $owner->employee_id]);
    Project::factory()->create(['name' => 'Their Clinic']);

    Livewire::actingAs($owner)->test('desk.panels.projects', ['size' => 'XL'])
        ->assertSee('My Wells')
        ->assertDontSee('Their Clinic')
        ->call('setScope', 'all')
        ->assertSee('Their Clinic');
});

test('the pipeline names the donor a proposal is written for before any agreement is signed', function () {
    $donor = Donor::factory()->create(['name' => 'Caritas Poland']);
    Proposal::factory()->create(['name' => 'Winter shelter repairs', 'donor_id' => $donor->id, 'status' => ProposalStatus::ProposalReview]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.pipeline', ['size' => 'L'])
        ->assertSee('Winter shelter repairs')
        ->assertSee('Caritas Poland')
        ->assertDontSee(__('desk.no_donor_yet'));
});

test('the donors panel lists only donors with a proposal written for them, counting those proposals', function () {
    $active = Donor::factory()->create(['name' => 'Mennonite Central Committee']);
    Donor::factory()->create(['name' => 'Dormant Foundation']);
    Proposal::factory()->count(2)->create(['donor_id' => $active->id]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())->test('desk.panels.donors', ['size' => 'L'])
        ->assertSee('Mennonite Central Committee')
        ->assertSee(trans_choice('desk.proposals_count', 2, ['count' => 2]))
        ->assertDontSee('Dormant Foundation');
});

test('the pipeline filters by status from its popup, names the donor, and hides money from non-money viewers', function () {
    $donor = Donor::factory()->create(['name' => 'Aurora Foundation']);
    Proposal::factory()->create(['name' => 'Wells for Idlib', 'donor_id' => $donor->id, 'status' => ProposalStatus::ConceptNoteReview, 'requested_budget' => 250000, 'requested_currency' => 'USD']);
    Proposal::factory()->create(['name' => 'School Meals', 'status' => ProposalStatus::Approved]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.pipeline', ['size' => 'W'])
        ->assertSee(['Wells for Idlib', 'Aurora Foundation', '$250,000', 'School Meals'])
        ->set('status', ProposalStatus::Approved->value)
        ->assertSee('School Meals')
        ->assertDontSee('Wells for Idlib');

    $this->seed(RolesAndPermissionsSeeder::class);
    $fundraiser = User::factory()->create();
    $fundraiser->givePermissionTo('proposals.view');

    Livewire::actingAs($fundraiser)->test('desk.panels.pipeline', ['size' => 'W'])
        ->assertSee('Wells for Idlib')
        ->assertDontSee('$250,000');
});

test('funding position sums each agreement in its own currency, filters by status and stays hidden from non-money viewers', function () {
    $paid = DonorProposal::factory()->create(['budget' => 300000, 'currency' => 'EUR']);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $paid->id, 'payment_amount' => 120000, 'amount_received' => 120000, 'net_amount_received' => 120000, 'amount_received_currency' => 'EUR', 'amount_transfered_currency' => 'EUR', 'scheduled_date' => '2026-06-01']);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $paid->id, 'payment_amount' => 5000, 'amount_received' => 5000, 'net_amount_received' => 5000, 'amount_received_currency' => 'USD', 'scheduled_date' => '2026-06-02']);
    $late = DonorProposal::factory()->create(['budget' => 50000, 'currency' => 'USD']);
    TransfersTracking::factory()->create(['donor_proposal_id' => $late->id, 'payment_amount' => 10000, 'scheduled_date' => '2026-09-01', 'transfer_date' => null, 'ar_status' => ArStatus::Pending]);

    $card = Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.money', ['size' => 'L']);
    $rows = $card->instance()->rows->keyBy(fn (array $row): int => $row['agreement']->id);

    expect($rows[$paid->id])->toMatchArray(['received' => 120000.0, 'committed' => 300000.0, 'status' => 'partly'])
        ->and($rows[$late->id]['status'])->toBe('overdue');

    $card->set('facets', ['status' => __('desk.funding_status.overdue')])
        ->assertSee(route('funding-agreements.show', $late), false)
        ->assertDontSee(route('funding-agreements.show', $paid), false);

    expect(Livewire::actingAs(userWithRole('Team Member', insertHrEmployee()))->test('desk.panels.money', ['size' => 'L'])->instance()->rows)->toBeEmpty();
});

test('donor reporting lists overdue first, one row per regenerated report, and filters by state', function () {
    $agreement = DonorProposal::factory()->create();
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Due later', 'due_on' => '2026-10-20']);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Overdue one', 'due_on' => '2026-09-01']);
    $old = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Quarter v1', 'due_on' => '2026-09-25', 'period_start' => '2026-04-01', 'period_end' => '2026-06-30', 'version' => 1]);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Quarter v2', 'due_on' => '2026-09-25', 'type' => $old->type, 'period_start' => '2026-04-01', 'period_end' => '2026-06-30', 'version' => 2]);

    $card = Livewire::actingAs(userWithRole('Financial'))->test('desk.panels.duties', ['size' => 'W']);

    expect($card->instance()->rows->pluck('title')->all())->toBe(['Overdue one', 'Quarter v2', 'Due later']);

    $card->set('facets', ['status' => __('desk.donor_report_state.overdue')])
        ->assertSee('Overdue one')
        ->assertDontSee('Due later');
});

test('donors show a proposal status strip and filter by where their newest proposal stands', function () {
    $active = Donor::factory()->create(['name' => 'Aurora Foundation']);
    $quiet = Donor::factory()->create(['name' => 'Cedar Trust']);
    Proposal::factory()->create(['donor_id' => $active->id, 'status' => ProposalStatus::ConceptNoteReview]);
    Proposal::factory()->create(['donor_id' => $quiet->id, 'status' => ProposalStatus::Rejected]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.donors', ['size' => 'L'])
        ->assertSeeHtml('class="dk-dot-act"')
        ->assertSeeHtml('class="dk-dot-bad"')
        ->set('facets', ['status' => __('desk.donor_strip.bad')])
        ->assertSee('Cedar Trust')
        ->assertDontSee('Aurora Foundation');
});

test('each project lens shows its own line: money counts only its currency, results add targets, dates count days left', function () {
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active]);
    $project->proposals()->attach($proposal);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => 100000, 'currency' => 'EUR']);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => 40000, 'amount_received' => 40000, 'net_amount_received' => 40000, 'amount_transfered_currency' => 'EUR', 'amount_received_currency' => 'EUR', 'scheduled_date' => '2026-03-01']);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => 9000, 'amount_received' => 9000, 'net_amount_received' => 9000, 'amount_transfered_currency' => 'EUR', 'amount_received_currency' => 'USD', 'scheduled_date' => '2026-04-01']);
    MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Validated]);
    MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-09-01']);

    $panel = Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.projects', ['size' => 'S'])->call('setScope', 'all');
    $line = $panel->instance()->lines[$project->id];

    expect($line['money'])->toMatchArray(['currency' => 'EUR', 'committed' => 100000.0, 'received' => 40000.0])
        ->and($line['results'])->toMatchArray(['validated' => 1, 'total' => 1]);

    $panel->call('setLens', 'results')->assertSee(__('desk.targets_on_pace'))
        ->call('setLens', 'dates')->assertSee(trans_choice('desk.days_left', 107, ['count' => 107]))
        ->call('setLens', 'money')->assertSee('€40,000');
});

test('annual plans compare done against expected and count the targets behind', function () {
    $plan = AnnualPlan::factory()->create(['year' => 2026]);
    $indicator = Indicator::factory()->create(['measure' => 'numeric']);
    $behind = PlanIndicator::factory()->create(['source_id' => $plan->id, 'indicator_id' => $indicator->id, 'target' => 100]);
    $ahead = PlanIndicator::factory()->create(['source_id' => $plan->id, 'indicator_id' => $indicator->id, 'target' => 100]);
    Report::factory()->create(['plan_indicator_id' => $behind->id, 'status' => ReportStatus::Approved, 'value' => 10]);
    Report::factory()->create(['plan_indicator_id' => $ahead->id, 'status' => ReportStatus::Approved, 'value' => 90]);

    $card = Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.plans', ['size' => 'L']);
    $row = $card->instance()->rows->first();

    expect($row['done'])->toBe(50.0)
        ->and($row['behind'])->toBe(1)
        ->and($row['onPace'])->toBeFalse()
        ->and(round($card->instance()->expected()))->toBe(71.0);

    $card->set('facets', ['status' => __('desk.plan_on_pace')])->assertSee(__('desk.panel.plans.empty'));
});

test('report templates status lists running projects missing a template first, then pending, then approved', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $missing = Project::factory()->create(['name' => 'No Template Yet', 'status' => ProjectStatus::Active]);
    $pending = Project::factory()->create(['name' => 'Pending One', 'status' => ProjectStatus::Active]);
    $approved = Project::factory()->create(['name' => 'Approved One', 'status' => ProjectStatus::Active]);
    ReportTemplate::factory()->create(['project_id' => $pending->id, 'status' => ReportTemplateStatus::Pending]);
    ReportTemplate::factory()->create(['project_id' => $approved->id, 'status' => ReportTemplateStatus::Confirmed]);
    Project::factory()->create(['name' => 'Closed Long Ago', 'status' => ProjectStatus::Closed]);

    $card = Livewire::actingAs(User::factory()->role('MEAL')->create())->test('desk.panels.templates', ['size' => 'M']);

    expect($card->instance()->rows->map(fn (array $row): array => [$row['project']->name, $row['state']])->all())->toBe([
        ['No Template Yet', 'missing'], ['Pending One', 'pending'], ['Approved One', 'approved'],
    ]);
    $card->assertSee(__('desk.template_action.missing'))->assertDontSee('Closed Long Ago');
});

test('transfers switch view from the filter popup, and a bad value falls back to what is still to arrive', function () {
    $late = TransfersTracking::factory()->create(['payment_amount' => 77777, 'scheduled_date' => '2026-09-01', 'transfer_date' => null, 'ar_status' => ArStatus::Pending]);
    TransfersTracking::factory()->create(['payment_amount' => 33333, 'scheduled_date' => '2026-10-01', 'transfer_date' => null, 'ar_status' => ArStatus::Pending]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())->test('desk.panels.transfers', ['size' => 'S'])
        ->assertSee(['77,777', '33,333'])
        ->set('filter', 'late')
        ->assertSee('77,777')
        ->assertDontSee('33,333')
        ->set('filter', 'nonsense')
        ->assertSet('filter', 'all');
});
