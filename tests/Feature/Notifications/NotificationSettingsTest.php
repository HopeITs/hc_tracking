<?php

use App\Notifications\Alerts\NotificationSettings;

test('settings fall back to the shipped defaults', function () {
    $settings = app(NotificationSettings::class);

    expect($settings->get('working_days'))->toBe([1, 2, 3, 4, 5])
        ->and($settings->get('due_soon_days'))->toBe([7, 3, 1])
        ->and($settings->get('overdue_repeat_days'))->toBe(3)
        ->and($settings->get('escalate_manager_after_days'))->toBe(5)
        ->and($settings->get('escalate_top_after_days'))->toBe(10)
        ->and($settings->get('daily_brief_time'))->toBe('07:30')
        ->and($settings->get('weekly_digest_time'))->toBe('08:00');
});

test('a saved value wins over the default and survives a fresh instance', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4], 'overdue_repeat_days' => 2]);

    $fresh = new NotificationSettings;

    expect($fresh->get('working_days'))->toBe([7, 1, 2, 3, 4])
        ->and($fresh->get('overdue_repeat_days'))->toBe(2)
        ->and($fresh->get('stalled_proposal_days'))->toBe(30)
        ->and($fresh->all()['overdue_repeat_days'])->toBe(2);
});
