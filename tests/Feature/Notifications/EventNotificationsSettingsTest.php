<?php

use App\Enums\EventImportance;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\EventCatalog;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The row of one catalog key in the page's `events` list.
 */
function eventRow(string $key): int
{
    return (int) array_search($key, EventCatalog::keys(), true);
}

function eventSettingsPage(string $role = 'Super Admin'): Testable
{
    return Livewire::actingAs(userWithRole($role))->test('pages::admin.notification-settings');
}

test('every catalog event is listed with its default', function () {
    $component = eventSettingsPage();
    $events = $component->get('events');

    expect(array_column($events, 'key'))->toBe(EventCatalog::keys())
        ->and($events[eventRow('project.created')])->toBe(['key' => 'project.created', 'enabled' => true, 'importance' => 'digest', 'oversight_roles' => []])
        ->and($events[eventRow('project.approved')])->toBe(['key' => 'project.approved', 'enabled' => true, 'importance' => 'immediate', 'oversight_roles' => []]);
});

test('saving writes only the events that differ from the catalog', function () {
    userWithRole('Financial');

    eventSettingsPage()
        ->set('events.'.eventRow('project.created').'.enabled', false)
        ->set('events.'.eventRow('proposal.reviewer_added').'.importance', 'digest')
        ->set('events.'.eventRow('project.approved').'.oversight_roles', ['Financial'])
        ->call('save')
        ->assertHasNoErrors();

    $catalog = app(EventCatalog::class);

    expect(app(NotificationSettings::class)->get('event_overrides'))->toEqual([
        'project.created' => ['enabled' => false],
        'proposal.reviewer_added' => ['importance' => 'digest'],
        'project.approved' => ['oversight_roles' => ['Financial']],
    ])
        ->and($catalog->effective('project.created')['enabled'])->toBeFalse()
        ->and($catalog->effective('proposal.reviewer_added')['importance'])->toBe(EventImportance::Digest)
        ->and($catalog->effective('project.approved')['oversight_roles'])->toBe(['Financial']);
});

test('putting an event back to its default removes the override', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['project.created' => ['enabled' => false]]]);

    eventSettingsPage()
        ->assertSet('events.'.eventRow('project.created').'.enabled', false)
        ->set('events.'.eventRow('project.created').'.enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(NotificationSettings::class)->get('event_overrides'))->toBe([]);
});

test('scanner events keep their catalog importance and none-oversight events take no roles', function () {
    eventSettingsPage()
        ->set('events.'.eventRow('project.started').'.importance', 'digest')
        ->set('events.'.eventRow('department_task.created').'.oversight_roles', ['MEAL'])
        ->call('save')
        ->assertHasNoErrors();

    expect(app(NotificationSettings::class)->get('event_overrides'))->toBe([]);
});

test('a non-administrator cannot save event overrides', function () {
    eventSettingsPage('Managers')
        ->set('events.'.eventRow('project.created').'.enabled', false)
        ->call('save')
        ->assertForbidden();

    $fresh = fn () => eventSettingsPage('General Manager');
    $fresh()->call('save')->assertForbidden();

    expect(app(NotificationSettings::class)->get('event_overrides'))->toBe([]);
});

test('event overrides are validated', function (string $field, mixed $value, ?string $message) {
    $component = eventSettingsPage()
        ->set('events.0.'.$field, $value)
        ->call('save')
        ->assertHasErrors('events.0.'.$field);

    if ($message !== null) {
        $component->assertSee(__($message));
    }

    expect(app(NotificationSettings::class)->get('event_overrides'))->toBe([]);
})->with([
    'unknown event' => ['key', 'project.teleported', 'notifications.settings.events.unknown_event'],
    'bad importance' => ['importance', 'someday', null],
    'enabled not boolean' => ['enabled', 'maybe', null],
]);

test('oversight roles must exist and never be Super Admin', function (string $role, string $message) {
    eventSettingsPage()
        ->set('events.0.oversight_roles', [$role])
        ->call('save')
        ->assertHasErrors('events.0.oversight_roles.0')
        ->assertSee(__($message));

    expect(app(NotificationSettings::class)->get('event_overrides'))->toBe([]);
})->with([
    'deleted role' => ['Astronauts', 'notifications.settings.events.unknown_role'],
    'super admin' => ['Super Admin', 'notifications.settings.events.super_admin_forbidden'],
]);

test('stale stored overrides are flagged, left out of the form and dropped on save', function () {
    userWithRole('MEAL');
    app(NotificationSettings::class)->save(['event_overrides' => [
        'project.teleported' => ['enabled' => false],
        'project.approved' => ['oversight_roles' => ['MEAL', 'Astronauts']],
    ]]);

    $component = eventSettingsPage()
        ->assertSee('project.teleported')
        ->assertSee('Astronauts')
        ->assertSet('events.'.eventRow('project.approved').'.oversight_roles', ['MEAL']);

    $component->call('save')->assertHasNoErrors();

    expect(app(NotificationSettings::class)->get('event_overrides'))->toBe(['project.approved' => ['oversight_roles' => ['MEAL']]]);
});

test('events are grouped by module and titled in arabic', function () {
    app()->setLocale('ar');

    eventSettingsPage()
        ->assertSeeHtml('id="rules-events"')
        ->assertSee(__('notifications.modules.proposals'))
        ->assertSee(__('notifications.events.project.approved.title'))
        ->assertDontSee('Super Admin');
});

test('the event section adds a flat number of queries however many overrides are stored', function () {
    $admin = userWithRole('Super Admin');
    userWithRole('MEAL');
    userWithRole('Financial');
    $render = function () use ($admin): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($admin)->test('pages::admin.notification-settings');

        return $queries;
    };

    app(NotificationSettings::class)->save(['event_overrides' => ['project.created' => ['enabled' => false]]]);
    $render(); // warms the settings cache and the remembered HR lookups
    $few = $render();

    app(NotificationSettings::class)->save(['event_overrides' => collect(EventCatalog::keys())
        ->mapWithKeys(fn (string $key): array => [$key => ['enabled' => false, 'oversight_roles' => ['MEAL', 'Financial', 'Astronauts']]])
        ->put('project.teleported', ['enabled' => false])
        ->all()]);
    $render();

    expect($render())->toBe($few);
});
