<?php

use App\Actions\Projects\CloseProject;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->manager = userWithRole('Managers');
    $this->actingAs($this->manager);
    $this->project = Project::factory()->active()->create([
        'user_id' => $this->ownerEmployee,
        'program_id' => Program::factory()->create(['manager_id' => insertHrEmployee()])->id,
    ]);
    auth()->logout();

    $this->close = fn ($user) => Livewire::actingAs($user)->test('pages::projects.show', ['project' => $this->project]);
});

test('a projects.update holder and the owner close an active project, keeping its dates', function (string $who) {
    $dates = [$this->project->start_date->toDateString(), $this->project->end_date->toDateString()];

    ($this->close)($this->{$who})
        ->call('confirmClose')
        ->assertSet('showCloseModal', true)
        ->call('closeProject')
        ->assertHasNoErrors()
        ->assertSet('showCloseModal', false);

    expect($this->project->fresh())->status->toBe(ProjectStatus::Closed)
        ->and([$this->project->fresh()->start_date->toDateString(), $this->project->fresh()->end_date->toDateString()])->toBe($dates);
})->with(['manager', 'owner']);

test('the Close Project button shows for an active project and not for another user', function () {
    ($this->close)($this->manager)->assertSeeHtml('data-test="close-project"');
    ($this->close)(userWithRole('Team Member'))->assertDontSeeHtml('data-test="close-project"');
});

test('another user is refused and the project stays active', function () {
    $stranger = userWithRole('Team Member');

    ($this->close)($stranger)->call('confirmClose')->assertForbidden();
    ($this->close)($stranger)->call('closeProject')->assertForbidden();
    $this->actingAs($stranger);

    expect(fn () => app(CloseProject::class)->handle($this->project))->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('only an active project can be closed', function (ProjectStatus $status) {
    $project = Project::factory()->status($status)->create();

    $this->actingAs($this->manager);

    expect(fn () => app(CloseProject::class)->handle($project))->toThrow(ValidationException::class, 'Only an active project can be closed.');
    expect($project->fresh()->status)->toBe($status);
})->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Closed, ProjectStatus::Archived]);

test('closing twice writes one change', function () {
    $this->actingAs($this->manager);
    $before = Activity::query()->where('event', 'project.status_changed')->count();

    app(CloseProject::class)->handle($this->project);
    expect(fn () => app(CloseProject::class)->handle($this->project->fresh()))->toThrow(ValidationException::class);

    expect(Activity::query()->where('event', 'project.status_changed')->count())->toBe($before + 1);
});

test('a project whose owner rejected it cannot be closed', function () {
    $this->actingAs($this->manager);
    $this->project->update(['user_id' => $this->ownerEmployee]);
    $this->project->currentAssignment('user_id')->reject($this->owner, 'Too busy');

    expect(fn () => app(CloseProject::class)->handle($this->project->fresh()))->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->status)->toBe(ProjectStatus::Active);
});
