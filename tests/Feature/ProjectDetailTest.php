<?php

use App\Enums\Currency;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ResultType;
use App\Models\AnnualPlan;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Media;
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
    $project = projectWithRow();

    $this->get(route('projects.show', rowFor($project)))->assertRedirect(route('login'));
});

test('project detail shows the project its city program and owner', function () {
    $user = User::factory()->role('General Manager')->create();
    $owner = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish');
    $project = projectWithRow(['name' => 'Aleppo Wells', 'user_id' => $owner]);

    $this->actingAs($user)
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeText('Aleppo Wells')
        ->assertSeeText($project->city->name_en)
        ->assertSeeText($project->program->name)
        ->assertSeeText('Rana Darwish');
});

test('project detail preserves lifecycle and disagreement between headline and demographic targets', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow([
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
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeText('Active')
        ->assertSeeText('Sep 1, 2026')
        ->assertSeeText('Aug 31, 2027')
        ->assertSeeText('Restores safe household water access.')
        ->assertSeeTextInOrder(['Headline target', '1,000', 'Men', '180', 'Women', '220', 'Boys', '140', 'Girls', '160', 'Demographic total', '700']);
});

test('project detail displays an em dash when no demographic breakdown exists', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow([
        'target_beneficiaries' => 250,
        'target_men' => null,
        'target_women' => null,
        'target_boys' => null,
        'target_girls' => null,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeTextInOrder(['Headline target', '250', 'Demographic total', '—']);
});

test('project detail shows a not funded empty state with no linked proposals', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();

    $this->actingAs($user)
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeText('Not funded yet');
});

test('project detail shows committed and received funding per currency', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
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
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeText('1,000.00')
        ->assertSeeText('400.00');
});

test('the project has no Risks tab; its risks show as a read-only awareness section', function () {
    $user = User::factory()->role('General Manager')->create();
    $subject = projectWithRow();
    $other = projectWithRow();
    $linked = Proposal::factory()->create(['name' => 'Linked Emergency Grant']);
    $unlinked = Proposal::factory()->create();
    DB::table('project_proposal')->insert(['project_id' => $subject->id, 'proposal_id' => $linked->id]);
    DB::table('project_proposal')->insert(['project_id' => $other->id, 'proposal_id' => $unlinked->id]);

    Risk::factory()->create(['project_id' => $subject->id, 'name' => 'Access restrictions']);
    Risk::factory()->create(['project_id' => $other->id, 'name' => 'Currency collapse']);
    Risk::factory()->create(['project_id' => null, 'proposal_id' => $linked->id, 'name' => 'تأخر التمويل', 'name_en' => 'Funding delay', 'likelihood' => 3, 'severity' => 2]);
    Risk::factory()->create(['project_id' => null, 'proposal_id' => $unlinked->id, 'name' => 'منع الوصول', 'name_en' => 'Foreign proposal risk']);

    $component = Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($subject)->slug])
        ->assertSeeHtml('data-test="risk-awareness"')
        ->assertSee('Funding delay')
        ->assertSee('Linked Emergency Grant')
        ->assertSee('Access restrictions')
        ->assertSee(__('risks.likelihood').': '.__('risks.rating_high'))
        ->assertDontSee('Currency collapse')
        ->assertDontSee('Foreign proposal risk')
        ->assertDontSeeHtml('wire:click="detachRisk')
        ->assertDontSeeHtml('wire:click="openRisks');

    expect(collect($component->instance()->tabs)->pluck('key')->all())->not->toContain('risks');

    $component->set('tab', 'risks')->assertSet('tab', 'risks');
    expect($component->instance()->deliveryTab)->toBe('tasks');
});

test('a project with no risks shows no awareness section', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor(projectWithRow())->slug])
        ->assertDontSeeHtml('data-test="risk-awareness"');
});

test('project detail tasks tab shows tasks raised directly on the project, but not on other projects', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $otherProject = projectWithRow();

    Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => rowFor($project)->id, 'title' => 'Sign the site access agreement']);
    Task::factory()->create(['source_type' => 'proposal_location', 'source_id' => rowFor($otherProject)->id, 'title' => 'Unrelated project task']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'tasks')
        ->assertSee('Sign the site access agreement')
        ->assertDontSee('Unrelated project task');
});

test('a task can be added from the project show page pre-filled with this project', function () {
    $user = User::factory()->role('General Manager')->create();
    $assignee = insertHrEmployee();
    $project = projectWithRow();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
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

test('adding a task from the project show page requires a title but no assignee', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'tasks')
        ->call('openAddTaskModal')
        ->set('newTaskTitle', '   ')
        ->set('newTaskAssignedTo', [])
        ->call('addTask')
        ->assertHasErrors(['newTaskTitle'])
        ->assertHasNoErrors(['newTaskAssignedTo']);
});

