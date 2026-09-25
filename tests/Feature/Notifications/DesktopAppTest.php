<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\NotificationPresenter;

function desktopAppNotification(User $user): UserNotification
{
    $user->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action,
        module: 'system',
        priority: NotificationPriority::Important,
        key: 'notifications.test_message',
        params: [],
        subjectType: null,
        subjectId: null,
    )));

    return UserNotification::query()->forUser($user)->latest('created_at')->firstOrFail();
}

test('the app manifest names HC Tracking, uses the header colour and points at icons that exist', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe('HC Tracking')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['theme_color'])->toBe('#dcddde')
        ->and($manifest['start_url'])->toBe(route('dashboard', absolute: false))
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('any', 'maskable');

    foreach ([...$manifest['icons'], ...collect($manifest['shortcuts'])->flatMap(fn (array $shortcut) => $shortcut['icons'])->all()] as $icon) {
        expect(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue($icon['src'].' is missing')
            ->and($icon['src'])->not->toStartWith('/icons/', 'Apache aliases /icons/ to its own folder, so these 404 in production');
    }
});

test('every page links the manifest and title bar colours matching the header', function () {
    $this->actingAs(Activity::withoutRecording(fn () => User::factory()->create()))
        ->get(route('dashboard'))
        ->assertSeeHtml('rel="manifest"')
        ->assertSeeHtml('<meta name="theme-color" content="#dcddde" media="(prefers-color-scheme: light)" data-theme-color="light">')
        ->assertSeeHtml('<meta name="theme-color" content="#58595b" media="(prefers-color-scheme: dark)" data-theme-color="dark">')
        ->assertSeeHtml('data-notification-toasts')
        ->assertSeeHtml('id="hc-notification-labels"');
});

test('the service worker handles notification clicks and never caches pages', function () {
    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)->toContain("addEventListener('notificationclick'")
        ->not->toContain("addEventListener('fetch'")
        ->not->toContain('caches.');
});

test('a user can mark their own notification read from a popup or toast', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $notification = desktopAppNotification($user);

    $this->actingAs($user)->postJson(route('notifications.read', $notification->id))->assertNoContent();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('nobody can mark another user\'s notification read', function () {
    [$owner, $intruder] = Activity::withoutRecording(fn () => User::factory()->count(2)->create());
    $notification = desktopAppNotification($owner);

    $this->actingAs($intruder)->postJson(route('notifications.read', $notification->id))->assertNotFound();
    $this->post(route('notifications.read', $notification->id));

    expect($notification->fresh()->read_at)->toBeNull();
});

test('guests are sent to sign in', function () {
    $owner = Activity::withoutRecording(fn () => User::factory()->create());

    $this->post(route('notifications.read', desktopAppNotification($owner)->id))->assertRedirect(route('login'));
});

test('a pushed notification carries the link that marks it read', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());
    $notification = desktopAppNotification($user);

    expect(app(NotificationPresenter::class)->toBroadcast($notification, 'en')['read_url'])
        ->toBe(route('notifications.read', $notification->id));
});

