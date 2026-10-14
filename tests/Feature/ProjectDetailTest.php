<?php

use App\Enums\Currency;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from project detail', function () {
    $project = Project::factory()->create();

    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
});

test('project detail shows the project its city program and owner', function () {
    $user = User::factory()->role('General Manager')->create();
    $owner = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish');
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'user_id' => $owner]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Aleppo Wells')
        ->assertSeeText($project->city->name_en)
        ->assertSeeText($project->program->name)
        ->assertSeeText('Rana Darwish');
});

test('project detail preserves lifecycle and disagreement between headline and demographic targets', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create([
        'description' => 'Restores safe household water access.',
        'status' => ProjectStatus::Active,
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
        'target_beneficiaries' => 1000,
        'target_men' => 180,
        'target_women' => 220,
        'target_boys' => 140,
        'target_girls' => 160,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Active')
        ->assertSeeText('Sep 1, 2026')
        ->assertSeeText('Aug 31, 2027')
        ->assertSeeText('Restores safe household water access.')
        ->assertSeeTextInOrder(['Headline target', '1,000', 'Demographic total', '700'])
        ->assertSeeTextInOrder(['Men', '180', 'Women', '220', 'Boys', '140', 'Girls', '160']);
});

test('project detail displays an em dash when no demographic breakdown exists', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create([
        'target_beneficiaries' => 250,
        'target_men' => null,
        'target_women' => null,
        'target_boys' => null,
        'target_girls' => null,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeTextInOrder(['Headline target', '250', 'Demographic total', '—']);
});

test('project detail renders its milestones as a single delivery timeline', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $responsible = User::factory()->role('General Manager')->create();
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'name' => 'Baseline assessment',
        'status' => MilestoneStatus::Reached,
        'sequence' => 1,
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'name' => 'Household survey',
        'status' => MilestoneStatus::Planned,
        'sequence' => 2,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSet('tab', 'milestones')
        ->assertSee('Baseline assessment')
        ->assertSee('Household survey')
        ->assertSee(MilestoneStatus::Reached->label())
        ->assertSee(MilestoneStatus::Planned->label())
        ->assertDontSee('data-tree-depth', false);
});

test('project detail shows weighted milestone progress in the header', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $responsible = User::factory()->role('General Manager')->create();
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'status' => MilestoneStatus::Reached,
        'weight_percentage' => 60,
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'status' => MilestoneStatus::Planned,
        'weight_percentage' => 40,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('60%')
        ->assertSeeText('1 of 2 milestones reached');
});

test('project detail shows a not funded empty state with no linked proposals', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Not funded yet');
});

test('project detail shows committed and received funding per currency', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $project->proposals()->attach($proposal->id);
    $donorProposal = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'currency' => Currency::Usd,
        'budget' => 1000,
    ]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $donorProposal->id,
        'net_amount_received' => 400,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('1,000.00')
        ->assertSeeText('400.00');
});

test('project detail shows only its own risks', function () {
    $user = User::factory()->role('General Manager')->create();
    $subject = Project::factory()->create();
    $other = Project::factory()->create();
    Risk::factory()->create(['project_id' => $subject->id, 'name' => 'Access restrictions']);
    Risk::factory()->create(['project_id' => $other->id, 'name' => 'Currency collapse']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $subject])
        ->set('tab', 'risks')
        ->assertSee('Access restrictions')
        ->assertDontSee('Currency collapse');
});

test('project detail tasks tab shows tasks raised directly on the project and on its milestones, but not on other projects', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);
    $otherProject = Project::factory()->create();

    Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'title' => 'Sign the site access agreement']);
    Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Deliver the milestone report']);
    Task::factory()->create(['source_type' => 'project', 'source_id' => $otherProject->id, 'title' => 'Unrelated project task']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'tasks')
        ->assertSee('Sign the site access agreement')
        ->assertSee('Deliver the milestone report')
        ->assertDontSee('Unrelated project task');
});

test('a task can be added from the project show page pre-filled with this project', function () {
    $user = User::factory()->role('General Manager')->create();
    $assignee = insertHrEmployee();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'tasks')
        ->call('openAddTaskModal')
        ->assertSet('showAddTaskModal', true)
        ->set('newTaskTitle', '  Household survey  ')
        ->set('newTaskAssignedTo', [(string) $assignee])
        ->call('addTask')
        ->assertHasNoErrors()
        ->assertSet('showAddTaskModal', false);

    $task = Task::query()->where('title', 'Household survey')->sole();

    expect($task->source_type)->toBe('project')
        ->and($task->source_id)->toBe($project->id)
        ->and($task->taskAssignees->pluck('employee_id')->all())->toBe([$assignee]);
});

