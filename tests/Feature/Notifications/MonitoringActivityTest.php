<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Result;
use App\Models\Risk;
use App\Models\UserNotification;

test('a risk that happens is critical for the owner, managers, meal and the top roles', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $meal = userWithRole('MEAL');
    $generalManager = userWithRole('General Manager');
    $risk = Activity::withoutRecording(fn () => Risk::factory()->create([
        'project_id' => Project::factory()->create(['user_id' => $ownerEmployee])->id,
        'name' => 'Road closure',
    ]));

    $this->actingAs(userWithRole('Managers'));
    $risk->update(['happened' => true]);

    foreach ([$owner, $meal, $generalManager] as $recipient) {
        expect(UserNotification::query()->forUser($recipient)->sole()->priority)->toBe(NotificationPriority::Critical);
    }

    expect(sentencesFor($meal)[0])->toEndWith('marked the risk “Road closure” as happened');
});

test('a risk returning to potential is an ordinary update', function () {
    $risk = Activity::withoutRecording(fn () => Risk::factory()->create(['happened' => true]));

    $this->actingAs(userWithRole('Managers'));
    $risk->update(['happened' => false]);

    expect(Activity::query()->sole()->event)->toBe('risk.updated');
});

test('meal hears about indicator targets and readings; a reading is recorded on its indicator', function () {
    $meal = userWithRole('MEAL');
    $indicator = Activity::withoutRecording(fn () => Indicator::factory()->create(['name' => 'People reached', 'target' => '100']));
    $recorder = userWithRole('MEAL');

    $this->actingAs($recorder);
    $indicator->update(['target' => '150']);
    $this->travel(6)->minutes();
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '40', 'recorded_by' => $recorder->id]);

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['indicator.target_changed', 'indicator.progress_recorded'])
        ->and(sentencesFor($meal))->toHaveCount(2)
        ->and(sentencesFor($meal)[1])->toContain('recorded 40 for “People reached”');
});

test('a target change on a plan indicator reaches meal as a targets notification', function () {
    $meal = userWithRole('MEAL');
    $target = Activity::withoutRecording(fn () => PlanIndicator::factory()->create());

    $this->actingAs(userWithRole('Managers'));
    $target->update(['target' => '999.00']);

    expect(UserNotification::query()->forUser($meal)->sole()->module)->toBe('targets');
});

test('results are watched by meal', function () {
    $meal = userWithRole('MEAL');

    $this->actingAs(userWithRole('Managers'));
    Result::factory()->create(['statement' => 'Improved access to water']);

    expect(sentencesFor($meal))->toHaveCount(1);
});
