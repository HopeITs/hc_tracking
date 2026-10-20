<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ScheduleDependency;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Support\Desk\Destinations;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, array<string, mixed>>
 */
function timelineRows(array $payload): array
{
    return collect($payload['rows'])->keyBy('id')->all();
}

test('guests are redirected from the timeline', function () {
    $this->get(route('timeline.index'))->assertRedirect(route('login'));
});

test('the timeline can be reached from the Ask bar and renders the portfolio gantt', function () {
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'start_date' => '2026-10-01', 'end_date' => '2027-03-31']);
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Drilling', 'planned_start_date' => '2026-11-01', 'planned_end_date' => '2026-12-15']);

    $manager = User::factory()->role('General Manager')->create();

    expect(array_column(app(Destinations::class)->for($manager), 'route'))->toContain('timeline.index');

    $this->actingAs($manager)
        ->get(route('timeline.index'))
        ->assertOk()
        ->assertSeeHtml('data-gantt')
        ->assertSee('Aleppo Wells')
        ->assertSee('Drilling');
});

test('the portfolio nests projects under their program with milestones under each project', function () {
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => '2026-12-01', 'planned_end_date' => '2026-12-20']);
    $second = Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-01-08']);
    ScheduleDependency::factory()->create(['project_id' => $project->id, 'predecessor_id' => $milestone->id, 'successor_id' => $second->id]);

    $payload = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->instance()
        ->payload;
    $rows = timelineRows($payload);

    expect($rows['project-'.$project->id])->toMatchArray(['kind' => 'project', 'depth' => 1, 'summary' => true, 'start' => '2026-10-01', 'end' => '2027-01-08'])
        ->and($rows['milestone-'.$milestone->id])->toMatchArray(['parent' => 'project-'.$project->id, 'depth' => 2])
        ->and($payload['links'])->toHaveCount(1);
});

test('the portfolio filters by program and status', function () {
    $kept = Project::factory()->create(['status' => ProjectStatus::Active]);
    $otherProgram = Project::factory()->create(['status' => ProjectStatus::Active]);
    $otherStatus = Project::factory()->create(['status' => ProjectStatus::Closed, 'program_id' => $kept->program_id]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->set('program', (string) $kept->program_id)
        ->set('status', ProjectStatus::Active->value);

    $rows = timelineRows($component->instance()->payload);

    expect($rows)->toHaveKey('project-'.$kept->id)
        ->not->toHaveKey('project-'.$otherProgram->id)
        ->not->toHaveKey('project-'.$otherStatus->id);
    $component->assertSet('status', 'active');
});

test('the pipeline groups proposals by phase with the projects they fund beneath them', function () {
    $proposal = Proposal::factory()->create(['name' => 'Winter response', 'status' => ProposalStatus::Active, 'planned_date_begin' => '2026-09-01', 'planned_date_end' => '2027-08-31']);
    $draft = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft, 'planned_date_begin' => '2020-01-01', 'planned_date_end' => '2020-02-01']);
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-06-30']);
    $project->proposals()->attach($proposal);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->set('view', 'pipeline');
    $rows = timelineRows($component->instance()->payload);

    expect($rows['phase-monitoring']['kind'])->toBe('group')
        ->and($rows['proposal-'.$proposal->id])->toMatchArray(['parent' => 'phase-monitoring', 'tone' => 'funding', 'summary' => false, 'end' => '2027-08-31'])
        ->and($rows['funded-'.$proposal->id.'-'.$project->id])->toMatchArray(['kind' => 'project', 'parent' => 'proposal-'.$proposal->id])
        ->and($rows['proposal-'.$draft->id]['tone'])->toBe('critical');

    $component->set('phase', 'planning');

    expect(timelineRows($component->instance()->payload))->not->toHaveKey('proposal-'.$proposal->id)
        ->toHaveKey('proposal-'.$draft->id);
});

test('the portfolio reads every project\'s critical path and predecessors', function () {
    $project = Project::factory()->create(['start_date' => '2026-11-01', 'end_date' => '2027-01-31']);
    $first = Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => '2026-12-01', 'planned_end_date' => '2026-12-20']);
    $last = Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-01-08']);
    ScheduleDependency::factory()->create(['project_id' => $project->id, 'predecessor_id' => $first->id, 'successor_id' => $last->id]);

    $rows = timelineRows(Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->instance()
        ->payload);

    expect($rows['milestone-'.$last->id])->toMatchArray(['critical' => true, 'float' => 0, 'predecessors' => $rows['milestone-'.$first->id]['wbs'].'FS'])
        ->and($rows['milestone-'.$first->id]['critical'])->toBeFalse()
        ->and($rows['milestone-'.$first->id]['float'])->toBeGreaterThan(0)
        ->and($rows['project-'.$project->id])->toMatchArray(['planUrl' => route('projects.show', ['project' => $project, 'tab' => 'plan'])]);
});

