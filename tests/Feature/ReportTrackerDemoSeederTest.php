<?php

use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Report;
use Database\Seeders\ReportTrackerDemoSeeder;

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    // A monthly report already exists, so the matrix seeder is not pulled in.
    MonthlyReport::factory()->create(['project_id' => null]);
    $this->meal = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal'));
});

test('the Report Tracker demo seeder adds open and finished donor and narrative reports, once', function () {
    DonorProposal::factory()->create();
    PlanIndicator::factory()->create();

    $this->seed(ReportTrackerDemoSeeder::class);
    $this->seed(ReportTrackerDemoSeeder::class);

    expect(DonorReport::query()->count())->toBe(3)
        ->and(DonorReport::query()->whereNull('submitted_on')->count())->toBe(2)
        ->and(DonorReport::query()->whereNull('submitted_on')->pluck('responsible_employee_id')->unique()->all())->toBe([$this->meal->employee_id])
        ->and(Report::query()->count())->toBe(4)
        ->and(Report::query()->pluck('status')->map->value->sort()->values()->all())->toBe(['approved', 'draft', 'draft', 'reviewed']);
});
