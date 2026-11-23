<?php

use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\Risk;
use App\Models\User;
use Livewire\Livewire;

/**
 * Y-13 (D-93): a register risk belongs to a location row — a place, read as
 * «city · program» on this page. The user-facing word stays "Project"
 * (wave4/AGENT.md §8.5), so the filter, the form field and the chart keep
 * their labels; only what they point at changed.
 */

/**
 * A location row carrying a project, named so its place label is predictable.
 */
function registerRow(string $city = 'Aleppo', string $program = 'Water'): ProposalLocation
{
    $project = Project::factory()->create([
        'city_id' => insertHrCity($city, $city),
        'program_id' => Program::factory()->create(['name' => $program])->id,
    ]);

    return ProposalLocation::factory()->forProject($project)->create();
}

test('guests are redirected from the risk register', function () {
    $this->get(route('risks.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the risk register', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)->get(route('risks.index'))->assertOk()->assertSeeText('Risks');
});

test('risks can be filtered by search place and whether they materialised', function () {
    $user = User::factory()->role('General Manager')->create();
    $first = registerRow('Aleppo', 'Wells');
    $second = registerRow('Homs', 'Shelter');

    Risk::factory()->onRow($first)->create([
        'name' => 'Access restrictions',
        'impact' => 'Deliveries pause at checkpoints.',
        'happened' => true,
    ]);
    Risk::factory()->onRow($second)->create([
        'name' => 'Currency collapse',
        'impact' => 'Purchasing power falls.',
        'happened' => false,
    ]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertSee('Access restrictions')->assertSee('Currency collapse')
        ->set('search', 'checkpoint')->assertSee('Access restrictions')->assertDontSee('Currency collapse')
        // The place is searched by its city and by its program.
        ->call('clearFilters')->set('search', 'Homs')->assertDontSee('Access restrictions')->assertSee('Currency collapse')
        ->call('clearFilters')->set('search', 'Shelter')->assertDontSee('Access restrictions')->assertSee('Currency collapse')
        ->call('clearFilters')->set('location', (string) $second->id)->assertDontSee('Access restrictions')->assertSee('Currency collapse')
        ->call('clearFilters')->call('toggleMaterialisedOnly')->assertSee('Access restrictions')->assertDontSee('Currency collapse')
        ->call('clearFilters')
        ->assertSet('search', '')->assertSet('location', '')->assertSet('materialisedOnly', false)
        ->assertSee('Access restrictions')->assertSee('Currency collapse');
});

test('the place filter reaches the register from a URL', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow('Aleppo', 'Wells');
    Risk::factory()->onRow($row)->create(['name' => 'Here risk']);
    Risk::factory()->onRow(registerRow('Homs', 'Shelter'))->create(['name' => 'Elsewhere risk']);

    $this->actingAs($user)
        ->get(route('risks.index', ['location' => $row->id]))
        ->assertOk()
        ->assertSeeText('Here risk')
        ->assertDontSeeText('Elsewhere risk');
});

test('the register names the place a risk belongs to, on its own card', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->onRow(registerRow('Aleppo', 'Wells'))->create(['name' => 'Access restrictions']);

    $html = Livewire::actingAs($user)->test('pages::risks.index')->assertSee('Access restrictions')->html();

    // Not just anywhere on the page: the place picker lists every place too.
    $card = str($html)->after('data-test="risk-place-'.$risk->id.'"')->before('</a>')->toString();

    expect($card)->toContain('Aleppo · Wells');
});

test('a risk the backfill could not map still shows, named by its project', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['name' => 'Unmapped Project']);
    Risk::factory()->create(['project_id' => $project->id, 'proposal_location_id' => null, 'name' => 'Orphan risk']);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertSee('Orphan risk')
        ->assertSee('Unmapped Project');
});

test('the materialised-only URL filter selects materialised risks', function () {
    $user = User::factory()->role('General Manager')->create();
    Risk::factory()->onRow(registerRow())->create(['name' => 'Materialised risk', 'happened' => true]);
    Risk::factory()->onRow(registerRow())->create(['name' => 'Open risk', 'happened' => false]);

    $this->actingAs($user)
        ->get(route('risks.index', ['materialisedOnly' => true]))
        ->assertOk()
        ->assertSeeText('Materialised risk')
        ->assertDontSeeText('Open risk');
});

test('a risk can be logged at a place, and carries that place\'s proposal and project', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', '  Fuel shortage  ')
        ->set('impact', '  Generators cannot run, pumping stops.  ')
        ->set('solution', '  Pre-position a 30 day fuel reserve.  ')
        ->set('happened', false)
        ->set('riskLocationId', (string) $row->id)
        ->call('saveRisk')
        ->assertHasNoErrors()
        ->assertSet('showRiskModal', false);

    $this->assertDatabaseHas('risks', [
        'name' => 'Fuel shortage',
        'impact' => 'Generators cannot run, pumping stops.',
        'solution' => 'Pre-position a 30 day fuel reserve.',
        'happened' => false,
        'proposal_location_id' => $row->id,
        'proposal_id' => $row->proposal_id,
        'project_id' => $row->project_id,
    ]);
});

