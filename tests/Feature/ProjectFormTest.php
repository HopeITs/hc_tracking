<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->idlibId = insertHrCity('Idlib', 'إدلب');
    $this->hamaId = insertHrCity('Hama', 'حماة');
    $this->idlibCenterId = insertHrCenter($this->idlibId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->closedCenterId = insertHrCenter($this->idlibId, 'Old Idlib Center', 'مركز إدلب القديم', isActive: false);
    $this->aleppoId = insertHrCity('Aleppo', 'حلب');
    $this->aleppoCenterId = insertHrCenter($this->aleppoId, 'Hope Center Aleppo', 'مركز الأمل حلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
    $this->open = fn (): Testable => Livewire::actingAs($this->user)->test('pages::projects.index')->call('openCreateModal');
});

/**
 * The `data-form-select` models inside the project dialog, in reading order.
 *
 * @return list<string>
 */
function projectFormSelects(Testable $form): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$form->html());
    $nodes = (new DOMXPath($document))->query('//*[@data-form-select]');

    return array_values(array_map(fn (DOMElement $node): string => $node->getAttribute('data-form-select'), iterator_to_array($nodes)));
}

test('the create dialog is titled «Add center» and an edit keeps «Edit project»', function () {
    ($this->open)()->assertSee(__('projects.add_center'))->assertDontSee(__('projects.edit_project'));

    $project = Project::factory()->create(['center_id' => $this->idlibCenterId, 'city_id' => $this->idlibId, 'program_id' => $this->program->id]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', rowFor($project)->id)
        ->assertSee(__('projects.edit_project'));
});

test('the fields read city, program, center, host center, owner, then sectors', function () {
    $form = ($this->open)();

    expect(projectFormSelects($form))->toBe(['cityId', 'programId', 'centerId', 'hostCenterId', 'userId']);

    $html = $form->html();

    expect(strpos($html, 'data-form-select="userId"'))->toBeLessThan(strpos($html, 'data-test="sector-picker"'));
});

test('the host center select shows only while no physical center is chosen', function () {
    $form = ($this->open)()->set('cityId', (string) $this->idlibId)->set('centerId', (string) $this->idlibCenterId);

    expect(projectFormSelects($form))->toBe(['cityId', 'programId', 'centerId', 'userId']);
});

test('the center options are the active centers of the chosen city', function () {
    $form = ($this->open)();

    expect($form->instance()->centerOptions->modelKeys())->toBe([]);

    $form->set('cityId', (string) $this->idlibId);

    expect($form->instance()->centerOptions->modelKeys())->toBe([$this->idlibCenterId]);
});

test('a city without centers disables the center select and says why', function () {
    ($this->open)()
        ->set('cityId', (string) $this->hamaId)
        ->assertSee(__('projects.no_centers_in_city'))
        ->assertSeeHtml('data-center-select-disabled');
});

test('before a city is chosen the center select asks for the city first', function () {
    ($this->open)()->assertSee(__('projects.choose_the_city_first'))->assertSeeHtml('data-center-select-disabled');
});

test('the host center options are the active centers outside the chosen city', function () {
    $form = ($this->open)()->set('cityId', (string) $this->idlibId);

    expect($form->instance()->hostCenterOptions->modelKeys())->toBe([$this->aleppoCenterId]);
});

test('choosing another city drops a center or host that does not belong to it', function () {
    ($this->open)()
        ->set('cityId', (string) $this->idlibId)
        ->set('centerId', (string) $this->idlibCenterId)
        ->set('cityId', (string) $this->hamaId)
        ->assertSet('centerId', '')
        ->set('hostCenterId', (string) $this->aleppoCenterId)
        ->set('cityId', (string) $this->aleppoId)
        ->assertSet('hostCenterId', '');
});

test('without a physical center the host center is required', function () {
    ($this->open)()
        ->set('sectorIds', newProjectSectorIds())
        ->set(['name' => 'Hama Water', 'cityId' => (string) $this->hamaId, 'programId' => (string) $this->program->id, 'userId' => (string) $this->owner])
        ->call('saveProject')
        ->assertHasErrors(['centerId']);

    expect(Project::query()->count())->toBe(0);
});

test('a project in a city with a center saves that center and the center\'s city', function () {
    ($this->open)()
        ->set('sectorIds', newProjectSectorIds())
        ->set(['name' => 'Idlib Water', 'cityId' => (string) $this->idlibId, 'centerId' => (string) $this->idlibCenterId, 'programId' => (string) $this->program->id, 'userId' => (string) $this->owner])
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::query()->sole())
        ->center_id->toBe($this->idlibCenterId)
        ->host_center_id->toBeNull()
        ->city_id->toBe($this->idlibId);
});

test('a project in a city with no center is hosted by another center and keeps its own city', function () {
    ($this->open)()
        ->set('sectorIds', newProjectSectorIds())
        ->set(['name' => 'Hama Water', 'cityId' => (string) $this->hamaId, 'hostCenterId' => (string) $this->aleppoCenterId, 'programId' => (string) $this->program->id, 'userId' => (string) $this->owner])
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::query()->sole())
        ->center_id->toBeNull()
        ->host_center_id->toBe($this->aleppoCenterId)
        ->city_id->toBe($this->hamaId);
});

test('the name default is worked out in the browser and the server never writes one', function () {
    $form = ($this->open)()->assertSeeHtml('data-name-default');

    $form->assertSee($this->program->name)->assertSee('Hope Center Idlib')->assertSee('Idlib');

    $form->set(['cityId' => (string) $this->idlibId, 'programId' => (string) $this->program->id, 'centerId' => (string) $this->idlibCenterId])
        ->assertSet('name', '');

    $form->set('name', 'My own name')
        ->set('programId', (string) Program::factory()->create(['manager_id' => $this->owner])->id)
        ->assertSet('name', 'My own name');
});

test('a Team Member cannot open the create dialog', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->assertForbidden();
});
