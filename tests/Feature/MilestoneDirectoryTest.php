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
        ->assertSee('wire:click="openEditModal('.$parent->id.')"', false)
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
    Milestone::factory()->create(['project_id' => $second->id, 'name' => 'Second gate', 'status' => MilestoneStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('project', (string) $first->id)
        ->assertSee('First gate')
        ->assertDontSee('Second gate')
        ->call('clearFilters')
        ->set('status', MilestoneStatus::Pending->value)
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
        'status' => MilestoneStatus::Pending,
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
        'status' => MilestoneStatus::Pending,
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
        ->set('milestoneStatus', MilestoneStatus::Pending->value)
        ->set('plannedDate', '2026-06-30')
        ->call('saveMilestone')
        ->assertHasNoErrors()
        ->assertSet('showMilestoneModal', false);

    $milestone = Milestone::query()->where('name', 'Water quality testing')->sole();

    expect($milestone->parent_milestone_id)->toBe($parent->id)
        ->and($milestone->project_id)->toBe($project->id)
        ->and($milestone->responsible_user_id)->toBe($responsible);
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

test('a milestone can be edited while its project remains fixed', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['name' => 'Initial gate']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openEditModal', $milestone->id)
        ->set('name', '  Revised gate  ')
        ->set('sequence', '8')
        ->set('weightPercentage', '35.50')
        ->call('saveMilestone')
        ->assertHasNoErrors()
        ->assertSet('showMilestoneModal', false);

    $milestone->refresh();

    expect($milestone->name)->toBe('Revised gate')
        ->and($milestone->sequence)->toBe(8)
        ->and($milestone->weight_percentage)->toBe('35.50');
});

test('creating a milestone requires its core delivery fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('name', '   ')
        ->set('plannedDate', '')
        ->set('responsibleUserId', '')
        ->set('milestoneProjectId', '')
        ->call('saveMilestone')
        ->assertHasErrors(['name', 'plannedDate', 'responsibleUserId', 'milestoneProjectId']);
});

test('the parent picker is project local and excludes the edited subtree', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $root = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Root gate']);
    Milestone::factory()->childOf($root)->create(['name' => 'Child gate']);
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Sibling gate']);
    Milestone::factory()->create(['project_id' => $otherProject->id, 'name' => 'Other project gate']);

    $component = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openEditModal', $root->id);

    $names = $component->instance()->parentOptions()->pluck('name')->all();

    expect($names)->toContain('Sibling gate')
        ->not->toContain('Root gate')
        ->not->toContain('Child gate')
        ->not->toContain('Other project gate');
});