test('blank mitigation is normalized to null', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', 'Fuel shortage')
        ->set('impact', 'Pumping stops.')
        ->set('solution', '   ')
        ->set('riskLocationId', (string) $row->id)
        ->call('saveRisk')
        ->assertHasNoErrors();

    expect(Risk::query()->where('name', 'Fuel shortage')->sole()->solution)->toBeNull();
});

test('logging a risk requires a name an impact and an existing place', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', '   ')
        ->set('impact', '   ')
        ->set('riskLocationId', '999999')
        ->call('saveRisk')
        ->assertHasErrors(['name', 'impact', 'riskLocationId']);

    expect(Risk::query()->count())->toBe(0);
});

test('a place with no project yet cannot take a risk', function () {
    $user = User::factory()->role('General Manager')->create();
    $withoutProject = ProposalLocation::factory()->isNew()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', 'Too early')
        ->set('impact', 'The place is not approved yet.')
        ->set('riskLocationId', (string) $withoutProject->id)
        ->call('saveRisk')
        ->assertHasErrors(['riskLocationId']);

    expect(Risk::query()->count())->toBe(0);
});

test('a risk is rated for likelihood and severity, and the rating can be cleared again', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->onRow(registerRow())->rated(2, 3)->create();

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
    $risk = Risk::factory()->onRow(registerRow())->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::risks.index')
        ->call('openEditModal', $risk->id)
        ->set('likelihood', '4')
        ->set('severity', '0')
        ->call('saveRisk')
        ->assertHasErrors(['likelihood', 'severity']);

    expect($risk->fresh()->likelihood)->toBeNull();
});

test('a risk can be edited, marked as materialised and moved to another place', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->onRow(registerRow())->create(['name' => 'Old risk', 'solution' => null, 'happened' => false]);
    $newRow = registerRow('Homs', 'Shelter');

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openEditModal', $risk->id)
        ->assertSet('name', 'Old risk')->assertSet('solution', '')->assertSet('happened', false)
        ->assertSet('riskLocationId', (string) $risk->proposal_location_id)
        ->set('name', '  Updated risk  ')
        ->set('impact', '  Updated impact.  ')
        ->set('solution', '  Updated mitigation.  ')
        ->set('happened', true)
        ->set('riskLocationId', (string) $newRow->id)
        ->call('saveRisk')
        ->assertHasNoErrors();

    $risk->refresh();

    expect($risk->name)->toBe('Updated risk')
        ->and($risk->impact)->toBe('Updated impact.')
        ->and($risk->solution)->toBe('Updated mitigation.')
        ->and($risk->happened)->toBeTrue()
        ->and($risk->proposal_location_id)->toBe($newRow->id)
        ->and($risk->proposal_id)->toBe($newRow->proposal_id)
        ->and($risk->project_id)->toBe($newRow->project_id);
});

test('a risk can be deleted after confirmation', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->onRow(registerRow())->create();

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
    $risk = Risk::factory()->onRow(registerRow())->create();

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
    $risk = Risk::factory()->onRow(registerRow())->create();

    Livewire::actingAs($user)->test('pages::risks.index')->call('deleteRisk')->assertHasNoErrors();

    $this->assertDatabaseHas('risks', ['id' => $risk->id]);
});

test('a risk can be marked materialised directly from its card', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->onRow(registerRow())->create(['happened' => false]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('setHappened', $risk->id, true)
        ->assertHasNoErrors();

    expect($risk->refresh()->happened)->toBeTrue();
});

