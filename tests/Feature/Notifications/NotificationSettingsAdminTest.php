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

test('in arabic every working-day checkbox is labelled with its own weekday', function () {
    app()->setLocale('ar');

    $html = Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')->html();
    preg_match_all('/data-weekday="(\d)".*?value="\1".*?<span class="rules-day-name">\s*<span[^>]*>([^<]+?)<\/span>/su', $html, $matches, PREG_SET_ORDER);

    expect(collect($matches)->mapWithKeys(fn (array $match): array => [(int) $match[1] => trim($match[2])])->all())
        ->toBe([1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت', 7 => 'الأحد']);
});

test('the escalation catch-up window is saved and validated', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->assertSet('escalationCatchUp', 3)
        ->set('escalationCatchUp', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(NotificationSettings::class)->get('escalation_catch_up_days'))->toBe(5);

    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->set('escalationCatchUp', 0)
        ->call('save')
        ->assertHasErrors('escalationCatchUp');
});

test('saving hands the page back the stored, normalised rules and tells it to take a fresh snapshot', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->set('workingDays', ['5', '1', '3', '1'])
        ->set('dueSoonDays', '3,10 , 3')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('workingDays', [1, 3, 5])
        ->assertSet('dueSoonDays', '10, 3')
        ->assertDispatched('notification-rules-saved');

    expect(app(NotificationSettings::class)->get('working_days'))->toBe([1, 3, 5])
        ->and(app(NotificationSettings::class)->get('due_soon_days'))->toBe([10, 3]);
});

test('a failed save neither stores the rules nor tells the page to take a snapshot', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->set('overdueRepeat', 0)
        ->call('save')
        ->assertNotDispatched('notification-rules-saved');

    expect(app(NotificationSettings::class)->get('overdue_repeat_days'))->toBe(3);
});

test('the reset-to-defaults values are the shipped defaults, not the stored rules', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4], 'due_soon_days' => [5], 'stalled_proposal_days' => 45]);

    $component = Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings');

    expect($component->get('stalledProposal'))->toBe(45)
        ->and($component->instance()->clientConfig()['defaults'])->toBe([
            'workingDays' => [1, 2, 3, 4, 5],
            'dueSoonDays' => '7, 3, 1',
            'overdueRepeat' => 3,
            'escalateManagerAfter' => 5,
            'escalateTopAfter' => 10,
            'escalationCatchUp' => 3,
            'stalledTaskInProgress' => 7,
            'stalledTaskUnderReview' => 3,
            'stalledReportDraft' => 7,
            'stalledReportReviewed' => 5,
            'stalledProposal' => 30,
            'offTrackPace' => 80,
            'dailyBriefTime' => '07:30',
            'weeklyDigestTime' => '08:00',
        ]);
});

test('discarding the draft clears the errors of a failed save', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->set('overdueRepeat', 0)
        ->call('save')
        ->assertHasErrors('overdueRepeat')
        ->set('overdueRepeat', 3)
        ->call('forgetErrors')
        ->assertHasNoErrors();
});

test('in arabic the page script gets one plural form per arabic range', function () {
    app()->setLocale('ar');

    $plurals = Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')->instance()->clientConfig()['plurals'];

    expect($plurals['workingDays'])->toBe([1 => 'يوم عمل', 2 => 'يوما عمل', 3 => 'أيام عمل', 11 => 'يوم عمل'])
        ->and($plurals['unsaved'][11])->toBe(':count تغييرًا غير محفوظ');
});

test('the page renders every rule section with its controls', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->assertSeeHtml('id="rules-week"')
        ->assertSeeHtml('id="rules-deadlines"')
        ->assertSeeHtml('id="rules-stalled"')
        ->assertSeeHtml('id="rules-digests"')
        ->assertSeeHtml('id="rule-escalateTopAfter"')
        ->assertSeeHtml('id="rule-stalledProposal"')
        ->assertSeeHtml('id="rule-weeklyDigestTime"')
        ->assertSee(__('notifications.admin.stalled_report_reviewed'));
});
