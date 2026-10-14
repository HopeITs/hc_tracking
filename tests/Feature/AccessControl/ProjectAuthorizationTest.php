<?php

use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->project = Project::factory()->create(['user_id' => $this->ownerEmployee]);
    $this->show = fn (User $user) => Livewire::actingAs($user)->test('pages::projects.show', ['project' => $this->project]);
    $this->index = fn (User $user) => Livewire::actingAs($user)->test('pages::projects.index');
});

test('a Team Member cannot edit someone else\'s project or file on it', function () {
    ($this->index)(userWithRole('Team Member'))->call('openEditModal', $this->project->id)->assertForbidden();
    ($this->show)(userWithRole('Team Member'))->call('openCreateFileModal')->assertForbidden();
});

test('the owner edits their project and uploads a file, but cannot archive it', function () {
    ($this->index)($this->owner)->call('openEditModal', $this->project->id)->assertOk();

    ($this->show)($this->owner)
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('plan.pdf', 50))
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->set('fileDisplayName', 'Work plan')
        ->call('saveFile')
        ->assertHasNoErrors();

    ($this->index)($this->owner)->call('confirmArchive', $this->project->id)->assertForbidden();
});

test('an owner\'s edit keeps the project\'s program and owner', function () {
    $otherProgram = Program::factory()->create();

    ($this->index)($this->owner)
        ->call('openEditModal', $this->project->id)
        ->set('name', 'Renamed by owner')
        ->set('programId', (string) $otherProgram->id)
        ->set('userId', (string) insertHrEmployee())
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($this->project->fresh())
        ->name->toBe('Renamed by owner')
        ->program_id->toBe($this->project->program_id)
        ->user_id->toBe($this->ownerEmployee);
});

test('an owner cannot archive a project', function () {
    ($this->index)($this->owner)
        ->call('confirmArchive', $this->project->id)
        ->assertForbidden();

    expect($this->project->fresh()->status)->not->toBe(ProjectStatus::Archived);
});

test('a program manager edits any project in their program', function () {
    $managerEmployee = insertHrEmployee();
    $programManager = userWithRole('Team Member', $managerEmployee);
    Program::query()->whereKey($this->project->program_id)->update(['manager_id' => $managerEmployee]);

    ($this->index)($programManager)->call('openEditModal', $this->project->id)->assertOk();
});

test('managers archive and restore projects', function () {
    $manager = userWithRole('Managers');

    ($this->index)($manager)->call('confirmArchive', $this->project->id)->call('archiveProject');
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Archived);

    ($this->index)($manager)->call('restoreProject', $this->project->id);
    expect($this->project->fresh()->status)->not->toBe(ProjectStatus::Archived);
});

test('schedule edits need edit rights on the project', function () {
    $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);

    ($this->show)(userWithRole('Team Member'))->call('ganttRename', 'milestone-'.$milestone->id, 'Renamed')->assertForbidden();
    ($this->show)($this->owner)->call('ganttRename', 'milestone-'.$milestone->id, 'Renamed')->assertOk();

    expect($milestone->fresh()->name)->toBe('Renamed');
});