test('tampered cross-project and descendant parents produce validation errors', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $root = Milestone::factory()->create(['project_id' => $project->id]);
    $child = Milestone::factory()->childOf($root)->create();
    $other = Milestone::factory()->create(['project_id' => $otherProject->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openEditModal', $root->id)
        ->set('parentMilestoneId', (string) $other->id)
        ->call('saveMilestone')
        ->assertHasErrors(['parentMilestoneId'])
        ->set('parentMilestoneId', (string) $child->id)
        ->call('saveMilestone')
        ->assertHasErrors(['parentMilestoneId']);

    expect($root->refresh()->parent_milestone_id)->toBeNull();
});

test('a milestone project is immutable on edit even when the livewire property is tampered', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openEditModal', $milestone->id)
        ->set('milestoneProjectId', (string) $otherProject->id)
        ->call('saveMilestone')
        ->assertHasErrors(['milestoneProjectId']);

    expect($milestone->refresh()->project_id)->toBe($project->id);
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

test('a leaf milestone with no tasks can be deleted', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->call('deleteMilestone')
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
    $task = Task::factory()->create(['milestone_id' => $milestone->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->call('deleteMilestone');

    expect(DB::table('milestones')->find($milestone->id))->not->toBeNull()
        ->and(DB::table('tasks')->find($task->id)->milestone_id)->toBe($milestone->id);
});

test('the stat cards summarise reached, overdue, missed and upcoming milestones', function () {
    $user = User::factory()->create();
    $today = now()->startOfDay();

    Milestone::factory()->create(['status' => MilestoneStatus::Reached, 'planned_date' => $today->copy()->subDays(10)]);
    Milestone::factory()->create(['status' => MilestoneStatus::Missed, 'planned_date' => $today->copy()->subDays(5)]);
    Milestone::factory()->create(['status' => MilestoneStatus::Pending, 'planned_date' => $today->copy()->subDay()]);
    Milestone::factory()->create(['status' => MilestoneStatus::Pending, 'planned_date' => $today->copy()->addDays(3)]);
    Milestone::factory()->create(['status' => MilestoneStatus::Pending, 'planned_date' => $today->copy()->addDays(30)]);

    $stats = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->instance()
        ->milestoneStats();

    expect($stats)->toBe([
        'total' => 5,
        'reached' => 1,
        'overdue' => 1,
        'missed' => 1,
        'due_soon' => 1,
    ]);
});

test('the milestone directory distinguishes empty and filtered states', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->assertSeeText('No milestones yet')
        ->set('status', MilestoneStatus::Missed->value)
        ->assertSeeText('No matching milestones')
        ->assertSeeText('Reset filters');
});

test('the timeline view plots milestones as clickable markers grouped by project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Homs Clinic']);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'name' => 'Foundation poured',
        'planned_date' => '2026-04-01',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('Homs Clinic')
        ->assertSee('wire:click="openEditModal('.$milestone->id.')"', false);
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

test('a nested milestone card is shifted below the root cards in the timeline view', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create(['name' => 'Root gate']);
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Child gate']);

    $html = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->html();

    preg_match('/<div\s+class="([^"]*)"\s+style="[^"]*"\s+wire:key="milestone-card-'.$parent->id.'"/s', $html, $parentDiv);
    preg_match('/<div\s+class="([^"]*)"\s+style="[^"]*"\s+wire:key="milestone-card-'.$child->id.'"/s', $html, $childDiv);

    expect($parentDiv[1] ?? null)->toContain('top-0')
        ->and($childDiv[1] ?? null)->toContain('top-6');
});

test('the timeline view links a nested milestone back to its parent', function () {
    $user = User::factory()->create();
    $parent = Milestone::factory()->create(['name' => 'Root gate']);
    $child = Milestone::factory()->childOf($parent)->create(['name' => 'Child gate']);
    $standalone = Milestone::factory()->create(['name' => 'Standalone gate']);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'timeline')
        ->assertSee('wire:key="milestone-nesting-link-'.$child->id.'"', false)
        ->assertDontSee('wire:key="milestone-nesting-link-'.$parent->id.'"', false)
        ->assertDontSee('wire:key="milestone-nesting-link-'.$standalone->id.'"', false);
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
    $pending = Milestone::factory()->create(['name' => 'Pending gate', 'status' => MilestoneStatus::Pending]);

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
    $milestone = Milestone::factory()->create(['name' => 'Pending gate', 'status' => MilestoneStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('view', 'board')
        // The card is a genuine HTML5 drag source carrying its id and current status.
        ->assertSee('draggable="true"', false)
        ->assertSee('{ id: '.$milestone->id.", status: 'pending' }", false)
        // Each column is a drop target that calls quickSetStatus with its own status.
        ->assertSee('$wire.quickSetStatus(dragged.id, \'reached\')', false)
        ->assertSee('$wire.quickSetStatus(dragged.id, \'missed\')', false)
        ->assertSee('$wire.quickSetStatus(dragged.id, \'cancelled\')', false);
});

test('a milestone status can be changed from the board view', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('quickSetStatus', $milestone->id, MilestoneStatus::Reached->value)
        ->assertHasNoErrors();

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Reached);
});

test('an invalid quick status change is ignored', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('quickSetStatus', $milestone->id, 'not-a-real-status');

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Pending);
});
