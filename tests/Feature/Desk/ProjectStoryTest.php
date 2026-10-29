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

test('every proposal link lands on its project Story with the proposal, tab and agreement deep link carried over', function (array $query, array $expected) {
    $project = storyProject(ProposalStatus::Submitted);
    $proposal = $project->proposals()->first();

    $this->actingAs(userWithRole('General Manager'))
        ->get(route('proposals.show', ['proposal' => $proposal, ...$query]))
        ->assertRedirect(route('projects.show', ['project' => $project, 'proposal' => $proposal->id, ...$expected]).'#proposal');
})->with([
    'bare' => [[], []],
    'a tab' => [['tab' => 'plan'], ['ptab' => 'plan']],
    'the record-agreement link' => [['record_agreement' => '1'], ['record_agreement' => 1]],
]);

test('a proposal on no project still opens its own page on the desk', function () {
    $loose = Activity::withoutRecording(fn () => Proposal::factory()->create());

    $this->actingAs(userWithRole('General Manager'))
        ->get(route('proposals.show', ['proposal' => $loose, 'tab' => 'plan']))
        ->assertOk()
        ->assertSeeHtml('data-proposal-back')
        ->assertSeeHtml('data-tab-panel="plan"');
});

test('the Story embeds the chosen proposal on the asked tab and ignores a proposal from another project', function () {
    $project = storyProject(ProposalStatus::Submitted);
    $lead = $project->proposals()->first();
    $second = Activity::withoutRecording(fn () => Proposal::factory()->create(['status' => ProposalStatus::ConceptNote]));
    $project->proposals()->attach($second->id);
    $stranger = Activity::withoutRecording(fn () => Proposal::factory()->create());
    $viewer = userWithRole('General Manager');

    Livewire::actingAs($viewer)->withQueryParams(['proposal' => (string) $second->id, 'ptab' => 'deadlines'])
        ->test('pages::projects.show', ['project' => $project])
        ->assertSet('embeddedProposalId', $second->id)
        ->assertSeeHtml('data-proposal-id="'.$second->id.'"')
        ->assertSeeHtml('data-tab-panel="deadlines"')
        ->assertDontSeeHtml('data-compact-proposal');

    Livewire::actingAs($viewer)->withQueryParams(['ptab' => 'deadlines'])
        ->test('pages::proposals.show', ['proposal' => $second, 'embedded' => true])
        ->assertSet('tab', 'deadlines')
        ->assertSeeHtml('data-tab-panel="deadlines"')
        ->assertSeeHtml('data-embedded-proposal-head')
        ->assertDontSeeHtml('data-proposal-back');

    Livewire::actingAs($viewer)->withQueryParams(['proposal' => (string) $stranger->id])
        ->test('pages::projects.show', ['project' => $project])
        ->assertSet('embeddedProposalId', $lead->id);
});

test('the compact embedded proposal hides the tabs until one is picked', function () {
    $proposal = storyProject(ProposalStatus::Submitted)->proposals()->first();

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::proposals.show', ['proposal' => $proposal, 'embedded' => true, 'compact' => true])
        ->assertSeeHtml('data-compact-proposal')
        ->assertDontSeeHtml('data-tab-panel')
        ->set('tab', 'deadlines')
        ->assertSet('compact', false)
        ->assertSeeHtml('data-tab-panel="deadlines"')
        ->assertSeeHtml('data-hide-details')
        ->set('compact', true)
        ->assertDontSeeHtml('data-tab-panel');
});

test('the embedded proposal funding tab shows the table without the overview toggle', function () {
    $proposal = storyProject(ProposalStatus::Approved)->proposals()->first();

    Livewire::actingAs(userWithRole('General Manager'))->withQueryParams(['ptab' => 'funding'])
        ->test('pages::proposals.show', ['proposal' => $proposal, 'embedded' => true])
        ->assertDontSee(__('finance_tables.view_overview'));
});

test('the embedded proposal keeps money from a viewer without money permission', function () {
    $project = storyProject(ProposalStatus::Active, projectStatus: ProjectStatus::Active);
    $proposal = $project->proposals()->first();
    Activity::withoutRecording(fn () => DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => 987654]));

    Livewire::actingAs(userWithRole('General Manager'))->withQueryParams(['ptab' => 'funding'])
        ->test('pages::proposals.show', ['proposal' => $proposal, 'embedded' => true])
        ->assertSet('tab', 'funding')
        ->assertSee('987,654');

    Livewire::actingAs(userWithRole('Team Member'))->withQueryParams(['ptab' => 'funding'])
        ->test('pages::proposals.show', ['proposal' => $proposal, 'embedded' => true])
        ->assertSet('tab', 'status')
        ->assertDontSee('987,654');
});

test('a rejected proposal keeps its owner on the next step but offers no nudge', function () {
    $owner = userWithRole('Managers', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish'));
    $project = storyProject(ProposalStatus::Rejected, $owner->employee_id);

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('Waiting on Rana Darwish.')
        ->assertDontSeeHtml('data-test="story-nudge"');
});