test('a task added from the project page can ask for proof, off by default', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();

    $page = Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'tasks')
        ->call('openAddTaskModal')
        ->assertSet('newTaskEvidenceRequired', false)
        ->assertSeeHtml('data-test="new-project-task-proof-needed"')
        ->set('newTaskTitle', 'Plain task')
        ->call('addTask')
        ->assertHasNoErrors();

    $page->call('openAddTaskModal')
        ->set('newTaskTitle', 'Distribution photos')
        ->set('newTaskEvidenceRequired', true)
        ->call('addTask')
        ->assertHasNoErrors();

    expect(Task::query()->where('title', 'Plain task')->sole()->evidence_required)->toBeFalse()
        ->and(Task::query()->where('title', 'Distribution photos')->sole()->evidence_required)->toBeTrue();
});

test('project detail shows targets naming this project', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $indicator = Indicator::factory()->create(['name' => 'People reached']);
    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
        'proposal_location_id' => rowFor($project)->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'targets')
        ->assertSee('People reached');
});

test('project detail groups targets by result and shows their source, period and reporting status', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $result = Result::factory()->create(['statement' => 'Improved access to safe drinking water.', 'type' => ResultType::Outcome]);
    $indicator = Indicator::factory()->create(['name' => 'People reached', 'result_id' => $result->id]);

    $reportedTarget = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
        'proposal_location_id' => rowFor($project)->id,
    ]);
    Report::factory()->create(['plan_indicator_id' => $reportedTarget->id]);

    PlanIndicator::factory()->forProposal()->create([
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
        'proposal_location_id' => rowFor($project)->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
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
    $project = projectWithRow();
    $proposal = Proposal::factory()->create(['name' => 'Emergency WASH Response']);
    $proposal->projects()->attach(projectWithRow());

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'proposals')
        ->call('detachProposal', $proposal->id);

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeFalse();
});

test('attaching the same proposal twice does not duplicate the pivot row', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $proposal = Proposal::factory()->create();
    $project->proposals()->attach($proposal->id);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
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
    $project = projectWithRow();
    $otherProject = projectWithRow();
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
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
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
    $project = projectWithRow();
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
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
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
    $project = projectWithRow();
    Media::factory()->inCollection(MediaCollection::Evidence)->count(2)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'files')
        ->assertDontSee('All files')
        ->assertDontSee('fileCategoryFilter', false);
});

test('project detail escapes free text and offers no project delete action', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow(['description' => '<script>alert("project")</script>']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert("project")</script>', false)
        ->assertDontSee('deleteProject', false)
        ->assertDontSee('Delete project', false);
});

test('project detail renders a project with nothing attached', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow(['name' => 'Bare Project']);

    $this->actingAs($user)
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeText('Bare Project')
        ->assertSeeText(__('projects.tasks_raised_against_this_project_will_appear_here'));
});

test('the project page shows a linked proposal\'s internal number and lists attachable ones by number', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $linked = Proposal::factory()->create(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'TEMP-0021']);
    $project->proposals()->attach($linked->id);
    Proposal::factory()->create(['name' => 'Cash for Work', 'internal_project_number' => 'HC-2026-093']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'proposals')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0021"')
        ->assertSee('Cash for Work · HC-2026-093');
});

test('New Concept Note opens the proposal form for this project', function () {
    $project = projectWithRow();

    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->assertSeeHtml('data-test="new-concept-note"')
        ->assertSeeHtml(e(route('proposals.index', ['for_project' => $project->id])));
});

test('New Concept Note is offered only to proposal creators on a project that can take a proposal', function (string $role, ProjectStatus $status) {
    $project = Project::factory()->status($status)->create();
    rowFor($project);

    Livewire::actingAs(User::factory()->role($role)->create())
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->assertDontSeeHtml('data-test="new-concept-note"');
})->with([
    'no proposals.create' => ['Managers', ProjectStatus::Draft],
    'archived project' => ['Financial', ProjectStatus::Archived],
]);

test('the project page says which center hosts a hosted project', function () {
    $user = User::factory()->role('General Manager')->create();
    $idleb = insertHrCity('Idleb', 'إدلب');
    $host = insertHrCenter(insertHrCity('Aleppo', 'حلب'), 'Head Office Aleppo', 'المكتب الرئيسي حلب');
    $project = Project::factory()->hostedBy($host)->create(['name' => 'Wells Idleb', 'city_id' => $idleb]);
    rowFor($project);

    $this->actingAs($user)
        ->get(route('projects.show', rowFor($project)))
        ->assertOk()
        ->assertSeeText('Idleb')
        ->assertSeeText('Hosted by Head Office Aleppo')
        ->assertSee('data-test="hosted-by-badge"', false);

    $centered = projectWithRow(['name' => 'Aleppo Clinics', 'center_id' => $host]);

    $this->actingAs($user)
        ->get(route('projects.show', rowFor($centered)))
        ->assertOk()
        ->assertDontSee('data-test="hosted-by-badge"', false);
});
