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

test('a new report is unapproved, still editable and unseen by MEAL', function () {
    $user = User::factory()->create();

    $report = DB::table('reports')->find(insertReport(insertPlannedTarget(), $user->id));

    expect((bool) $report->is_approved)->toBeFalse()
        ->and((bool) $report->is_editable)->toBeTrue()
        ->and((bool) $report->is_seen_by_meal)->toBeFalse()
        ->and($report->submit_date)->toBeNull()
        ->and($report->approved_date)->toBeNull()
        ->and($report->edited_date)->toBeNull()
        ->and($report->narrative_report_notes)->toBeNull();
});

test('a reported value keeps its exact figure', function () {
    $user = User::factory()->create();

    $report = DB::table('reports')->find(
        insertReport(insertPlannedTarget(), $user->id, '9999999999.99')
    );

    expect($report->value)->toBe('9999999999.99');
});

test('an approved report records its approval trail and locks', function () {
    $user = User::factory()->create();
    $reportId = insertReport(insertPlannedTarget(), $user->id);

    DB::table('reports')->where('id', $reportId)->update([
        'submit_date' => '2026-04-02',
        'is_approved' => true,
        'approved_date' => '2026-04-05',
        'is_editable' => false,
        'is_seen_by_meal' => true,
    ]);

    $report = DB::table('reports')->find($reportId);

    expect((bool) $report->is_approved)->toBeTrue()
        ->and((bool) $report->is_editable)->toBeFalse()
        ->and((bool) $report->is_seen_by_meal)->toBeTrue()
        ->and($report->submit_date)->toBe('2026-04-02')
        ->and($report->approved_date)->toBe('2026-04-05');
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
