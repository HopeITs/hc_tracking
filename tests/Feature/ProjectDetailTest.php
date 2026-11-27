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

test('project detail is titled with the proposal name the list showed, and keeps the place in the path', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow(['name' => 'Al-Wadi Training Center - GIZ']);
    $row = rowFor($project);

    expect($row->headline())->toBe('Al-Wadi Training Center - GIZ')
        ->and($row->label())->not->toBe($row->headline());

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertSeeText($row->headline());

    $this->actingAs($user)
        ->get(route('projects.show', $row))
        ->assertOk()
        ->assertSeeHtml('<h1 class="page-title">')
        ->assertSeeTextInOrder([$project->city->name_en, 'Al-Wadi Training Center - GIZ'])
        ->assertSeeHtml('data-test="project-crumb"')
        ->assertSeeHtml('aria-label="'.e(__('projects.more_actions_for', ['name' => $row->headline()])).'"')
        ->assertSeeHtml('<p class="dk-side-ctx-t" dir="auto" title="Al-Wadi Training Center - GIZ">');

    expect($row->activityLabel())->toBe('Al-Wadi Training Center - GIZ');
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
    // Y-16, D-93: the money shown is that of the ONE proposal behind the row.
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->running()->create();
    rowFor($project, $proposal);
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
    // Y-16, D-93: a register risk belongs to the row; a logframe risk belongs
    // to the one proposal behind it. The row's proposal is renamed so the
    // assertion still has a name to look for.
    $subjectRow = rowFor($subject);
    $otherRow = rowFor($other);
    $linked = $subjectRow->proposal;
    $linked->update(['name' => 'Linked Emergency Grant']);
    $unlinked = $otherRow->proposal;

    Risk::factory()->create(['project_id' => $subject->id, 'proposal_location_id' => $subjectRow->id, 'name' => 'Access restrictions']);
    Risk::factory()->create(['project_id' => $other->id, 'proposal_location_id' => $otherRow->id, 'name' => 'Currency collapse']);
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

    expect($task->source_type)->toBe('proposal_location')
        ->and($task->source_id)->toBe(rowFor($project)->id)
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

    // Y-10: one target per indicator per row, so the proposal target that
    // sits beside the plan one needs its own indicator.
    PlanIndicator::factory()->forProposal()->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Households served', 'result_id' => $indicator->result_id])->id,
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

/*
| Y-17: the two cases on attaching and detaching a proposal went with the
| feature — a proposal does not link projects (§6 removes
| LinkProposalProject and UnlinkProposalProject), so the project page's
| funding panel offers neither. They are named in the Y-17 handoff.
*/

test('project files list only this projects metadata and download through the authenticated route, never the raw storage path', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $otherProject = projectWithRow();
    $media = Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'proposal_location',
        'mediable_id' => rowFor($project)->id,
        'original_name' => 'signed-agreement.pdf',
        'path' => 'media/private-storage-name.pdf',
        'type' => FileType::Pdf,
        'uploaded_at' => '2026-09-10',
    ]);
    Media::factory()->create([
        'mediable_type' => 'proposal_location',
        'mediable_id' => rowFor($otherProject)->id,
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
        ->assertSee(route('projects.files.download', [rowFor($project), $media]), false)
        ->assertDontSee('other-project-photo.jpg')
        ->assertDontSee('media/private-storage-name.pdf')
        ->assertDontSee('href="media/private-storage-name.pdf"', false);
});

test('project files show a category filter once more than one category is present, and it narrows the grid', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = projectWithRow();
    $uploader = User::factory()->create(['name' => 'Nadia Haddad']);
    Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'proposal_location',
        'mediable_id' => rowFor($project)->id,
        'original_name' => 'signed-agreement.pdf',
        'type' => FileType::Pdf,
        'size' => 1_500_000,
        'uploaded_by' => $uploader->id,
    ]);
    Media::factory()->inCollection(MediaCollection::Photo)->create([
        'mediable_type' => 'proposal_location',
        'mediable_id' => rowFor($project)->id,
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
        'mediable_type' => 'proposal_location',
        'mediable_id' => rowFor($project)->id,
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

test('the place page shows its proposal internal number', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(projectWithRow());
    // D-93: the row carries one proposal, so that is the one the page names.
    $row->proposal->update(['name' => 'Winter Shelter Kit', 'internal_project_number' => 'TEMP-0021']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $row->slug])
        ->set('tab', 'proposals')
        ->assertSeeHtml('data-placeholder-chip="TEMP-0021"');
});

test('New Concept Note opens the proposal form at this place', function () {
    $row = rowFor(projectWithRow());

    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::projects.show', ['project' => $row->slug])
        ->assertSeeHtml('data-test="new-concept-note"')
        ->assertSeeHtml(e(route('proposals.index', ['for_project' => $row->id])));
});

test('New Concept Note is offered only to proposal creators', function () {
    // Y-17: an archived place may be proposed again — the new proposal gets a
    // row of its own (D-93), so there is no project to check for
    // linkability. Only the permission decides.
    $row = rowFor(Project::factory()->status(ProjectStatus::Archived)->create());

    Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::projects.show', ['project' => $row->slug])
        ->assertSeeHtml('data-test="new-concept-note"');

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::projects.show', ['project' => $row->slug])
        ->assertDontSeeHtml('data-test="new-concept-note"');
});
