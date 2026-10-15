<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\SimilarProjects;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->otherCenterId = insertHrCenter(insertHrCity('Aleppo', 'حلب'), 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
    $this->project = fn (string $name, array $attributes = []) => Project::factory()->create([
        'name' => $name,
        'center_id' => $this->otherCenterId,
        'program_id' => $this->program->id,
        ...$attributes,
    ]);
    $this->openForm = fn () => Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set(['centerId' => (string) $this->centerId, 'programId' => (string) $this->program->id, 'userId' => (string) $this->owner]);
});

test('a similar name in another center is listed, the current center is not', function () {
    $other = ($this->project)('Water Network Rehabilitation');
    ($this->project)('Water Network Local', ['center_id' => $this->centerId]);
    ($this->project)('Mobile Clinic Expansion');

    $names = SimilarProjects::find('Water Network', null, $this->centerId)->pluck('name')->all();

    expect($names)->toBe([$other->name]);
});

test('at most five similar projects are offered, newest first', function () {
    foreach (range(1, 7) as $number) {
        ($this->project)("Cash Transfer {$number}");
    }

    $found = SimilarProjects::find('Cash Transfer', null, $this->centerId);

    expect($found)->toHaveCount(5)
        ->and($found->pluck('name')->first())->toBe('Cash Transfer 7');
});

test('Arabic names and diacritics match', function () {
    ($this->project)('مشروع المياه والصرف الصحي');

    expect(SimilarProjects::find('مشروع المِياه', null, $this->centerId))->toHaveCount(1);
});

test('wildcards in the typed name match literally', function () {
    ($this->project)('Water Network');

    expect(SimilarProjects::find('%%', null, $this->centerId))->toHaveCount(0);
});

test('an archived project and a project of another program narrow the list', function () {
    ($this->project)('Shelter Repair', ['status' => 'archived']);
    ($this->project)('Shelter Repair Two', ['program_id' => Program::factory()->create()->id]);
    $kept = ($this->project)('Shelter Repair Three');

    expect(SimilarProjects::find('Shelter Repair', $this->program->id, $this->centerId)->pluck('id')->all())->toBe([$kept->id]);
});

test('the form lists them and refuses to save until the new project is confirmed', function () {
    ($this->project)('Winter Shelter Idlib');

    $form = ($this->openForm)()->set('name', 'Winter Shelter');

    expect($form->get('similarProjects'))->toHaveCount(1)
        ->and($form->get('similarProjects')[0])->toHaveKeys(['id', 'name', 'center', 'program', 'status']);

    $form->call('saveProject')->assertHasErrors(['confirmNewProject']);

    expect(Project::query()->where('center_id', $this->centerId)->count())->toBe(0);

    $form->set('confirmNewProject', true)->call('saveProject')->assertHasNoErrors();

    expect(Project::query()->where('center_id', $this->centerId)->count())->toBe(1);
});

test('changing the name clears the confirmation', function () {
    ($this->project)('Winter Shelter Idlib');

    ($this->openForm)()->set('name', 'Winter Shelter')->set('confirmNewProject', true)->set('name', 'Winter Shelter Two')->assertSet('confirmNewProject', false);
});

test('the panel shows names, center, program and status and no money', function () {
    ($this->project)('Winter Shelter Idlib');

    ($this->openForm)()->set('name', 'Winter Shelter')
        ->assertSee('Winter Shelter Idlib')
        ->assertSee('Hope Center Aleppo');
});

test('the lookup is one query however many projects match', function () {
    foreach (range(1, 5) as $number) {
        ($this->project)("Cash Transfer {$number}");
    }

    DB::enableQueryLog();
    SimilarProjects::find('Cash Transfer', null, $this->centerId);

    expect(DB::getQueryLog())->toHaveCount(1);
});
