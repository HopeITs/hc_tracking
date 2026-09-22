<?php

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the milestone directory', function () {
    $this->get(route('milestones.index'))->assertRedirect(route('login'));
});

test('milestones render as a responsive tree grouped by project with reachable actions', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Aleppo Wells']);
    $responsible = User::factory()->create(['name' => 'Rana Hamad']);
    $parent = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'name' => 'Baseline assessment',
    ]);
    $child = Milestone::factory()->childOf($parent)->create([
        'responsible_user_id' => $responsible->id,
        'name' => 'Household survey',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'list')
        ->assertSee('Aleppo Wells')
        ->assertSee('Baseline assessment')
        ->assertSee('Household survey')
        ->assertSee('data-tree-depth="1"', false)
        ->assertSee('wire:click="openCreateModal"', false)
        ->assertSee('href="'.route('milestones.show', $parent).'"', false)
        ->assertSee('wire:click="confirmDelete('.$child->id.')"', false)
        ->assertDontSee('wire:click="confirmDelete('.$parent->id.')"', false)
        ->assertSee('aria-disabled="true"', false)
        ->assertSee('cursor-not-allowed', false)
        ->assertSee(__('milestones.only_a_milestone_with_no_nested_milestones_and_no_tasks_can'));
});

test('milestones can be filtered by project and status', function () {
    $user = User::factory()->create();
    $first = Project::factory()->create();
    $second = Project::factory()->create();

    Milestone::factory()->create(['project_id' => $first->id, 'name' => 'First gate', 'status' => MilestoneStatus::Reached]);
    Milestone::factory()->create(['project_id' => $second->id, 'name' => 'Second gate', 'status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('project', (string) $first->id)
        ->assertSee('First gate')
        ->assertDontSee('Second gate')
        ->call('clearFilters')
        ->set('status', MilestoneStatus::Planned->value)
        ->assertDontSee('First gate')
        ->assertSee('Second gate');
});

test('the dropdown filter actions set and clear the project, status and responsible filters', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = insertHrEmployee();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('filterByProject', $project->id)
        ->assertSet('project', (string) $project->id)
        ->call('filterByProject')
        ->assertSet('project', '')
        ->call('filterByStatus', MilestoneStatus::Reached->value)
        ->assertSet('status', MilestoneStatus::Reached->value)
        ->call('filterByStatus', 'not-a-real-status')
        ->assertSet('status', '')
        ->call('filterByResponsible', $responsible)
        ->assertSet('responsible', (string) $responsible)
        ->call('filterByResponsible')
        ->assertSet('responsible', '');
});

test('a matching child remains visible with its necessary ancestors for status and responsible filters', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $parentOwner = User::factory()->create();
    $childOwner = User::factory()->create();
    $parent = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $parentOwner->id,
        'name' => 'Parent context',
        'status' => MilestoneStatus::Planned,
    ]);
    Milestone::factory()->childOf($parent)->create([
        'responsible_user_id' => $childOwner->id,
        'name' => 'Matching child',
        'status' => MilestoneStatus::Reached,
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $parentOwner->id,
        'name' => 'Unrelated root',
        'status' => MilestoneStatus::Planned,
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('status', MilestoneStatus::Reached->value)
        ->assertSee('Parent context')
        ->assertSee('Matching child')
        ->assertDontSee('Unrelated root')
        ->call('clearFilters')
        ->set('responsible', (string) $childOwner->id)
        ->assertSee('Parent context')
        ->assertSee('Matching child')
        ->assertDontSee('Unrelated root');
});

test('the create modal renders a project dropdown with every project option', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Coastal Relief']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->assertSee('id="milestone-form-project"', false)
        ->assertSee('$set(\'milestoneProjectId\', '.$project->id.')', false)
        ->assertSee('Coastal Relief');
});

