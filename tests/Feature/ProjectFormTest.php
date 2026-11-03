<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

/*
 * The "Add center" project form (W-02, D-55): city → program → physical
 * center (of the city) → host center (only while no center) → owner → sectors.
 */
beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->aleppoId = insertHrCity('Aleppo', 'حلب');
    $this->idlebId = insertHrCity('Idleb', 'إدلب');
    $this->homsId = insertHrCity('Homs', 'حمص');
    $this->aleppoCenterId = insertHrCenter($this->aleppoId, 'Head Office Aleppo', 'المكتب الرئيسي حلب');
    $this->aleppoClosedId = insertHrCenter($this->aleppoId, 'Old Aleppo Center', 'مركز حلب القديم', isActive: false);
    $this->homsCenterId = insertHrCenter($this->homsId, 'Hope Center Homs', 'مركز الأمل حمص');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['name' => 'Wells', 'manager_id' => $this->owner]);

    $this->form = fn () => Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal');

    $this->selectOrder = function (string $html): array {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($document);
        $dialog = '//dialog[contains(@class, "modal-shell")][.//*[@data-test="save-project-button"]]';

        return array_map(
            fn (DOMNode $node): string => $node->getAttribute('data-form-select'),
            iterator_to_array($xpath->query($dialog.'//*[@data-form-select]')),
        );
    };

    $this->optionLabels = function (string $html, string $model): array {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($document);

        return array_map(
            fn (DOMNode $node): string => $node->getAttribute('data-option-label'),
            iterator_to_array($xpath->query('//*[@data-form-select="'.$model.'"]//*[@data-option-label]')),
        );
    };
});

test('the create dialog reads «Add center» and the edit dialog keeps «Edit project»', function () {
    ($this->form)()
        ->assertSeeHtml('<h2 id="modal-title-')
        ->assertSeeText('Add center')
        ->assertDontSeeText('Add project')
        ->assertDontSeeText('Create project');

    $project = Project::factory()->create(['program_id' => $this->program->id, 'user_id' => $this->owner, 'center_id' => $this->aleppoCenterId, 'city_id' => $this->aleppoId]);

    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->assertSeeText('Edit project')
        ->assertSeeText('Save changes')
        ->assertSet('cityId', (string) $this->aleppoId);
});

test('the placement fields come in the order city, program, center, host center, owner', function () {
    $html = ($this->form)()->html();

    expect(($this->selectOrder)($html))->toBe(['cityId', 'programId', 'centerId', 'hostCenterId', 'userId']);
});

test('the center select waits for a city, then offers only the active centers of that city', function () {
    $form = ($this->form)();

    expect(($this->optionLabels)($form->html(), 'centerId'))->toBe([]);
    $form->assertSeeText('Choose the city first.');

    $form->set('cityId', (string) $this->aleppoId);

    expect(($this->optionLabels)($form->html(), 'centerId'))->toBe(['Head Office Aleppo']);
});

test('a city with no center disables the center select and says so', function () {
    $html = ($this->form)()->set('cityId', (string) $this->idlebId)->assertSeeText('No centers in this city yet.')->html();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-form-select="centerId"]//button[@data-test="center-select"][@disabled]')->length)->toBe(1)
        ->and(($this->optionLabels)($html, 'centerId'))->toBe([]);
});

test('the host center select is offered while no center is chosen and hidden once one is', function () {
    $form = ($this->form)()->set('cityId', (string) $this->aleppoId);

    expect(($this->optionLabels)($form->html(), 'hostCenterId'))->toBe(['Hope Center Homs']);

    $form->set('centerId', (string) $this->aleppoCenterId)
        ->assertDontSeeHtml('data-form-select="hostCenterId"');

    $form->set('centerId', '')
        ->assertSeeHtml('data-form-select="hostCenterId"');
});

test('choosing another city drops a center that is not in it and a host that is', function () {
    ($this->form)()
        ->set('cityId', (string) $this->aleppoId)
        ->set('centerId', (string) $this->aleppoCenterId)
        ->set('cityId', (string) $this->homsId)
        ->assertSet('centerId', '')
        ->set('hostCenterId', (string) $this->aleppoCenterId)
        ->set('cityId', (string) $this->aleppoId)
        ->assertSet('hostCenterId', '');
});

test('the name is proposed as «Program — Center» with a center and «Program — City» without one', function () {
    $form = ($this->form)()
        ->set('programId', (string) $this->program->id)
        ->set('cityId', (string) $this->idlebId)
        ->assertSet('name', 'Wells — Idleb')
        ->set('cityId', (string) $this->aleppoId)
        ->assertSet('name', 'Wells — Aleppo')
        ->set('centerId', (string) $this->aleppoCenterId)
        ->assertSet('name', 'Wells — Head Office Aleppo');

    $form->set('name', 'Aleppo Wells')
        ->set('cityId', (string) $this->homsId)
        ->assertSet('name', 'Aleppo Wells');
});

test('a project with a physical center is saved through SaveProject with the center\'s city', function () {
    ($this->form)()
        ->set('sectorIds', newProjectSectorIds())
        ->set('cityId', (string) $this->aleppoId)
        ->set('programId', (string) $this->program->id)
        ->set('centerId', (string) $this->aleppoCenterId)
        ->set('userId', (string) $this->owner)
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::query()->sole())
        ->name->toBe('Wells — Head Office Aleppo')
        ->center_id->toBe($this->aleppoCenterId)
        ->host_center_id->toBeNull()
        ->city_id->toBe($this->aleppoId);
});

test('a project in a city without centers is saved with its city and a host center', function () {
    ($this->form)()
        ->set('sectorIds', newProjectSectorIds())
        ->set('cityId', (string) $this->idlebId)
        ->set('programId', (string) $this->program->id)
        ->set('hostCenterId', (string) $this->homsCenterId)
        ->set('userId', (string) $this->owner)
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::query()->sole())
        ->name->toBe('Wells — Idleb')
        ->center_id->toBeNull()
        ->host_center_id->toBe($this->homsCenterId)
        ->city_id->toBe($this->idlebId);
});

test('a name the server must default follows the same rule as the form', function () {
    Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set(['cityId' => (string) $this->idlebId, 'programId' => (string) $this->program->id, 'hostCenterId' => (string) $this->homsCenterId, 'userId' => (string) $this->owner, 'name' => ''])
        ->call('saveProject')
        ->assertHasNoErrors();

    expect(Project::query()->sole()->name)->toBe('Wells — Idleb');
});

test('a city without a center or a host is refused', function () {
    ($this->form)()
        ->set('sectorIds', newProjectSectorIds())
        ->set('cityId', (string) $this->idlebId)
        ->set('programId', (string) $this->program->id)
        ->set('userId', (string) $this->owner)
        ->call('saveProject')
        ->assertHasErrors(['centerId'])
        ->assertSeeText('Choose a physical center or a host center.');

    expect(Project::query()->count())->toBe(0);
});

test('a Team Member can neither open nor submit the form', function () {
    $member = User::factory()->role('Team Member')->create();

    Livewire::actingAs($member)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->assertForbidden();

    Livewire::actingAs($member)
        ->test('pages::projects.index')
        ->set(['cityId' => (string) $this->aleppoId, 'programId' => (string) $this->program->id, 'centerId' => (string) $this->aleppoCenterId, 'userId' => (string) $this->owner])
        ->call('saveProject')
        ->assertForbidden();

    expect(Project::query()->count())->toBe(0);
});
