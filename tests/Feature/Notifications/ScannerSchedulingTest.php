<?php

use App\Jobs\RunScanner;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use App\Notifications\Alerts\OnHoldReminderScanner;
use App\Notifications\Alerts\OversightDigestScanner;
use App\Notifications\Alerts\ProjectDatesScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Queue;

test('a scan group queues one job per scanner', function () {
    Queue::fake();

    $this->artisan('notifications:scan frequent')->assertSuccessful();

    Queue::assertPushed(RunScanner::class, 4);
    Queue::assertPushed(RunScanner::class, fn (RunScanner $job): bool => $job->scanner === DueSoonScanner::class);
});

test('the daily group queues the project dates, on-hold reminder and oversight digest scanners', function () {
    Queue::fake();

    $this->artisan('notifications:scan daily')->assertSuccessful();

    Queue::assertPushed(RunScanner::class, 5);

    foreach ([ProjectDatesScanner::class, OnHoldReminderScanner::class, OversightDigestScanner::class] as $scanner) {
        Queue::assertPushed(RunScanner::class, fn (RunScanner $job): bool => $job->scanner === $scanner);
    }
});

test('an unknown scan group is refused', function () {
    $this->artisan('notifications:scan weekly')->assertFailed();
});

test('the scheduler runs the frequent group every fifteen minutes and the daily group at six', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->first(fn ($event) => str_contains($event->command, 'notifications:scan frequent'))->expression)->toBe('*/15 * * * *')
        ->and($events->first(fn ($event) => str_contains($event->command, 'notifications:scan daily'))->expression)->toBe('0 6 * * *');
});

test('a scanner job runs its scanner', function () {
    expect(fn () => (new RunScanner(OffTrackIndicatorScanner::class))->handle())->not->toThrow(Throwable::class);
});

test('php artisan dev also runs the scheduler, so alerts fire locally too', function () {
    expect(collect(DevCommands::commands())->pluck('command'))->toContain('php artisan schedule:work');
});
