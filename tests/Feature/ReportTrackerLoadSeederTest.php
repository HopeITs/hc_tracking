<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\ReportTemplate;
use App\Models\User;
use Database\Seeders\ReportTrackerLoadSeeder;

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
});

test('the load seeder fills places with twelve months and many donor reports, once', function () {
    $owner = userWithRole('Super Admin', insertHrEmployee(firstNameEn: 'Owner', lastNameEn: 'One'));
    User::factory()->create();
    Program::factory()->create();
    Donor::factory()->create();
    $city = insertHrCity('Aleppo');
    insertHrCenter($city, 'First Center');
    insertHrCenter($city, 'Second Center');

    $this->seed(ReportTrackerLoadSeeder::class);
    $this->seed(ReportTrackerLoadSeeder::class);

    $monthly = MonthlyReport::query()->get();

    expect(ReportTemplate::query()->count())->toBe(2)
        ->and($monthly)->toHaveCount(24)
        ->and($monthly->pluck('status')->unique()->map->value->sort()->values()->all())->toContain('draft', 'submitted')
        ->and($monthly->where('status', MonthlyReportStatus::Submitted)->pluck('submitted_by')->unique()->all())->not->toContain($owner->id)
        ->and(DonorReport::query()->count())->toBe(60)
        ->and(DonorReport::query()->where('responsible_employee_id', $owner->employee_id)->count())->toBe(20);
});
