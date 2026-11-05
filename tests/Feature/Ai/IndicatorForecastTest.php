<?php

declare(strict_types=1);

use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Support\Ai\IndicatorForecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/*
| The indicator forecast (AI trial, step 8): a straight-line trend through
| the readings, read at the end of the window and against the target.
*/

/**
 * @param  array<string, float>  $readings  date => value, oldest first
 * @return Collection<int, IndicatorProgress>
 */
function readings(array $readings): Collection
{
    return collect($readings)->map(fn (float $value, string $date): IndicatorProgress => IndicatorProgress::factory()->make(['date' => $date, 'value' => $value]))->values();
}

$yearEnd = fn (): CarbonImmutable => CarbonImmutable::parse('2026-12-31');

test('a steady climb that crosses the target in time reaches it, with the date', function () use ($yearEnd) {
    $forecast = IndicatorForecast::for(readings(['2026-01-01' => 10, '2026-04-01' => 40, '2026-07-01' => 70]), 0, 100, $yearEnd());

    expect($forecast['state'])->toBe('reaches')
        ->and($forecast['reaches_on']->format('Y-m'))->toBe('2026-09')
        ->and($forecast['projected_percent'])->toBeGreaterThan(100.0)
        ->and($forecast['per_month'])->toBeGreaterThan(9.0)->toBeLessThan(11.0);
});

test('a slow climb falls short and says how far it gets', function () use ($yearEnd) {
    $forecast = IndicatorForecast::for(readings(['2026-01-01' => 10, '2026-04-01' => 13, '2026-07-01' => 16]), 0, 100, $yearEnd());

    expect($forecast['state'])->toBe('falls_short')
        ->and($forecast['reaches_on'])->toBeNull()
        ->and($forecast['projected'])->toBeGreaterThan(20.0)->toBeLessThan(23.0);
});

test('falling readings on a rising target are moving away; a reduction target reads the other way', function () use ($yearEnd) {
    $falling = readings(['2026-01-01' => 50, '2026-04-01' => 40, '2026-07-01' => 30]);

    expect(IndicatorForecast::for($falling, 0, 100, $yearEnd())['state'])->toBe('moving_away')
        ->and(IndicatorForecast::for($falling, 60, 10, $yearEnd())['state'])->toBe('reaches');
});

test('readings that do not move are flat', function () use ($yearEnd) {
    expect(IndicatorForecast::for(readings(['2026-01-01' => 20, '2026-04-01' => 20, '2026-07-01' => 20]), 0, 100, $yearEnd())['state'])->toBe('flat');
});

test('no forecast without a target or with fewer than three readings', function () use ($yearEnd) {
    expect(IndicatorForecast::for(readings(['2026-01-01' => 10, '2026-04-01' => 40]), 0, 100, $yearEnd()))->toBeNull()
        ->and(IndicatorForecast::for(readings(['2026-01-01' => 10, '2026-04-01' => 40, '2026-07-01' => 70]), 0, null, $yearEnd()))->toBeNull();
});

test('the indicator inspector shows the forecast once there are three readings', function () {
    $this->travelTo('2026-07-15 10:00:00');
    $user = userWithRole('General Manager');
    $indicator = Indicator::factory()->create(['baseline' => 0, 'target' => 100]);

    foreach (['2026-01-01' => 10, '2026-04-01' => 40] as $date => $value) {
        IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => $date, 'value' => $value, 'recorded_by' => $user->id]);
    }

    $page = Livewire::actingAs($user)->test('pages::indicators.index')->call('openInspectModal', $indicator->id);
    $page->assertDontSeeText(__('ai.forecast.heading'));

    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'date' => '2026-07-01', 'value' => 70, 'recorded_by' => $user->id]);

    Livewire::actingAs($user)->test('pages::indicators.index')->call('openInspectModal', $indicator->id)
        ->assertSeeText(__('ai.forecast.heading'))
        ->assertSeeHtml('data-indicator-forecast="reaches"');
});
