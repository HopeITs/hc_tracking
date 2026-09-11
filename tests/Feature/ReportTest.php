<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Insert a planned target and return its id, wiring up the result and indicator
 * it hangs from.
 */
function insertPlannedTarget(): int
{
    return insertPlanIndicator(insertIndicator(insertResult()), 'yearly_plan', 1);
}

test('a new report is a draft, unseen by MEAL, with no narrative written', function () {
    $user = User::factory()->create();

    $report = DB::table('reports')->find(insertReport(insertPlannedTarget(), $user->id));

    expect($report->status)->toBe('draft')
        ->and((bool) $report->is_seen_by_meal)->toBeFalse()
        ->and($report->submit_date)->toBeNull()
        ->and($report->narrative_report_notes)->toBeNull()
        ->and($report->period_start)->toBeNull()
        ->and($report->period_end)->toBeNull()
        ->and($report->executive_summary)->toBeNull()
        ->and($report->key_achievements)->toBeNull()
        ->and($report->challenges)->toBeNull()
        ->and($report->lessons_learned)->toBeNull()
        ->and($report->next_period_plans)->toBeNull();
});

test('a reported value keeps its exact figure', function () {
    $user = User::factory()->create();

    $report = DB::table('reports')->find(
        insertReport(insertPlannedTarget(), $user->id, '9999999999.99')
    );

    expect($report->value)->toBe('9999999999.99');
});

test('an approved report records its submission and MEAL review', function () {
    $user = User::factory()->create();
    $reportId = insertReport(insertPlannedTarget(), $user->id);

    DB::table('reports')->where('id', $reportId)->update([
        'status' => 'approved',
        'submit_date' => '2026-04-02',
        'is_seen_by_meal' => true,
    ]);

    $report = DB::table('reports')->find($reportId);

    expect($report->status)->toBe('approved')
        ->and((bool) $report->is_seen_by_meal)->toBeTrue()
        ->and($report->submit_date)->toBe('2026-04-02');
});

test('the narrative sections a donor report is made of are stored verbatim', function () {
    $user = User::factory()->create();
    $reportId = insertReport(insertPlannedTarget(), $user->id);

    DB::table('reports')->where('id', $reportId)->update([
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'executive_summary' => 'Reached 82% of the quarterly target.',
        'key_achievements' => 'Two boreholes rehabilitated ahead of schedule.',
        'challenges' => 'Fuel shortages halted pumping for eleven days.',
        'lessons_learned' => 'A pre-positioned fuel reserve would have absorbed the outage.',
        'next_period_plans' => 'Extend the network to two further neighbourhoods.',
    ]);

    $report = DB::table('reports')->find($reportId);

    expect($report->period_start)->toBe('2026-01-01')
        ->and($report->period_end)->toBe('2026-03-31')
        ->and($report->executive_summary)->toBe('Reached 82% of the quarterly target.')
        ->and($report->key_achievements)->toBe('Two boreholes rehabilitated ahead of schedule.')
        ->and($report->challenges)->toBe('Fuel shortages halted pumping for eleven days.')
        ->and($report->lessons_learned)->toBe('A pre-positioned fuel reserve would have absorbed the outage.')
        ->and($report->next_period_plans)->toBe('Extend the network to two further neighbourhoods.');
});

test('deleting a planned target deletes the actuals reported against it', function () {
    $user = User::factory()->create();
    $planId = insertPlannedTarget();
    insertReport($planId, $user->id);

    DB::table('plan_indicators')->where('id', $planId)->delete();

    $this->assertDatabaseCount('reports', 0);
});

test('a user is not deletable while their submitted reports remain', function () {
    $officer = User::factory()->create();
    insertReport(insertPlannedTarget(), $officer->id);

    expect(fn () => DB::table('users')->where('id', $officer->id)->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('users', ['id' => $officer->id]);
});