test('a risk can be moved back to potential directly from its card', function () {
    $user = User::factory()->role('General Manager')->create();
    $risk = Risk::factory()->onRow(registerRow())->create(['happened' => true]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('setHappened', $risk->id, false)
        ->assertHasNoErrors();

    expect($risk->refresh()->happened)->toBeFalse();
});

test('risk content and the place name are escaped in the register', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow('<script>city()</script>', 'Wells');
    Risk::factory()->onRow($row)->create([
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
        ->assertSee('<script>city()</script>')
        ->assertDontSeeHtml('<script>name()</script>')
        ->assertDontSeeHtml('<img src=x onerror=impact()>')
        ->assertDontSeeHtml('<svg onload=solution()>')
        ->assertDontSeeHtml('<script>city()</script>');
});

test('the risk register renders with no risks', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('pages::risks.index')->assertSee('No risks logged');
});

test('the materialised count stays stable regardless of the materialised-only toggle', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow();
    Risk::factory()->onRow($row)->count(2)->create(['happened' => true]);
    Risk::factory()->onRow($row)->create(['happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    expect($component->get('materialisedCount'))->toBe(2);

    $component->call('toggleMaterialisedOnly');

    expect($component->get('materialisedCount'))->toBe(2);
});

test('toggling materialised-only filters the register to materialised risks', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow();
    Risk::factory()->onRow($row)->create(['name' => 'Materialised risk', 'happened' => true]);
    Risk::factory()->onRow($row)->create(['name' => 'Open risk', 'happened' => false]);

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
    $row = registerRow();
    Risk::factory()->onRow($row)->create(['name' => 'Has mitigation', 'solution' => 'A plan.', 'happened' => true]);
    Risk::factory()->onRow($row)->create(['name' => 'No mitigation', 'solution' => null, 'happened' => true]);
    Risk::factory()->onRow($row)->create(['name' => 'Blank mitigation', 'solution' => '', 'happened' => true]);

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
    Risk::factory()->onRow(registerRow())->create(['name' => 'Not yet occurred', 'solution' => null, 'happened' => false]);

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

test('the chart splits challenges from potential risks per place', function () {
    $user = User::factory()->role('General Manager')->create();
    $aleppo = registerRow('Aleppo', 'Wells');
    $homs = registerRow('Homs', 'Shelter');

    Risk::factory()->onRow($aleppo)->count(2)->create(['happened' => true]);
    Risk::factory()->onRow($homs)->create(['happened' => false]);

    $chart = Livewire::actingAs($user)->test('pages::risks.index')->get('locationRiskChart');

    $aleppoIndex = array_search('Aleppo · Wells', $chart['categories'], true);
    $homsIndex = array_search('Homs · Shelter', $chart['categories'], true);

    expect($aleppoIndex)->not->toBeFalse()
        ->and($homsIndex)->not->toBeFalse()
        ->and($chart['series'][0]['data'][$aleppoIndex])->toBe(2)
        ->and($chart['series'][1]['data'][$aleppoIndex])->toBe(0)
        ->and($chart['series'][0]['data'][$homsIndex])->toBe(0)
        ->and($chart['series'][1]['data'][$homsIndex])->toBe(1);
});

test('every bar of the chart equals what the register lists for that place', function () {
    $user = User::factory()->role('General Manager')->create();
    $aleppo = registerRow('Aleppo', 'Wells');
    $homs = registerRow('Homs', 'Shelter');

    Risk::factory()->onRow($aleppo)->count(3)->create(['happened' => true]);
    Risk::factory()->onRow($aleppo)->create(['happened' => false]);
    Risk::factory()->onRow($homs)->count(2)->create(['happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');
    $chart = $component->get('locationRiskChart');

    $barTotals = [];

    foreach ($chart['categories'] as $index => $category) {
        $barTotals[$category] = $chart['series'][0]['data'][$index] + $chart['series'][1]['data'][$index];
    }

    expect($barTotals)->toBe(['Aleppo · Wells' => 4, 'Homs · Shelter' => 2])
        ->and(array_sum($barTotals))->toBe($component->get('risks')->total())
        ->and($component->get('riskLandscape'))->toBe(['challenges' => 3, 'potential' => 3]);
});

test('the chart and the counts follow the place filter the list follows', function () {
    $user = User::factory()->role('General Manager')->create();
    $aleppo = registerRow('Aleppo', 'Wells');
    $homs = registerRow('Homs', 'Shelter');

    Risk::factory()->onRow($aleppo)->count(2)->create(['solution' => 'A plan.', 'happened' => true]);
    Risk::factory()->onRow($homs)->create(['solution' => null, 'happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    $component->call('toggleMaterialisedOnly');
    expect(array_search('Homs · Shelter', $component->get('locationRiskChart')['categories'], true))->toBeFalse();

    $component->call('clearFilters')->call('toggleUnmitigated');
    expect(array_search('Aleppo · Wells', $component->get('locationRiskChart')['categories'], true))->toBeFalse();

    $component->call('clearFilters')->set('location', (string) $aleppo->id);

    expect($component->get('locationRiskChart')['categories'])->toBe(['Aleppo · Wells'])
        ->and($component->get('risks')->total())->toBe(2);
});

test('the risk landscape totals match the currently filtered risks', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow();
    Risk::factory()->onRow($row)->count(2)->create(['happened' => true]);
    Risk::factory()->onRow($row)->create(['happened' => false]);

    $component = Livewire::actingAs($user)->test('pages::risks.index');

    expect($component->get('riskLandscape'))->toBe(['challenges' => 2, 'potential' => 1]);

    $component->call('toggleMaterialisedOnly');

    expect($component->get('riskLandscape'))->toBe(['challenges' => 2, 'potential' => 0]);
});

test('the place picker offers only places that carry a project', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow('Aleppo', 'Wells');
    $notApproved = ProposalLocation::factory()->isNew()->create();

    $options = Livewire::actingAs($user)->test('pages::risks.index')->get('locationOptions');

    expect($options)->toHaveKey($row->id)
        ->and($options[$row->id])->toBe('Aleppo · Wells')
        ->and($options)->not->toHaveKey($notApproved->id);
});

test('the state picker in the risk form saves the string values it sends as the boolean state', function (string $sent, bool $stored) {
    $user = User::factory()->role('General Manager')->create();
    $row = registerRow();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', 'Fuel shortage')
        ->set('impact', 'Generators cannot run.')
        ->set('riskLocationId', (string) $row->id)
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

test('the legacy register stays reachable for one release and says where risks live now', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)->get(route('risks.index'))
        ->assertOk()
        ->assertSee('data-test="risk-register-moved-notice"', false)
        ->assertSee(__('risks.moved_notice'))
        ->assertSee(route('proposals.index'), false);
});