test('adding a task from the project show page requires a title and an assignee', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'tasks')
        ->call('openAddTaskModal')
        ->set('newTaskTitle', '   ')
        ->set('newTaskAssignedTo', [])
        ->call('addTask')
        ->assertHasErrors(['newTaskTitle', 'newTaskAssignedTo']);
});

test('project detail shows targets naming this project', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'People reached']);
    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'targets')
        ->assertSee('People reached');
});

test('project detail groups targets by result and shows their source, period and reporting status', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $result = Result::factory()->create(['statement' => 'Improved access to safe drinking water.', 'type' => ResultType::Outcome]);
    $indicator = Indicator::factory()->create(['name' => 'People reached', 'result_id' => $result->id]);

    $reportedTarget = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
    ]);
    Report::factory()->create(['plan_indicator_id' => $reportedTarget->id]);

    PlanIndicator::factory()->forProposal()->create([
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'targets')
        ->assertSeeText('Improved access to safe drinking water.')
        ->assertSeeText('People reached')
        ->assertSeeText(ResultType::Outcome->label())
        ->assertSeeText('Annual plan')
        ->assertSeeText('Proposal')
        ->assertSeeText('Reports filed')
        ->assertSeeText('No reports filed yet');
});

test('a proposal can be attached to and detached from a project', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Emergency WASH Response']);
    $proposal->projects()->attach(Project::factory()->create());

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->call('detachProposal', $proposal->id);

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeFalse();
});

test('attaching the same proposal twice does not duplicate the pivot row', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $project->proposals()->attach($proposal->id);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal');

    expect(DB::table('project_proposal')
        ->where('project_id', $project->id)
        ->where('proposal_id', $proposal->id)
        ->count())->toBe(1);
});

test('project files list only this projects metadata and download through the authenticated route, never the raw storage path', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $media = Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'original_name' => 'signed-agreement.pdf',
        'path' => 'media/private-storage-name.pdf',
        'type' => FileType::Pdf,
        'uploaded_at' => '2026-09-10',
    ]);
    Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $otherProject->id,
        'original_name' => 'other-project-photo.jpg',
        'type' => FileType::Jpg,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->assertSee('signed-agreement.pdf')
        ->assertSee('PDF')
        ->assertSee('Agreement')
        ->assertSee('Sep 10, 2026')
        ->assertSee(route('projects.files.download', [$project, $media]), false)
        ->assertDontSee('other-project-photo.jpg')
        ->assertDontSee('media/private-storage-name.pdf')
        ->assertDontSee('href="media/private-storage-name.pdf"', false);
});

test('project files show a category filter once more than one category is present, and it narrows the grid', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $uploader = User::factory()->create(['name' => 'Nadia Haddad']);
    Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'original_name' => 'signed-agreement.pdf',
        'type' => FileType::Pdf,
        'size' => 1_500_000,
        'uploaded_by' => $uploader->id,
    ]);
    Media::factory()->inCollection(MediaCollection::Photo)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'original_name' => 'site-visit.jpg',
        'type' => FileType::Jpg,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->assertSee('signed-agreement.pdf')
        ->assertSee('site-visit.jpg')
        ->assertSee('Nadia Haddad')
        ->assertSee('1.4 MB')
        ->assertSeeInOrder(['All files', 'Agreement', 'Photo']);

    $component->set('fileCategoryFilter', 'agreement')
        ->assertSee('signed-agreement.pdf')
        ->assertDontSee('site-visit.jpg');
});

test('project files show no filter chips when every file shares one category', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    Media::factory()->inCollection(MediaCollection::Evidence)->count(2)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->assertDontSee('All files')
        ->assertDontSee('fileCategoryFilter', false);
});

test('project detail escapes free text and offers no project delete action', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['description' => '<script>alert("project")</script>']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert("project")</script>', false)
        ->assertDontSee('deleteProject', false)
        ->assertDontSee('Delete project', false);
});

test('project detail renders a project with nothing attached', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['name' => 'Bare Project']);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Bare Project')
        ->assertSeeText('No milestones yet');
});

test('the project page shows a linked proposal\'s internal number and lists attachable ones by number', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();
    $linked = Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'TEMP-0021']);
    $project->proposals()->attach($linked->id);
    Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-093']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0021"')
        ->assertSee('Cash for Work · HC-2026-093');
});
