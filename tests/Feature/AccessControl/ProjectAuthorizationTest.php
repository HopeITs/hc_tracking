<?php

use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->project = projectWithRow(['user_id' => $this->ownerEmployee]);
    $this->show = fn (User $user) => Livewire::actingAs($user)->test('pages::projects.show', ['project' => rowFor($this->project)->slug]);
    $this->index = fn (User $user) => Livewire::actingAs($user)->test('pages::projects.index');
    // Y-16 (D-93): the directory's actions name the location row.
    $this->rowId = rowFor($this->project)->id;
});

test('a Team Member cannot edit someone else\'s project or file on it', function () {
    ($this->index)(userWithRole('Team Member'))->call('openEditModal', $this->rowId)->assertForbidden();
    ($this->show)(userWithRole('Team Member'))->call('openCreateFileModal')->assertForbidden();
});

test('the owner edits their project and uploads a file, but cannot archive it', function () {
    ($this->index)($this->owner)->call('openEditModal', $this->rowId)->assertOk();

    ($this->show)($this->owner)
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('plan.pdf', 50))
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->set('fileDisplayName', 'Work plan')
        ->call('saveFile')
        ->assertHasNoErrors();

    ($this->index)($this->owner)->call('confirmArchive', $this->rowId)->assertForbidden();
});

test('an owner\'s edit offers no program or owner field, so it cannot move the place', function () {
    // Y-17: the place, its program and its responsible come from the proposal
    // (D-93), so the delivery form has no field for any of them — the owner
    // edits what delivery owns and nothing else.
    $page = ($this->index)($this->owner)
        ->call('openEditModal', $this->rowId)
        ->assertDontSeeHtml('wire:model="programId"')
        ->assertDontSeeHtml('wire:model="userId"')
        ->assertDontSeeHtml('wire:model="name"')
        ->set('description', 'Rewritten by the owner')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(property_exists($page->instance(), 'name'))->toBeFalse()
        ->and(property_exists($page->instance(), 'programId'))->toBeFalse()
        ->and(property_exists($page->instance(), 'userId'))->toBeFalse();

    expect(rowFor($this->project)->fresh())
        ->description->toBe('Rewritten by the owner')
        ->program_id->toBe($this->project->program_id)
        ->responsible_employee_id->toBe($this->ownerEmployee);
});

test('an owner cannot archive a project', function () {
    ($this->index)($this->owner)
        ->call('confirmArchive', $this->rowId)
        ->assertForbidden();

    expect(rowFor($this->project)->fresh()->status)->not->toBe(ProjectStatus::Archived);
});

test('a program manager edits any project in their program', function () {
    $managerEmployee = insertHrEmployee();
    $programManager = userWithRole('Team Member', $managerEmployee);
    Program::query()->whereKey($this->project->program_id)->update(['manager_id' => $managerEmployee]);

    ($this->index)($programManager)->call('openEditModal', $this->rowId)->assertOk();
});

test('managers archive and restore places', function () {
    $manager = userWithRole('Managers');
    // Y-17: archiving writes the location row, not the project.
    $row = rowFor($this->project);

    ($this->index)($manager)->call('confirmArchive', $this->rowId)->call('archiveProject');
    expect($row->fresh()->status)->toBe(ProjectStatus::Archived);

    ($this->index)($manager)->call('restoreProject', $this->rowId);
    expect($row->fresh()->status)->not->toBe(ProjectStatus::Archived);
});
