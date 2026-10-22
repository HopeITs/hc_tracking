<?php

use App\Models\Project;
use App\Models\Risk;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the risk register', function () {
    $this->get(route('risks.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the risk register', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)->get(route('risks.index'))->assertOk()->assertSeeText('Risks');
});

test('risks can be filtered by search project and whether they materialised', function () {
    $user = User::factory()->role('General Manager')->create();
    $first = Project::factory()->create(['name' => 'Aleppo Wells']);
    $second = Project::factory()->create(['name' => 'Homs Shelter']);

    Risk::factory()->create([
        'project_id' => $first->id,
        'name' => 'Access restrictions',
        'impact' => 'Deliveries pause at checkpoints.',
        'happened' => true,
    ]);
    Risk::factory()->create([
        'project_id' => $second->id,
        'name' => 'Currency collapse',
        'impact' => 'Purchasing power falls.',
        'happened' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertSee('Access restrictions')->assertSee('Currency collapse')
        ->set('search', 'checkpoint')->assertSee('Access restrictions')->assertDontSee('Currency collapse')
        ->call('clearFilters')->set('search', 'Homs Shelter')->assertDontSee('Access restrictions')->assertSee('Currency collapse')
        ->call('clearFilters')->set('project', (string) $second->id)->assertDontSee('Access restrictions')->assertSee('Currency collapse')
        ->call('clearFilters')->call('toggleMaterialisedOnly')->assertSee('Access restrictions')->assertDontSee('Currency collapse')
        ->call('clearFilters')
        ->assertSet('search', '')->assertSet('project', '')->assertSet('materialisedOnly', false)
        ->assertSee('Access restrictions')->assertSee('Currency collapse');
});

test('the materialised-only URL filter selects materialised risks', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->create(['name' => 'Materialised risk', 'happened' => true]);
    Risk::factory()->create(['name' => 'Open risk', 'happened' => false]);

    $this->actingAs($user)
        ->get(route('risks.index', ['materialisedOnly' => true]))
        ->assertOk()
        ->assertSeeText('Materialised risk')
        ->assertDontSeeText('Open risk');
});

test('a risk can be logged against a project', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', '  Fuel shortage  ')
        ->set('impact', '  Generators cannot run, pumping stops.  ')
        ->set('solution', '  Pre-position a 30 day fuel reserve.  ')
        ->set('happened', false)
        ->set('riskProjectId', (string) $project->id)
        ->call('saveRisk')
        ->assertHasNoErrors()
        ->assertSet('showRiskModal', false);

    $this->assertDatabaseHas('risks', [
        'name' => 'Fuel shortage',
        'impact' => 'Generators cannot run, pumping stops.',
        'solution' => 'Pre-position a 30 day fuel reserve.',
        'happened' => false,
        'project_id' => $project->id,
    ]);
});

test('blank mitigation is normalized to null', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', 'Fuel shortage')
        ->set('impact', 'Pumping stops.')
        ->set('solution', '   ')
        ->set('riskProjectId', (string) $project->id)
        ->call('saveRisk')
        ->assertHasNoErrors();

    expect(Risk::query()->where('name', 'Fuel shortage')->sole()->solution)->toBeNull();
});

test('logging a risk requires a name an impact and an existing project', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', '   ')
        ->set('impact', '   ')
        ->set('riskProjectId', '999999')
        ->call('saveRisk')
        ->assertHasErrors(['name', 'impact', 'riskProjectId']);

    expect(Risk::query()->count())->toBe(0);
});

test('a risk is rated for likelihood and severity, and the rating can be cleared again', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->rated(2, 3)->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openEditModal', $risk->id)
        ->assertSet('likelihood', '2')->assertSet('severity', '3')
        ->set('likelihood', '3')
        ->set('severity', '')
        ->call('saveRisk')
        ->assertHasNoErrors();

    $risk->refresh();

    expect($risk->likelihood)->toBe(3)
        ->and($risk->severity)->toBeNull()
        ->and($risk->heatScore())->toBeNull();
});

test('a rating outside 1 to 3 is refused', function () {
    $risk = Risk::factory()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::risks.index')
        ->call('openEditModal', $risk->id)
        ->set('likelihood', '4')
        ->set('severity', '0')
        ->call('saveRisk')
        ->assertHasErrors(['likelihood', 'severity']);

    expect($risk->fresh()->likelihood)->toBeNull();
});

