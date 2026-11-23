<?php

use App\Models\Project;
use App\Notifications\Alerts\WorkingCalendar;
use App\Support\HrHolidays;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $schema = Schema::connection('hr');
    $schema->table('holidays', function (Blueprint $table) {
        $table->dropColumn(['holidayable_type', 'holidayable_id']);
        $table->string('scope')->default('national');
        $table->string('kind')->default('official');
    });
    $schema->create('holiday_project', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('holiday_id');
        $table->unsignedBigInteger('program_id');
        $table->unsignedBigInteger('city_id');
    });
});

test('current HR holidays apply only to their exact reporting coverage', function (string $scope, string $kind, ?int $program, ?int $city, bool $expected) {
    $id = DB::connection('hr')->table('holidays')->insertGetId([
        'scope' => $scope, 'kind' => $kind, 'start_date' => '2026-11-16', 'end_date' => '2026-11-17',
    ]);
    if ($program !== null && $city !== null) {
        DB::connection('hr')->table('holiday_project')->insert(['holiday_id' => $id, 'program_id' => $program, 'city_id' => $city]);
    }
    $before = DB::connection('hr')->table('holidays')->get()->toArray();
    $project = new Project(['program_id' => 9, 'city_id' => 2, 'center_id' => 8]);
    $at = CarbonImmutable::parse('2026-11-16');

    expect(HrHolidays::forProject($project)($at))->toBe($expected)
        ->and(HrHolidays::forPlace(new ReportPlace(2, 9, null, true))($at))->toBe($expected)
        ->and(HrHolidays::forProject($project)(CarbonImmutable::parse('2026-11-18')))->toBeFalse();
    expect(DB::connection('hr')->table('holidays')->get()->toArray())->toEqual($before);
})->with([
    'national official' => ['national', 'official', null, null, true],
    'matching official' => ['projects', 'official', 9, 2, true],
    'matching grant' => ['projects', 'grant', 9, 2, true],
    'other program' => ['projects', 'official', 10, 2, false],
    'other city' => ['projects', 'official', 9, 3, false],
    'unassigned project holiday' => ['projects', 'official', null, null, false],
    'personal birthday window' => ['national', 'birthday', null, null, false],
]);

test('national holidays work without a place and recurring ranges span new year', function () {
    DB::connection('hr')->table('holidays')->insert([
        'scope' => 'national', 'kind' => 'official', 'start_date' => '2020-12-30', 'end_date' => '2021-01-02', 'is_recurring' => true,
    ]);
    $isHoliday = HrHolidays::forProject(new Project);

    expect($isHoliday(CarbonImmutable::parse('2026-12-31')))->toBeTrue()
        ->and($isHoliday(CarbonImmutable::parse('2027-01-01')))->toBeTrue()
        ->and($isHoliday(CarbonImmutable::parse('2027-01-03')))->toBeFalse();
});

test('current HR holidays move working deadlines and the last reporting day', function () {
    DB::connection('hr')->table('holidays')->insert([
        ['scope' => 'national', 'kind' => 'official', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03'],
        ['scope' => 'national', 'kind' => 'official', 'start_date' => '2026-11-30', 'end_date' => '2026-11-30'],
    ]);
    $calendar = app(WorkingCalendar::class)->forDays([1, 2, 3, 4, 5])->withHolidays(HrHolidays::forPlace(new ReportPlace(2, 9, 8, false)));

    expect($calendar->addWorkingDays(CarbonImmutable::parse('2026-10-31'), 5)->toDateString())->toBe('2026-11-09')
        ->and($calendar->lastWorkingDayOnOrBefore(CarbonImmutable::parse('2026-11-30'))->toDateString())->toBe('2026-11-27');
});
