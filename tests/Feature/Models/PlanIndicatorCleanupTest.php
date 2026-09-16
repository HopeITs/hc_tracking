<?php

use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportFile;

test('deleting a annual plan deletes the targets committed in it', function () {
    $plan = AnnualPlan::factory()->create();
    $target = PlanIndicator::factory()->forAnnualPlan($plan)->create();
    $survivor = PlanIndicator::factory()->create();

    $plan->delete();

    $this->assertDatabaseMissing('plan_indicators', ['id' => $target->id]);
    $this->assertDatabaseHas('plan_indicators', ['id' => $survivor->id]);
});

test('deleting a proposal deletes the targets committed in it', function () {
    $proposal = Proposal::factory()->create();
    $target = PlanIndicator::factory()->forProposal($proposal)->create();
    $survivor = PlanIndicator::factory()->create();

    $proposal->delete();

    $this->assertDatabaseMissing('plan_indicators', ['id' => $target->id]);
    $this->assertDatabaseHas('plan_indicators', ['id' => $survivor->id]);
});

test('deleting a program reaches the targets planned in its annual plans', function () {
    $program = Program::factory()->create();
    $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
    $target = PlanIndicator::factory()->forAnnualPlan($plan)->create();

    $program->delete();

    $this->assertDatabaseCount('annual_plans', 0);
    $this->assertDatabaseMissing('plan_indicators', ['id' => $target->id]);
});

test('cleaning up a target takes its reports and their files with it', function () {
    $plan = AnnualPlan::factory()->create();
    $target = PlanIndicator::factory()->forAnnualPlan($plan)->create();
    $report = Report::factory()->create(['plan_indicator_id' => $target->id]);
    $file = ReportFile::factory()->create(['report_id' => $report->id]);

    $plan->delete();

    $this->assertDatabaseMissing('plan_indicators', ['id' => $target->id]);
    $this->assertDatabaseMissing('reports', ['id' => $report->id]);
    $this->assertDatabaseMissing('report_files', ['id' => $file->id]);
});

test('deleting one source leaves targets planned against a different source alone', function () {
    $doomed = AnnualPlan::factory()->create();
    $kept = AnnualPlan::factory()->create();

    $doomedTarget = PlanIndicator::factory()->forAnnualPlan($doomed)->create();
    $keptTarget = PlanIndicator::factory()->forAnnualPlan($kept)->create();

    $doomed->delete();

    $this->assertDatabaseMissing('plan_indicators', ['id' => $doomedTarget->id]);
    $this->assertDatabaseHas('plan_indicators', ['id' => $keptTarget->id]);
});

test('a target is matched on source_type as well as source_id', function () {
    $plan = AnnualPlan::factory()->create();

    $planTarget = PlanIndicator::factory()->forAnnualPlan($plan)->create();
    $proposalTarget = PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => $plan->id,
    ]);

    $plan->delete();

    $this->assertDatabaseMissing('plan_indicators', ['id' => $planTarget->id]);
    $this->assertDatabaseHas('plan_indicators', ['id' => $proposalTarget->id]);
});

test('deleting a program cleans the targets of every one of its annual plans', function () {
    $program = Program::factory()->create();

    $targets = collect([2026, 2027, 2028, 2029, 2030])->map(function (int $year) use ($program) {
        $plan = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => $year]);

        return PlanIndicator::factory()->forAnnualPlan($plan)->create()->id;
    });

    $program->delete();

    $this->assertDatabaseCount('annual_plans', 0);

    foreach ($targets as $id) {
        $this->assertDatabaseMissing('plan_indicators', ['id' => $id]);
    }
});