test('a risk can be edited and marked as materialised', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->create(['name' => 'Old risk', 'solution' => null, 'happened' => false]);
    $newProject = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openEditModal', $risk->id)
        ->assertSet('name', 'Old risk')->assertSet('solution', '')->assertSet('happened', false)
        ->set('name', '  Updated risk  ')
        ->set('impact', '  Updated impact.  ')
        ->set('solution', '  Updated mitigation.  ')
        ->set('happened', true)
        ->set('riskProjectId', (string) $newProject->id)
        ->call('saveRisk')
        ->assertHasNoErrors();

    $risk->refresh();

    expect($risk->name)->toBe('Updated risk')
        ->and($risk->impact)->toBe('Updated impact.')
        ->and($risk->solution)->toBe('Updated mitigation.')
        ->and($risk->happened)->toBeTrue()
        ->and($risk->project_id)->toBe($newProject->id);
});

test('a risk can be deleted after confirmation', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('confirmDelete', $risk->id)
        ->assertSet('deletingRiskId', $risk->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteRisk')
        ->assertHasNoErrors()
        ->assertSet('deletingRiskId', null)
        ->assertSet('showDeleteModal', false);

    $this->assertDatabaseMissing('risks', ['id' => $risk->id]);
});

test('cancelling a delete closes the modal without deleting the risk', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('confirmDelete', $risk->id)
        ->assertSet('showDeleteModal', true)
        ->call('cancelDelete')
        ->assertSet('deletingRiskId', null)
        ->assertSet('showDeleteModal', false);

    $this->assertDatabaseHas('risks', ['id' => $risk->id]);
});

test('delete is a no-op until a risk is confirmed', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->create();

    Livewire::actingAs($user)->test('pages::risks.index')->call('deleteRisk')->assertHasNoErrors();

    $this->assertDatabaseHas('risks', ['id' => $risk->id]);
});

test('a risk can be marked materialised directly from its card', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->create(['happened' => false]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('setHappened', $risk->id, true)
        ->assertHasNoErrors();

    expect($risk->refresh()->happened)->toBeTrue();
});

test('a risk can be moved back to potential directly from its card', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->create(['happened' => true]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('setHappened', $risk->id, false)
        ->assertHasNoErrors();

    expect($risk->refresh()->happened)->toBeFalse();
});

test('risk content is escaped in the register', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['name' => '<script>project()</script>']);
    Risk::factory()->create([
        'project_id' => $project->id,
        'name' => '<script>name()</script>',
        'impact' => '<img src=x onerror=impact()>',
        'solution' => '<svg onload=solution()>',
        'happened' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertSee('<script>name()</script>')
        ->assertSee('<img src=x onerror=impact()>')
        ->assertSee('<svg onload=solution()>')
        ->assertSee('<script>project()</script>')
        ->assertDontSeeHtml('<script>name()</script>')
        ->assertDontSeeHtml('<img src=x onerror=impact()>')
        ->assertDontSeeHtml('<svg onload=solution()>')
        ->assertDontSeeHtml('<script>project()</script>');
});

test('the risk register renders with no risks', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('pages::risks.index')->assertSee('No risks logged');
});

