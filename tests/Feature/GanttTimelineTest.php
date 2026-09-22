<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ScheduleDependency;
use App\Models\TransfersTracking;
use App\Models\User;
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

test('the timeline is in the sidebar and renders the portfolio gantt', function () {
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'start_date' => '2026-10-01', 'end_date' => '2027-03-31']);
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Drilling', 'planned_start_date' => '2026-11-01', 'planned_end_date' => '2026-12-15']);

    $this->actingAs(User::factory()->create())
        ->get(route('timeline.index'))
        ->assertOk()
        ->assertSee('href="'.route('timeline.index').'"', false)
        ->assertSeeHtml('data-gantt')
        ->assertSee('Aleppo Wells')
        ->assertSee('Drilling');
});

test('the portfolio nests projects under their program with milestones under each project', function () {
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => '2026-12-01', 'planned_end_date' => '2026-12-20']);
    $second = Milestone::factory()->create(['project_id' => $project->id, 'planned_start_date' => '2027-01-04', 'planned_end_date' => '2027-01-08']);
    ScheduleDependency::factory()->create(['project_id' => $project->id, 'predecessor_id' => $milestone->id, 'successor_id' => $second->id]);

    $payload = Livewire::actingAs(User::factory()->create())
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

    $component = Livewire::actingAs(User::factory()->create())
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
    $draft = Proposal::factory()->create(['status' => ProposalStatus::ConceptNote, 'planned_date_begin' => '2020-01-01', 'planned_date_end' => '2020-02-01']);
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-06-30']);
    $project->proposals()->attach($proposal);

    $component = Livewire::actingAs(User::factory()->create())
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

test('an unknown view falls back to the portfolio', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::timeline.index')
        ->set('view', 'nonsense')
        ->assertSet('view', 'portfolio');
});

test('the funding agreement page shows the agreement on one gantt', function () {
    $proposal = Proposal::factory()->create(['planned_date_begin' => '2026-06-01', 'planned_date_end' => '2026-08-31']);
    $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $otherAgreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id]);
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-03-31']);
    $project->proposals()->attach($proposal);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'due_on' => '2027-01-15']);
    $foreignReport = DonorReport::factory()->create(['donor_proposal_id' => $otherAgreement->id, 'due_on' => '2027-01-20']);
    $transfer = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-11-01']);

    $component = Livewire::actingAs(User::factory()->create())
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->assertSeeHtml('data-agreement-timeline')
        ->assertSeeHtml('data-gantt');
    $rows = timelineRows($component->instance()->ganttPayload);

    expect($rows)->toHaveKeys(['proposal-'.$proposal->id, 'project-'.$project->id, 'report-'.$report->id, 'transfer-'.$transfer->id])
        ->not->toHaveKey('report-'.$foreignReport->id);
});
