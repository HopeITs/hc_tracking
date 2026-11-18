<?php

use App\Models\Activity;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

test('only super admins and the general manager can open the activity log', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('activity.index'))->assertStatus($status);
})->with([
    ['Super Admin', 200],
    ['General Manager', 200],
    ['Managers', 403],
    ['MEAL', 403],
]);

test('the log filters by module, person, text and date range', function () {
    $sami = userWithRole('Managers');
    $rana = userWithRole('Managers');

    $this->actingAs($sami);
    Task::factory()->create(['title' => 'Water trucking']);
    $this->travel(2)->days();
    $this->actingAs($rana);
    Risk::factory()->create(['name' => 'Road closure']);

    $page = fn () => Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index');

    $page()->set('module', 'risks')->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
    $page()->set('actor', (string) $sami->id)->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('search', 'trucking')->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('from', now()->toDateString())->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
});

test('search finds what the row says: the person, the wording and the label in any case', function () {
    $sami = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['name' => 'Sami Haddad']));
    $rana = Activity::withoutRecording(fn () => User::factory()->role('Managers')->create(['name' => 'Rana Khoury']));

    $this->actingAs($sami);
    Task::factory()->create(['title' => 'Water trucking']);
    $this->actingAs($rana);
    Risk::factory()->create(['name' => 'Road closure']);

    $page = fn () => Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index');

    $page()->set('search', 'Khoury')->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
    $page()->set('search', 'logged')->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
    $page()->set('search', 'WATER')->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('search', 'sami trucking')->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('search', 'sami closure')->assertDontSeeText('Water trucking')->assertDontSeeText('Road closure');

    app()->setLocale('ar');
    $page()->set('search', 'سجل')->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
});

test('entries sort by time or module, toggling direction on repeat clicks', function () {
    $this->actingAs(userWithRole('Managers'));
    Risk::factory()->create(['name' => 'Alpha risk']);
    $this->travel(1)->hours();
    Task::factory()->create(['title' => 'Beta task']);

    $page = Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index');

    $page->assertSeeInOrder(['Beta task', 'Alpha risk']);

    $page->call('setSort', 'module')
        ->assertSeeInOrder(['Alpha risk', 'Beta task']);

    $page->call('setSort', 'module')
        ->assertSeeInOrder(['Beta task', 'Alpha risk']);

    $page->call('setSort', 'created_at')
        ->assertSeeInOrder(['Alpha risk', 'Beta task']);
});

test('sorting by area follows the area name the reader sees, not its key', function () {
    $this->actingAs(userWithRole('Managers'));
    Risk::factory()->create(['name' => 'A risk entry']);
    $access = Activity::query()->first()->replicate();
    $access->module = 'access-management';
    $access->properties = [...$access->properties, 'label' => 'An access entry'];
    $access->save();

    expect(__('notifications.modules.risks'))->toBe('Risks')
        ->and(__('notifications.modules.access-management'))->toBe('Users & roles');

    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index')
        ->call('setSort', 'module')
        ->assertSeeInOrder(['A risk entry', 'An access entry'])
        ->call('setSort', 'module')
        ->assertSeeInOrder(['An access entry', 'A risk entry']);
});

test('a deleted record shows as deleted instead of a link', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Gone']));
    $this->actingAs(userWithRole('Managers'));
    $task->delete();

    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index')
        ->assertSeeText(__('activity.record_gone'))
        ->assertDontSeeHtml(route('tasks.show', $task));
});

test('the sidebar links the log only for those who may open it', function () {
    $this->actingAs(userWithRole('General Manager'))->get(route('dashboard'))->assertSee(route('activity.index'));
    $this->actingAs(userWithRole('Managers'))->get(route('dashboard'))->assertDontSee(route('activity.index'));
});

test('an impossible date in the address is ignored instead of crashing the log', function () {
    $this->actingAs(userWithRole('Managers'));
    Task::factory()->create(['title' => 'Water trucking']);

    $this->actingAs(userWithRole('Super Admin'))
        ->get(route('activity.index', ['from' => '2026-13-45']))
        ->assertOk()
        ->assertSeeText('Water trucking');
});

test('the today and last 7 days numbers filter the list and clear on a second press', function () {
    $this->actingAs(userWithRole('Managers'));
    $this->travel(-10)->days();
    Risk::factory()->create(['name' => 'Old risk']);
    $this->travelBack();
    Task::factory()->create(['title' => 'Fresh task']);

    $page = Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index')
        ->assertSeeText('Old risk')
        ->call('showRange', 'today')
        ->assertSet('from', now()->toDateString())
        ->assertSeeText('Fresh task')
        ->assertDontSeeText('Old risk')
        ->assertSeeHtml('aria-pressed="true"')
        ->call('showRange', 'today')
        ->assertSet('from', '')
        ->assertSeeText('Old risk');

    $page->call('showRange', 'week')->assertDontSeeText('Old risk');
});

test('a day split across pages shows its real total and an empty result offers a reset', function () {
    $this->actingAs(userWithRole('Managers'));
    Task::factory()->count(55)->create();

    $page = Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index');

    expect(Activity::count())->toBeGreaterThan(50)
        ->and($page->instance()->dayTotals)->toBe([now()->toDateString() => Activity::count()]);

    $page->set('search', 'zzz-no-match')
        ->assertSeeText(__('activity.empty_filtered'))
        ->assertSeeHtml('data-activity-reset')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertDontSeeText(__('activity.empty_filtered'));
});

test('the system actor filter keeps only entries with no person', function () {
    $this->actingAs(userWithRole('Managers'));
    Risk::factory()->create(['name' => 'By a person']);
    $system = Activity::query()->first()->replicate();
    $system->actor_id = null;
    $system->properties = [...$system->properties, 'label' => 'By the system'];
    $system->save();

    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index')
        ->set('actor', 'system')
        ->assertSeeText('By the system')
        ->assertDontSeeText('By a person');
});
