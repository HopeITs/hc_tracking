<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;

test('priorities rank info below important below critical', function () {
    expect(NotificationPriority::Info->rank())->toBeLessThan(NotificationPriority::Important->rank())
        ->and(NotificationPriority::Important->rank())->toBeLessThan(NotificationPriority::Critical->rank());
});

test('the highest of several priorities wins', function () {
    expect(NotificationPriority::highest(NotificationPriority::Info, NotificationPriority::Critical, NotificationPriority::Important))
        ->toBe(NotificationPriority::Critical)
        ->and(NotificationPriority::highest())->toBe(NotificationPriority::Info);
});

test('priority and kind labels are translated in arabic', function () {
    app()->setLocale('ar');

    expect(NotificationPriority::Critical->label())->toBe('حرجة')
        ->and(NotificationKind::Alert->label())->toBe('تنبيه');
});
