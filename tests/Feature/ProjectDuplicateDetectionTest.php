<?php

use App\Actions\Proposals\LinkProposalProject;
use App\Enums\ProjectStatus;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
    $this->existing = fn (ProjectStatus $status, array $attributes = []) => Project::factory()->create([
        'name' => 'Idlib Micro',
        'status' => $status,
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        ...$attributes,
    ]);
    $this->form = fn () => Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set(['centerId' => (string) $this->centerId, 'programId' => (string) $this->program->id, 'userId' => (string) $this->owner]);
});

test('a second open project is refused and the form offers Add a proposal to it', function (ProjectStatus $status) {
    $existing = ($this->existing)($status);

    $form = ($this->form)()->set('name', 'Another one')->call('saveProject');

    $form->assertHasErrors(['centerId'])
        ->assertSee('Add a proposal to it')
        ->assertSeeHtml('for_project='.$existing->id);

    expect(Project::query()->count())->toBe(1)
        ->and($form->get('existingPairProject'))->toMatchArray(['id' => $existing->id, 'state' => 'open']);
})->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Active]);

test('another program in the same center is accepted', function () {
    ($this->existing)(ProjectStatus::Active);
    $otherProgram = Program::factory()->create(['manager_id' => $this->owner]);

    ($this->form)()->set('programId', (string) $otherProgram->id)->set('name', 'Different program')
        ->assertSet('existingPairProject', null)
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::query()->count())->toBe(2);
});

test('a closed project of the pair is offered back instead of a new one', function () {
    $closed = ($this->existing)(ProjectStatus::Closed, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    $form = ($this->form)();

    expect($form->get('existingPairProject'))->toMatchArray(['id' => $closed->id, 'state' => 'closed', 'canRestore' => false]);
    $form->assertSee('Add a proposal to it');
});

test('linking a proposal to a closed project returns it to a dateless draft', function () {
    $closed = ($this->existing)(ProjectStatus::Closed, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    test()->actingAs($this->user);
    app(LinkProposalProject::class)->handle(Proposal::factory()->create(), $closed->id);

    expect($closed->fresh())->status->toBe(ProjectStatus::Draft)->start_date->toBeNull()->end_date->toBeNull();
});

test('an archived project is offered for restore first', function () {
    $archived = ($this->existing)(ProjectStatus::Archived, ['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);

    $form = ($this->form)();

    expect($form->get('existingPairProject'))->toMatchArray(['id' => $archived->id, 'state' => 'archived', 'canRestore' => true]);
    $form->assertSee('Restore it first')->assertDontSee('Add a proposal to it');

    $form->call('restoreExistingProject');

    expect($archived->fresh()->status)->toBe(ProjectStatus::Closed)
        ->and($form->get('existingPairProject'))->toMatchArray(['state' => 'closed']);
});

test('a user who cannot create projects cannot open the form', function () {
    $viewer = User::factory()->role('Team Member')->create();

    Livewire::actingAs($viewer)->test('pages::projects.index')->call('openCreateModal')->assertForbidden();
});

test('there is no project chain column', function () {
    expect(Schema::hasColumn('projects', 'previous_project_id'))->toBeFalse();
});