function desktopAppPage(string $routeName): DOMXPath
{
    $response = test()->actingAs(Activity::withoutRecording(fn () => User::factory()->create()))
        ->get(route($routeName))
        ->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

test('the settings page offers the desktop app install through the install modal', function () {
    $xpath = desktopAppPage('notification-settings.edit');

    $installButton = $xpath->query('//*[@data-test="install-app-button"]')->item(0);

    expect(trim($xpath->query('//*[@id="desktop-app-heading"]')->item(0)->textContent))->toBe(__('notifications.app.heading'))
        ->and($xpath->query('//*[@data-app-install-hint]')->length)->toBe(1)
        ->and($installButton->getAttribute('x-on:click'))->toBe("\$flux.modal('install-app').show()");
});

test('both top headers offer the desktop app install as an icon with a tooltip, right before the notification bell', function () {
    $xpath = desktopAppPage('dashboard');

    $installButtons = $xpath->query('//*[@data-test="header-install-app-button"]');

    expect($installButtons->length)->toBe(2);

    foreach ($installButtons as $installButton) {
        $tooltip = $xpath->query('ancestor::*[@data-flux-tooltip][1]', $installButton)->item(0);

        expect(trim($installButton->textContent))->toBe('')
            ->and($installButton->getAttribute('aria-label'))->toBe(__('notifications.app.tooltip'))
            ->and($installButton->getAttribute('x-on:click'))->toBe("\$flux.modal('install-app').show()");

        expect(trim($xpath->query('.//*[@data-flux-tooltip-content]', $tooltip)->item(0)->textContent))->toBe(__('notifications.app.tooltip'))
            ->and($tooltip->getAttribute('x-show'))->toBe("state === 'available'")
            ->and($xpath->query('following-sibling::*[1][@data-notification-bell]', $tooltip)->length)->toBe(1);
    }
});

test('signed-in pages carry one install modal that explains the app before the browser confirms', function () {
    $xpath = desktopAppPage('dashboard');

    $modals = $xpath->query('//dialog[@data-modal="install-app"]');

    expect($modals->length)->toBe(1);

    $modal = $modals->item(0);

    expect(preg_replace('/\s+/', ' ', $modal->textContent))->toContain(
        __('notifications.app.install'),
        __('notifications.app.description'),
        __('notifications.app.window_title'),
        __('notifications.app.window_text'),
        __('notifications.app.alerts_title'),
        __('notifications.app.alerts_text'),
        __('notifications.app.live_title'),
        __('notifications.app.live_text'),
        __('notifications.app.confirm_next'),
        __('notifications.app.not_now'),
    );

    expect($xpath->query('.//*[@data-install-reason]', $modal)->length)->toBe(3)
        ->and($xpath->query('.//*[@data-test="install-app-confirm"]', $modal)->item(0)->getAttribute('x-on:click'))->toBe('install()');
});

test('every module has a desktop icon for both popup urgencies', function () {
    $modules = [...array_keys(config('notifications.modules')), 'system'];

    expect(array_keys(config('notifications.module_icons')))->toEqualCanonicalizing($modules);

    foreach ($modules as $module) {
        foreach (['critical', 'important'] as $priority) {
            expect(file_exists(public_path("notification-banners/tiles/{$module}-{$priority}.png")))->toBeTrue("{$module}-{$priority} tile is missing");
        }
    }
});

test('a pushed notification points at its module tile, and info falls back to the app icon', function () {
    $presenter = app(NotificationPresenter::class);

    expect($presenter->desktopIcon('risks', 'critical'))->toBe(asset('notification-banners/tiles/risks-critical.png'))
        ->and($presenter->desktopIcon('tasks', 'info'))->toBe(asset('app-icons/icon-192.png'));

    $user = Activity::withoutRecording(fn () => User::factory()->create());

    expect($presenter->toBroadcast(desktopAppNotification($user), 'en')['icon'])->toBe(asset('notification-banners/tiles/system-important.png'));
});

test('every module has a banner for both popup urgencies in both languages, and a push carries the reader\'s one', function () {
    foreach (array_keys(config('notifications.module_icons')) as $module) {
        foreach (['critical', 'important'] as $priority) {
            foreach (['en', 'ar'] as $locale) {
                expect(file_exists(public_path("notification-banners/{$locale}/{$module}-{$priority}.png")))->toBeTrue("{$locale}/{$module}-{$priority} banner is missing");
            }
        }
    }

    $presenter = app(NotificationPresenter::class);
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    expect($presenter->toBroadcast(desktopAppNotification($user), 'ar')['image'])->toBe(asset('notification-banners/ar/system-important.png'))
        ->and($presenter->desktopBanner('tasks', 'info', 'en'))->toBeNull();
});
