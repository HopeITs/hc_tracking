<?php

use App\Models\Activity;
use App\Models\TrustedDevice;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:wake-snoozed')->everyMinute()->withoutOverlapping();

Schedule::command('notifications:scan frequent')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('notifications:scan daily')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('notifications:digests')->everyMinute()->withoutOverlapping();
Schedule::command('reports:monthly-digest')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [Activity::class, TrustedDevice::class, UserNotification::class]])->daily();
Schedule::command('files:prune')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('cache:prune-expired')->hourly()->withoutOverlapping();
