<?php

use App\Actions\Locations\CloseLocation;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Closing a place from its page (contracts.md §3: active → closed, dates
| kept). Y-17: the row is the writer, so the button calls CloseLocation and
| the appointment that locks a rejected place is the row's own.
*/

beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee();
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->manager = userWithRole('Managers');
    $this->actingAs($this->manager);
    $project = Project::factory()->active()->create([
        'user_id' => $this->ownerEmployee,
        'program_id' => Program::factory()->create(['manager_id' => insertHrEmployee()])->id,
    ]);
    $this->row = rowFor($project);
    auth()->logout();

    $this->close = fn ($user) => Livewire::actingAs($user)->test('pages::projects.show', ['project' => $this->row->slug]);
});

test('a projects.update holder and the responsible close an active place, keeping its dates', function (string $who) {
    $dates = [$this->row->start_date->toDateString(), $this->row->end_date->toDateString()];

    ($this->close)($this->{$who})
        ->call('confirmClose')
        ->assertSet('showCloseModal', true)
        ->call('closeProject')
        ->assertHasNoErrors()
        ->assertSet('showCloseModal', false);

    expect($this->row->fresh())->status->toBe(ProjectStatus::Closed)
        ->and([$this->row->fresh()->start_date->toDateString(), $this->row->fresh()->end_date->toDateString()])->toBe($dates);
})->with(['manager', 'owner']);

test('the Close Project button shows for an active place and not for another user', function () {
    ($this->close)($this->manager)->assertSeeHtml('data-test="close-project"');
    ($this->close)(userWithRole('Team Member'))->assertDontSeeHtml('data-test="close-project"');
});

test('another user is refused and the place stays active', function () {
    $stranger = userWithRole('Team Member');

    ($this->close)($stranger)->call('confirmClose')->assertForbidden();
    ($this->close)($stranger)->call('closeProject')->assertForbidden();
    $this->actingAs($stranger);

    expect(fn () => app(CloseLocation::class)->handle($this->row))->toThrow(AuthorizationException::class);
    expect($this->row->fresh()->status)->toBe(ProjectStatus::Active);
});

test('only an active place can be closed', function (ProjectStatus $status) {
    $row = ProposalLocation::factory()->complete()->create(['status' => $status]);

    $this->actingAs($this->manager);

    expect(fn () => app(CloseLocation::class)->handle($row))->toThrow(ValidationException::class, 'Only an active project can be closed.');
    expect($row->fresh()->status)->toBe($status);
})->with([ProjectStatus::Draft, ProjectStatus::Approved, ProjectStatus::Closed, ProjectStatus::Archived]);

test('closing twice writes one change', function () {
    $this->actingAs($this->manager);
    $before = Activity::query()->where('event', 'project.closed')->count();

    app(CloseLocation::class)->handle($this->row);
    expect(fn () => app(CloseLocation::class)->handle($this->row->fresh()))->toThrow(ValidationException::class);

    expect(Activity::query()->where('event', 'project.closed')->count())->toBe($before + 1);
});

test('a place whose responsible rejected it cannot be closed', function () {
    $this->actingAs($this->manager);

    // rowFor() builds the row without recording, so the appointment the
    // responsible rejects is stated outright (Y-17).
    Assignment::query()->create([
        'assignable_type' => 'proposal_location',
        'assignable_id' => $this->row->id,
        'field' => 'responsible_employee_id',
        'employee_id' => $this->ownerEmployee,
        'status' => AssignmentStatus::Rejected,
        'responded_at' => now(),
    ]);

    expect(fn () => app(CloseLocation::class)->handle($this->row->fresh()))->toThrow(AuthorizationException::class);
    expect($this->row->fresh()->status)->toBe(ProjectStatus::Active);
});
