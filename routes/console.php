<?php

use App\Models\Activity;
use App\Models\UserNotification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:wake-snoozed')->everyMinute()->withoutOverlapping();

Schedule::command('notifications:scan frequent')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('notifications:scan daily')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('notifications:digests')->everyMinute()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [Activity::class, UserNotification::class]])->daily();