test('a milestone can be created under a parent in the same project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = insertHrEmployee();
    $parent = Milestone::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('milestoneProjectId', (string) $project->id)
        ->set('name', '  Water quality testing  ')
        ->set('responsibleUserId', (string) $responsible)
        ->set('parentMilestoneId', (string) $parent->id)
        ->set('milestoneStatus', MilestoneStatus::Planned->value)
        ->set('plannedStartDate', '2026-06-30')
        ->call('saveMilestone')
        ->assertHasNoErrors()
        ->assertSet('showMilestoneModal', false);

    $milestone = Milestone::query()->where('name', 'Water quality testing')->sole();

    expect($milestone->parent_milestone_id)->toBe($parent->id)
        ->and($milestone->project_id)->toBe($project->id)
        ->and($milestone->responsible_user_id)->toBe($responsible);
});

test('milestones created through the form are sequenced after their existing siblings, not entered by hand', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = insertHrEmployee();
    $parent = Milestone::factory()->create(['project_id' => $project->id]);
    Milestone::factory()->childOf($parent)->create(['sequence' => 1]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('milestoneProjectId', (string) $project->id)
        ->set('name', 'Second nested gate')
        ->set('responsibleUserId', (string) $responsible)
        ->set('parentMilestoneId', (string) $parent->id)
        ->set('plannedStartDate', '2026-06-30')
        ->assertDontSee('wire:model="sequence"', false)
        ->call('saveMilestone')
        ->assertHasNoErrors();

    $milestone = Milestone::query()->where('name', 'Second nested gate')->sole();

    expect($milestone->sequence)->toBe(2);
});

test('the create modal has a description field and saves it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = insertHrEmployee();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->assertSee('wire:model="description"', false)
        ->set('milestoneProjectId', (string) $project->id)
        ->set('name', 'Borehole drilling')
        ->set('description', '  Drill and cap the new borehole.  ')
        ->set('responsibleUserId', (string) $responsible)
        ->set('plannedStartDate', '2026-06-30')
        ->call('saveMilestone')
        ->assertHasNoErrors();

    $milestone = Milestone::query()->where('name', 'Borehole drilling')->sole();

    expect($milestone->description)->toBe('Drill and cap the new borehole.');
});

test('changing the project while creating clears the selected parent', function () {
    $user = User::factory()->create();
    $first = Project::factory()->create();
    $second = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $first->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('milestoneProjectId', (string) $first->id)
        ->set('parentMilestoneId', (string) $parent->id)
        ->set('milestoneProjectId', (string) $second->id)
        ->assertSet('parentMilestoneId', '');
});

test('each milestone links to its own edit page instead of an edit modal', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'list')
        ->assertSee('href="'.route('milestones.show', $milestone).'"', false)
        ->assertDontSee('wire:click="openEditModal', false);
});

test('the add button next to a project prefills its project on the create modal', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Coastal Relief']);
    Milestone::factory()->create(['project_id' => $project->id]);
    $otherProject = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'list')
        ->assertSee('data-test="milestone-list-add-'.$project->id.'"', false)
        ->call('openCreateModal', null, null, $project->id)
        ->assertSet('showMilestoneModal', true)
        ->assertSet('milestoneProjectId', (string) $project->id)
        ->assertSet('parentMilestoneId', '');

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal', null, null, $otherProject->id + 999)
        ->assertSet('milestoneProjectId', '');
});

test('the add button next to a milestone prefills its project and nests the new milestone under it', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Root gate']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'list')
        ->assertSee('data-test="milestone-add-nested-'.$parent->id.'"', false)
        ->call('openCreateModal', null, null, null, $parent->id)
        ->assertSet('showMilestoneModal', true)
        ->assertSet('milestoneProjectId', (string) $project->id)
        ->assertSet('parentMilestoneId', (string) $parent->id);
});