test('the portfolio groups projects by status or by owner', function () {
    $active = Project::factory()->create(['status' => ProjectStatus::Active]);
    $draft = Project::factory()->create(['status' => ProjectStatus::Draft]);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->set('group', 'status');
    $rows = timelineRows($component->instance()->payload);

    expect($rows['status-active']['kind'])->toBe('group')
        ->and($rows['project-'.$active->id]['parent'])->toBe('status-active')
        ->and($rows['project-'.$draft->id]['parent'])->toBe('status-draft');

    $rows = timelineRows($component->set('group', 'owner')->instance()->payload);

    expect($rows['project-'.$active->id]['parent'])->toBe('owner-'.$active->user_id)
        ->and($rows['owner-'.$active->user_id]['kind'])->toBe('group');

    $component->set('group', 'nonsense')->assertSet('group', 'program');
});

test('the funding view ties proposals, transfers and reports to the projects they fund', function () {
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-06-30']);
    $elsewhere = Project::factory()->create();
    $proposal = Proposal::factory()->create(['planned_date_begin' => '2026-06-01', 'planned_date_end' => '2026-09-30']);
    $unrelated = Proposal::factory()->create();
    $foreign = Proposal::factory()->create();
    $project->proposals()->attach($proposal);
    $elsewhere->proposals()->attach($foreign);
    $fundingDonorId = Donor::factory()->create(['name' => 'Aid Fund'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $fundingDonorId, 'budget' => 120000, 'currency' => 'USD']);
    $transfer = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-11-01', 'payment_amount' => 40000]);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => '2027-01-15']);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->set('view', 'connected')
        ->set('program', (string) $project->program_id);
    $payload = $component->instance()->payload;
    $rows = timelineRows($payload);

    expect($rows['proposal-'.$proposal->id])->toMatchArray(['parent' => 'fundraising', 'owner' => 'Aid Fund', 'money' => '120,000 USD'])
        ->and($rows['project-'.$project->id]['kind'])->toBe('project')
        ->and($rows['transfer-'.$transfer->id])->toMatchArray(['parent' => 'obligations', 'money' => '40,000 USD'])
        ->and($rows['report-'.$report->id]['parent'])->toBe('obligations')
        ->and($rows)->not->toHaveKeys(['proposal-'.$unrelated->id, 'proposal-'.$foreign->id, 'project-'.$elsewhere->id])
        ->and($payload['relations'])->toEqualCanonicalizing([
            ['from' => 'proposal-'.$proposal->id, 'to' => 'project-'.$project->id, 'type' => 'funds'],
            ['from' => 'transfer-'.$transfer->id, 'to' => 'proposal-'.$proposal->id, 'type' => 'pays'],
            ['from' => 'report-'.$report->id, 'to' => 'proposal-'.$proposal->id, 'type' => 'reports'],
        ]);

    $component->assertSeeHtml('data-gantt-horizon')
        ->assertSeeHtml('data-gantt-inspector');
});

test('an unknown view falls back to the portfolio', function () {
    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->set('view', 'nonsense')
        ->assertSet('view', 'portfolio');

    Livewire::withQueryParams(['view' => 'nonsense', 'group' => 'nonsense'])
        ->actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::timeline.index')
        ->assertSet('view', 'portfolio')
        ->assertSet('group', 'program');
});

test('the funding agreement page shows the agreement on one gantt', function () {
    $proposal = Proposal::factory()->create(['planned_date_begin' => '2026-06-01', 'planned_date_end' => '2026-08-31']);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $otherAgreement = DonorProposal::factory()->create();
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-03-31']);
    $project->proposals()->attach($proposal);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => '2027-01-15']);
    $foreignReport = DonorReport::factory()->create(['donor_proposal_id' => $otherAgreement->id, 'due_on' => '2027-01-20']);
    $transfer = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-11-01']);

    $component = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->set('view', 'overview')
        ->assertSeeHtml('data-agreement-timeline')
        ->assertSeeHtml('data-gantt');
    $rows = timelineRows($component->instance()->ganttPayload);

    expect($rows)->toHaveKeys(['proposal-'.$proposal->id, 'project-'.$project->id, 'report-'.$report->id, 'transfer-'.$transfer->id])
        ->not->toHaveKey('report-'.$foreignReport->id);
});
