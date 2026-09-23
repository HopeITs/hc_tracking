<?php

use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WorkingCalendar;
use App\Notifications\Inbox;
use Carbon\CarbonImmutable;

// 2026-09-21 is a Monday.
function calendarDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('monday to friday is the default working week', function () {
    $calendar = app(WorkingCalendar::class);

    expect($calendar->isWorkingDay(calendarDay('2026-09-25')))->toBeTrue()
        ->and($calendar->isWorkingDay(calendarDay('2026-09-26')))->toBeFalse()
        ->and($calendar->isWorkingDay(calendarDay('2026-09-27')))->toBeFalse();
});

test('working days between two dates skip the weekend', function () {
    $calendar = app(WorkingCalendar::class);

    expect($calendar->workingDaysBetween(calendarDay('2026-09-25'), calendarDay('2026-09-28')))->toBe(1)
        ->and($calendar->workingDaysBetween(calendarDay('2026-09-23'), calendarDay('2026-09-30')))->toBe(5)
        ->and($calendar->workingDaysBetween(calendarDay('2026-09-21'), calendarDay('2026-10-19')))->toBe(20)
        ->and($calendar->workingDaysBetween(calendarDay('2026-09-23'), calendarDay('2026-09-23')))->toBe(0)
        ->and($calendar->workingDaysBetween(calendarDay('2026-09-24'), calendarDay('2026-09-23')))->toBe(0);
});

test('a date on a weekend moves back to the last working day', function () {
    $calendar = app(WorkingCalendar::class);

    expect($calendar->lastWorkingDayOnOrBefore(calendarDay('2026-09-27'))->toDateString())->toBe('2026-09-25')
        ->and($calendar->lastWorkingDayOnOrBefore(calendarDay('2026-09-24'))->toDateString())->toBe('2026-09-24')
        ->and($calendar->addWorkingDays(calendarDay('2026-09-25'), 2)->toDateString())->toBe('2026-09-29');
});

test('a custom working week is honoured', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4]]); // Sunday–Thursday
    $calendar = app(WorkingCalendar::class);

    expect($calendar->isWorkingDay(calendarDay('2026-09-27')))->toBeTrue()
        ->and($calendar->isWorkingDay(calendarDay('2026-09-25')))->toBeFalse()
        ->and($calendar->lastWorkingDayOnOrBefore(calendarDay('2026-09-26'))->toDateString())->toBe('2026-09-24')
        ->and($calendar->workingDaysBetween(calendarDay('2026-09-24'), calendarDay('2026-09-27')))->toBe(1);
});

test('snoozing until next week lands on its first working day', function () {
    app(NotificationSettings::class)->save(['working_days' => [2, 3, 4, 5, 6]]); // Tuesday–Saturday

    expect(Inbox::snoozeUntil('next_week', calendarDay('2026-09-23 10:00'))->format('Y-m-d H:i'))->toBe('2026-09-29 08:00');
});