test('creating a milestone requires its core delivery fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('name', '   ')
        ->set('plannedStartDate', '')
        ->set('responsibleUserId', '')
        ->set('milestoneProjectId', '')
        ->call('saveMilestone')
        ->assertHasErrors(['name', 'plannedStartDate', 'responsibleUserId', 'milestoneProjectId']);
});

test('the create parent picker is limited to the selected project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Sibling gate']);
    Milestone::factory()->create(['project_id' => $otherProject->id, 'name' => 'Other project gate']);

    $component = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('milestoneProjectId', (string) $project->id);

    $names = $component->instance()->parentOptions()->pluck('name')->all();

    expect($names)->toContain('Sibling gate')
        ->not->toContain('Other project gate');
});

test('the list view no longer displays milestone files', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();
    $media = Media::factory()->forMilestone($milestone->id)->create([
        'original_name' => 'signed-evidence.pdf',
        'path' => 'private/never-expose.pdf',
        'type' => FileType::Pdf,
        'collection' => MediaCollection::Evidence,
        'uploaded_at' => '2026-05-14',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'list')
        ->set('project', (string) $milestone->project_id)
        ->assertDontSeeText('signed-evidence.pdf')
        ->assertDontSee($media->path);

    expect($component->instance()->projectsWithMilestones()->first()->milestones->first()->relationLoaded('media'))->toBeFalse();
});

test('confirming delete opens the delete modal with the milestone name', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['name' => 'Foundation poured']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingMilestoneId', $milestone->id)
        ->assertSeeText('Foundation poured');
});

test('cancelling delete closes the modal without deleting the milestone', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->call('cancelDelete')
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingMilestoneId', null);

    $this->assertDatabaseHas('milestones', ['id' => $milestone->id]);
});

test('a leaf milestone with no tasks can be deleted', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteMilestone')
        ->assertSet('showDeleteModal', false)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('milestones', ['id' => $milestone->id]);
});

test('a milestone with children is not deleted because the subtree would cascade', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create();
    $child = Milestone::factory()->childOf($parent)->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $parent->id)
        ->call('deleteMilestone');

    expect(DB::table('milestones')->find($parent->id))->not->toBeNull()
        ->and(DB::table('milestones')->find($child->id))->not->toBeNull();
});

test('a milestone carrying tasks is not deleted because tasks would be orphaned', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->call('deleteMilestone');

    expect(DB::table('milestones')->find($milestone->id))->not->toBeNull()
        ->and(DB::table('tasks')->find($task->id)->source_id)->toBe($milestone->id);
});

test('the stat cards summarise reached, missed and upcoming milestones', function () {
    $user = User::factory()->create();
    $today = now()->startOfDay();

    Milestone::factory()->create(['status' => MilestoneStatus::Reached, 'planned_start_date' => $today->copy()->subDays(10)]);
    Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => $today->copy()->subDays(5)]);
    Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => $today->copy()->subDay()]);
    Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => $today->copy()->addDays(3)]);
    Milestone::factory()->create(['status' => MilestoneStatus::Planned, 'planned_end_date' => $today->copy()->addDays(30)]);

    $stats = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->instance()
        ->milestoneStats();

    expect($stats)->toBe([
        'total' => 5,
        'reached' => 1,
        'missed' => 2,
        'due_soon' => 1,
    ]);
});

test('the milestone directory distinguishes empty and filtered states', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->assertSeeText('No milestones yet')
        ->set('status', MilestoneStatus::Cancelled->value)
        ->assertSeeText('No matching milestones')
        ->assertSeeText('Reset filters');
});

test('the timeline view plots milestones as clickable markers grouped by project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Homs Clinic']);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'name' => 'Foundation poured',
        'planned_start_date' => '2026-04-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('Homs Clinic')
        ->assertSee('href="'.route('milestones.show', $milestone).'"', false);
});