test('the materialised count stays stable regardless of the materialised-only toggle', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->count(2)->create(['happened' => true]);
    Risk::factory()->create(['happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    expect($component->get('materialisedCount'))->toBe(2);

    $component->call('toggleMaterialisedOnly');

    expect($component->get('materialisedCount'))->toBe(2);
});

test('toggling materialised-only filters the register to materialised risks', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->create(['name' => 'Materialised risk', 'happened' => true]);
    Risk::factory()->create(['name' => 'Open risk', 'happened' => false]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertSee('Materialised risk')->assertSee('Open risk')
        ->call('toggleMaterialisedOnly')
        ->assertSet('materialisedOnly', true)
        ->assertSee('Materialised risk')->assertDontSee('Open risk')
        ->call('toggleMaterialisedOnly')
        ->assertSet('materialisedOnly', false)
        ->assertSee('Open risk');
});

test('the unmitigated toggle filters to challenges with no mitigation recorded', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->create(['name' => 'Has mitigation', 'solution' => 'A plan.', 'happened' => true]);
    Risk::factory()->create(['name' => 'No mitigation', 'solution' => null, 'happened' => true]);
    Risk::factory()->create(['name' => 'Blank mitigation', 'solution' => '', 'happened' => true]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    expect($component->get('unmitigatedCount'))->toBe(2);

    $component
        ->assertSee('Has mitigation')->assertSee('No mitigation')->assertSee('Blank mitigation')
        ->call('toggleUnmitigated')
        ->assertSet('unmitigated', true)
        ->assertDontSee('Has mitigation')->assertSee('No mitigation')->assertSee('Blank mitigation')
        ->call('toggleUnmitigated')
        ->assertSet('unmitigated', false)
        ->assertSee('Has mitigation');
});

test('a potential risk that has not happened never counts toward challenges without a solution', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->create(['name' => 'Not yet occurred', 'solution' => null, 'happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    expect($component->get('unmitigatedCount'))->toBe(0);

    $component->call('toggleUnmitigated')->assertDontSee('Not yet occurred');
});

test('clearing filters also clears the unmitigated toggle', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('toggleUnmitigated')
        ->assertSet('unmitigated', true)
        ->call('clearFilters')
        ->assertSet('unmitigated', false);
});

test('the project chart splits challenges from potential risks per project', function () {
    $user = User::factory()->role('General Manager')->create();
    $projectA = Project::factory()->create(['name' => 'Project A']);
    $projectB = Project::factory()->create(['name' => 'Project B']);

    Risk::factory()->count(2)->create(['project_id' => $projectA->id, 'happened' => true]);
    Risk::factory()->create(['project_id' => $projectB->id, 'happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    $chart = $component->get('projectRiskChart');

    $projectAIndex = array_search('Project A', $chart['categories'], true);
    $projectBIndex = array_search('Project B', $chart['categories'], true);

    expect($chart['series'][0]['data'][$projectAIndex])->toBe(2)
        ->and($chart['series'][1]['data'][$projectAIndex])->toBe(0)
        ->and($chart['series'][0]['data'][$projectBIndex])->toBe(0)
        ->and($chart['series'][1]['data'][$projectBIndex])->toBe(1);
});

test('the risk landscape totals match the currently filtered risks', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->count(2)->create(['happened' => true]);
    Risk::factory()->create(['happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    expect($component->get('riskLandscape'))->toBe(['challenges' => 2, 'potential' => 1]);

    $component->call('toggleMaterialisedOnly');

    expect($component->get('riskLandscape'))->toBe(['challenges' => 2, 'potential' => 0]);
});

test('the project chart respects every active filter, including the project filter itself', function () {
    $user = User::factory()->role('General Manager')->create();
    $projectA = Project::factory()->create(['name' => 'Project A']);
    $projectB = Project::factory()->create(['name' => 'Project B']);

    Risk::factory()->count(2)->create(['project_id' => $projectA->id, 'solution' => 'A plan.', 'happened' => true]);
    Risk::factory()->create(['project_id' => $projectB->id, 'solution' => null, 'happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    $component->call('toggleMaterialisedOnly');
    $materialisedChart = $component->get('projectRiskChart');
    expect(array_search('Project B', $materialisedChart['categories'], true))->toBeFalse();

    $component->call('clearFilters')->call('toggleUnmitigated');
    $unmitigatedChart = $component->get('projectRiskChart');
    expect(array_search('Project A', $unmitigatedChart['categories'], true))->toBeFalse();

    $component->call('clearFilters')->set('project', (string) $projectA->id);
    $projectFilteredChart = $component->get('projectRiskChart');
    expect($projectFilteredChart['categories'])->toBe(['Project A']);
});

test('the state picker in the risk form saves the string values it sends as the boolean state', function (string $sent, bool $stored) {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', 'Fuel shortage')
        ->set('impact', 'Generators cannot run.')
        ->set('riskProjectId', (string) $project->id)
        ->set('happened', $sent)
        ->call('saveRisk')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('risks', ['name' => 'Fuel shortage', 'happened' => $stored]);
})->with([
    'materialised' => ['1', true],
    'potential' => ['0', false],
]);

test('the risk form is rendered only while its modal is open', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertDontSeeHtml('wire:model="impact"')
        ->call('openCreateModal')
        ->assertSeeHtml('wire:model="impact"');
});
