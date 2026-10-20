<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Proposal;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');

    $this->project = Activity::withoutRecording(function (): Project {
        $project = Project::factory()->status(ProjectStatus::Active)->create();
        $project->proposals()->attach(Proposal::factory()->create(['status' => ProposalStatus::Active, 'name' => 'Clean Water Phase One'])->id);

        return $project;
    });
});

test('a manager sees the edit and archive actions on the Story', function () {
    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="edit-project"')
        ->assertSeeHtml('data-test="archive-project"')
        ->assertDontSeeHtml('data-test="edit-project-locked"')
        ->assertDontSeeHtml('data-test="archive-project-locked"');
});

test('a viewer without the permissions sees the actions locked, not usable', function () {
    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="edit-project-locked"')
        ->assertSeeHtml('data-test="archive-project-locked"')
        ->assertDontSeeHtml('wire:click="openEditModal(')
        ->assertDontSeeHtml('wire:click="confirmArchive(');
});

test('archiving from the Story archives the project and offers Restore', function () {
    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->call('confirmArchive', $this->project->id)
        ->assertSet('showArchiveModal', true)
        ->call('archiveProject')
        ->assertSet('showArchiveModal', false)
        ->assertSeeHtml('data-test="restore-project"')
        ->assertDontSeeHtml('data-test="archive-project"');

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('a viewer without the archive permission sees Restore locked on an archived project', function () {
    $this->project->forceFill(['status' => ProjectStatus::Archived])->saveQuietly();

    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="restore-project-locked"')
        ->assertDontSeeHtml('data-test="restore-project"');
});

test('archiving is refused for a viewer without the permission', function () {
    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->call('confirmArchive', $this->project->id)
        ->assertForbidden();

    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('the months strip shows a validated month', function () {
    Activity::withoutRecording(fn () => MonthlyReport::factory()->validated()->create(['project_id' => $this->project->id, 'period' => '2026-08-01']));

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="story-months"')
        ->assertSeeHtml('data-month-state="validated"');
});

test('the side column still shows the funding history entry', function () {
    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="funding-history"')
        ->assertSee('Clean Water Phase One');
});
