<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\UserNotification;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

/**
 * A project with one proposal at the given status, owned by the given HR employee.
 */
function storyProject(ProposalStatus $status, ?int $ownerEmployeeId = null, ProjectStatus $projectStatus = ProjectStatus::Draft): Project
{
    return Activity::withoutRecording(function () use ($status, $ownerEmployeeId, $projectStatus): Project {
        $project = Project::factory()->status($projectStatus)->create(['user_id' => $ownerEmployeeId ?? insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish')]);
        $project->proposals()->attach(Proposal::factory()->create(['status' => $status])->id);

        return $project;
    });
}

test('the Story marks the chapters before the lead proposal done, its own chapter now and the rest later', function () {
    $project = storyProject(ProposalStatus::ProposalDraft);

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertSeeHtml('data-chapter="0" data-state="done"')
        ->assertSeeHtml('data-chapter="1" data-state="done"')
        ->assertSeeHtml('data-chapter="2" data-state="now"')
        ->assertSeeHtml('data-chapter="3" data-state="later"')
        ->assertSeeHtml('data-chapter="6" data-state="later"')
        ->assertSee('Finish the proposal and send it for review');
});

test('a running project with last month\'s report still open is off track and asks its owner to submit it', function () {
    $owner = userWithRole('Managers', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'));
    $project = storyProject(ProposalStatus::Active, $owner->employee_id, ProjectStatus::Active);
    Activity::withoutRecording(fn () => MonthlyReport::factory()->create(['project_id' => $project->id, 'period' => '2026-08-01']));

    Livewire::actingAs($owner)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSeeHtml('data-chapter="5" data-state="now"')
        ->assertSee('Submit the August 2026 report')
        ->assertSee('Off track')
        ->assertSee('This one is yours.')
        ->assertDontSeeHtml('data-test="story-nudge"');
});

test('nudging the holder of the next step reaches them once a day', function () {
    $owner = userWithRole('Managers', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'));
    $viewer = userWithRole('General Manager', insertHrEmployee(firstNameEn: 'Sami'));
    $project = storyProject(ProposalStatus::ConceptNote, $owner->employee_id);

    $story = Livewire::actingAs($viewer)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('Waiting on Rana Darwish.')
        ->assertSeeHtml('data-test="story-nudge"')
        ->call('nudgeNextStep')
        ->call('nudgeNextStep');

    expect(UserNotification::query()->forUser($owner)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'nudge.logged')->where('subject_id', $project->id)->count())->toBe(1);
});

test('the Story shows money received only to viewers who may see money', function () {
    $project = storyProject(ProposalStatus::Active, projectStatus: ProjectStatus::Active);
    $proposal = $project->proposals()->first();
    Activity::withoutRecording(fn () => DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => 1000]));

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertSeeHtml('data-test="story-received"');

    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertDontSeeHtml('data-test="story-received"');
});

test('opening a proposal with no tab lands on its project Story at the chapter it is in', function () {
    $project = storyProject(ProposalStatus::Submitted);
    $proposal = $project->proposals()->first();

    $this->actingAs(userWithRole('General Manager'))
        ->get(route('proposals.show', $proposal))
        ->assertRedirect(route('projects.show', $project).'#dk-ch-3');
});

test('a proposal link with a tab, or a proposal on no project, still opens the proposal page', function () {
    $user = userWithRole('General Manager');
    $project = storyProject(ProposalStatus::Submitted);
    $loose = Activity::withoutRecording(fn () => Proposal::factory()->create());

    $this->actingAs($user)->get(route('proposals.show', ['proposal' => $project->proposals()->first(), 'tab' => 'status']))->assertOk();
    $this->actingAs($user)->get(route('proposals.show', $loose))->assertOk();
});