test('the timeline view counts only root milestones next to the project name, like the list view', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Homs Clinic']);
    $parent = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Root gate']);
    Milestone::factory()->childOf($parent)->create(['name' => 'Child gate']);

    $countBadge = fn (int $count) => '<span class="inline-flex min-w-6 items-center justify-center rounded-full bg-secondary-soft px-2 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-dark dark:bg-secondary-medium dark:text-white">'.$count.'</span>';

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('Homs Clinic')
        ->assertSee($countBadge(1), false)
        ->assertDontSee($countBadge(2), false);
});

test('a nested milestone marker is dropped one row below its parent in the timeline view, and connected to it', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create(['name' => 'Root gate']);
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Child gate']);
    $standalone = Milestone::factory()->create(['name' => 'Standalone gate']);

    $html = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->html();

    preg_match('/wire:key="milestone-node-'.$parent->id.'"[\s\S]{0,300}?data-milestone-depth="([^"]*)"/', $html, $parentDepthAttr);
    preg_match('/wire:key="milestone-node-'.$child->id.'"[\s\S]{0,300}?data-milestone-depth="([^"]*)"/', $html, $childDepthAttr);

    $parentDepth = $parentDepthAttr[1] ?? null;
    $childDepth = $childDepthAttr[1] ?? null;

    expect($parentDepth)->toBe('0')
        ->and($childDepth)->toBe('1')
        ->and($html)->toContain('wire:key="milestone-connector-'.$child->id.'"')
        ->and($html)->not->toContain('wire:key="milestone-connector-'.$parent->id.'"')
        ->and($html)->not->toContain('wire:key="milestone-connector-'.$standalone->id.'"');
});

test('the timeline view lets clicking a marker offer to add a related milestone nested under it, and clicking empty timeline space add a root milestone', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('data-test="milestone-timeline-add-related-'.$parent->id.'"', false)
        ->assertSee('wire:click="openCreateModal(null, null, null, '.$parent->id.')"', false)
        ->assertSee('$wire.openCreateModal(pointerInfo($event).iso, null, '.$project->id.')', false)
        ->assertSee('data-test="milestone-timeline-river-'.$project->id.'"', false);
});

test('the timeline view offers a zoom slider that switches the tick resolution between months, weeks and days', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('data-test="milestone-timeline-zoom-'.$project->id.'"', false)
        ->assertSee("tickMode === 'weeks'", false)
        ->assertSee("tickMode === 'days'", false);
});

test('a deletable milestone shows a delete action in the timeline view, and a locked one does not', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create(['name' => 'Root gate']);
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Child gate']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('wire:click="confirmDelete('.$child->id.')"', false)
        ->assertDontSee('wire:click="confirmDelete('.$parent->id.')"', false);
});

test('a deletable milestone shows a delete action in the board view, and a locked one does not', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create(['name' => 'Root gate']);
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Child gate']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'board')
        ->assertSee('wire:click="confirmDelete('.$child->id.')"', false)
        ->assertDontSee('wire:click="confirmDelete('.$parent->id.')"', false);
});

