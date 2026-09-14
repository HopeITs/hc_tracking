<?php

use App\Models\Project;
use App\Models\Risk;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the risk register', function () {
    $this->get(route('risks.index'))->assertRedirect(route('login'));
});

test('authenticated users can view the risk register', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('risks.index'))->assertOk()->assertSeeText('Risks');
});

test('risks can be filtered by search project and whether they materialised', function () {
    $user = User::factory()->create();
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
        ->call('clearFilters')->set('status', 'happened')->assertSee('Access restrictions')->assertDontSee('Currency collapse')
        ->call('clearFilters')->set('status', 'watching')->assertDontSee('Access restrictions')->assertSee('Currency collapse')
        ->call('clearFilters')
        ->assertSet('search', '')->assertSet('project', '')->assertSet('status', '')
        ->assertSee('Access restrictions')->assertSee('Currency collapse');
});

test('the state URL filter selects materialised risks', function () {
    $user = User::factory()->create();
    Risk::factory()->create(['name' => 'Materialised risk', 'happened' => true]);
    Risk::factory()->create(['name' => 'Watched risk', 'happened' => false]);

    $this->actingAs($user)
        ->get(route('risks.index', ['status' => 'happened']))
        ->assertOk()
        ->assertSeeText('Materialised risk')
        ->assertDontSeeText('Watched risk');
});

test('a risk can be logged against a project', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();

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

test('a risk can be edited and marked as materialised', function () {
    $user = User::factory()->create();
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
    $user = User::factory()->create();
    $risk = Risk::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('confirmDelete', $risk->id)
        ->assertSet('deletingRiskId', $risk->id)
        ->call('deleteRisk')
        ->assertHasNoErrors()
        ->assertSet('deletingRiskId', null);

    $this->assertDatabaseMissing('risks', ['id' => $risk->id]);
});

test('delete is a no-op until a risk is confirmed', function () {
    $user = User::factory()->create();
    $risk = Risk::factory()->create();

    Livewire::actingAs($user)->test('pages::risks.index')->call('deleteRisk')->assertHasNoErrors();

    $this->assertDatabaseHas('risks', ['id' => $risk->id]);
});

test('risk content is escaped in the register', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => '<script>project()</script>']);
    Risk::factory()->create([
        'project_id' => $project->id,
        'name' => '<script>name()</script>',
        'impact' => '<img src=x onerror=impact()>',
        'solution' => '<svg onload=solution()>',
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
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::risks.index')->assertSee('No risks logged');
});
