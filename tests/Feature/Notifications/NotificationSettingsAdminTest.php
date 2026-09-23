<?php

use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WorkingCalendar;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('only administrators open notification settings', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('admin.notification-settings.edit'))->assertStatus($status);
})->with([
    ['Super Admin', 200],
    ['General Manager', 403],
    ['Managers', 403],
]);

test('an administrator changes thresholds and working days and they take effect', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->assertSet('workingDays', [1, 2, 3, 4, 5])
        ->assertSet('dueSoonDays', '7, 3, 1')
        ->set('workingDays', [7, 1, 2, 3, 4])
        ->set('dueSoonDays', '10, 5, 2')
        ->set('overdueRepeat', 2)
        ->set('dailyBriefTime', '07:00')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(NotificationSettings::class);

    expect($settings->get('due_soon_days'))->toBe([10, 5, 2])
        ->and($settings->get('overdue_repeat_days'))->toBe(2)
        ->and($settings->get('daily_brief_time'))->toBe('07:00')
        ->and(app(WorkingCalendar::class)->isWorkingDay(CarbonImmutable::parse('2026-09-27')))->toBeTrue();
});

test('settings are validated', function (string $property, mixed $value) {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->set($property, $value)
        ->call('save')
        ->assertHasErrors($property);
})->with([
    'no working day' => ['workingDays', []],
    'a bad weekday' => ['workingDays', [8]],
    'a bad stage list' => ['dueSoonDays', '7, soon'],
    'a zero repeat' => ['overdueRepeat', 0],
    'top before manager' => ['escalateTopAfter', 3],
    'a pace over 100' => ['offTrackPace', 150],
    'a bad time' => ['weeklyDigestTime', '8am'],
]);

test('a non-administrator cannot save by calling the action', function () {
    Livewire::actingAs(userWithRole('Managers'))->test('pages::admin.notification-settings')->call('save')->assertForbidden();
});