test('the board view renders a card per milestone and honours the status filter', function () {
    $user = User::factory()->create();
    $reached = Milestone::factory()->create(['name' => 'Reached gate', 'status' => MilestoneStatus::Reached]);
    $pending = Milestone::factory()->create(['name' => 'Pending gate', 'status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'board')
        ->assertSee('data-test="milestone-board-card-'.$reached->id.'"', false)
        ->assertSee('data-test="milestone-board-card-'.$pending->id.'"', false)
        ->set('status', MilestoneStatus::Reached->value)
        ->assertSee('data-test="milestone-board-card-'.$reached->id.'"', false)
        ->assertDontSee('data-test="milestone-board-card-'.$pending->id.'"', false);
});

test('the board view wires each card to be dragged and each column to accept a drop', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['name' => 'Planned gate', 'status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'board')
        // The card is a genuine HTML5 drag source carrying its id and current status.
        ->assertSee('draggable="true"', false)
        ->assertSee('{ id: '.$milestone->id.", status: 'planned' }", false)
        // Each column is a drop target that calls quickSetStatus with its own status.
        ->assertSee('$wire.quickSetStatus(dragged.id, \'ongoing\')', false)
        ->assertSee('$wire.quickSetStatus(dragged.id, \'reached\')', false)
        ->assertSee('$wire.quickSetStatus(dragged.id, \'postponed\')', false)
        ->assertSee('$wire.quickSetStatus(dragged.id, \'cancelled\')', false);
});

test('the board view wires each column to open the create modal with that status when its empty space is clicked', function () {
    $user = User::factory()->create();
    Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'board')
        ->assertSee('data-milestone-card', false)
        ->assertSee("\$wire.openCreateModal(null, 'planned')", false)
        ->assertSee("\$wire.openCreateModal(null, 'ongoing')", false)
        ->assertSee("\$wire.openCreateModal(null, 'reached')", false)
        ->assertSee("\$wire.openCreateModal(null, 'postponed')", false)
        ->assertSee("\$wire.openCreateModal(null, 'cancelled')", false);
});

test('opening the create modal with a status prefills it, and an invalid status is ignored', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal', null, MilestoneStatus::Reached->value)
        ->assertSet('showMilestoneModal', true)
        ->assertSet('milestoneStatus', MilestoneStatus::Reached->value)
        ->call('closeMilestoneModal')
        ->call('openCreateModal', null, 'not-a-real-status')
        ->assertSet('milestoneStatus', 'planned');
});

test('a milestone status can be changed from the board view', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('quickSetStatus', $milestone->id, MilestoneStatus::Reached->value)
        ->assertHasNoErrors();

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Reached);
});

test('an invalid quick status change is ignored', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('quickSetStatus', $milestone->id, 'not-a-real-status');

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Planned);
});

test('the calendar view places milestones on their planned date and honours the project filter', function () {
    $user = User::factory()->create();
    $inMonth = Project::factory()->create();
    $otherProject = Project::factory()->create();

    $milestone = Milestone::factory()->create([
        'project_id' => $inMonth->id,
        'name' => 'Foundation poured',
        'planned_start_date' => '2026-04-15',
    ]);
    $excluded = Milestone::factory()->create([
        'project_id' => $otherProject->id,
        'name' => 'Other gate',
        'planned_start_date' => '2026-04-16',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'calendar')
        ->set('month', '2026-04')
        ->assertSee('data-test="milestone-calendar-chip-'.$milestone->id.'"', false)
        ->assertSee('href="'.route('milestones.show', $milestone).'"', false)
        ->set('project', (string) $inMonth->id)
        ->assertSee('Foundation poured')
        ->assertDontSee('Other gate')
        ->assertDontSee('data-test="milestone-calendar-chip-'.$excluded->id.'"', false);
});

test('the calendar view month navigation moves the visible month', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'calendar')
        ->set('month', '2026-04')
        ->call('nextMonth')
        ->assertSet('month', '2026-05')
        ->call('previousMonth')
        ->assertSet('month', '2026-04')
        ->call('goToToday')
        ->assertSet('month', '');
});

test('clicking a day in the calendar view opens the create modal with that date prefilled', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'calendar')
        ->set('month', '2026-04')
        ->call('openCreateModal', '2026-04-15')
        ->assertSet('showMilestoneModal', true)
        ->assertSet('plannedStartDate', '2026-04-15');
});

test('a deletable milestone shows a delete action in the calendar view, and a locked one does not', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create(['name' => 'Root gate', 'planned_start_date' => '2026-04-10']);
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Child gate', 'planned_start_date' => '2026-04-11']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'calendar')
        ->set('month', '2026-04')
        ->assertSee('data-milestone-chip', false)
        ->assertSee('wire:click="confirmDelete('.$child->id.')"', false)
        ->assertDontSee('wire:click="confirmDelete('.$parent->id.')"', false);
});
