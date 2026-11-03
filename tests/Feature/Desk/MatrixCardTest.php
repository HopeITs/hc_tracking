<?php

use App\Enums\Locale;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');

    Activity::withoutRecording(function (): void {
        $late = Project::factory()->create(['name' => 'Late Wells']);
        MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-07-01', 'status' => MonthlyReportStatus::Draft]);
        MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Draft]);
        $waiting = Project::factory()->create(['name' => 'Waiting School']);
        MonthlyReport::factory()->create(['project_id' => $waiting->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Submitted]);
        $fine = Project::factory()->create(['name' => 'Fine Clinic']);
        MonthlyReport::factory()->create(['project_id' => $fine->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Validated]);
    });
});

test('the summary counts late reports and those waiting for validation', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('<b class="dk-num dk-bad">2</b>')
        ->assertSeeHtml('<b class="dk-num">1</b>')
        ->assertSee(['Late Wells', 'Waiting School', 'Fine Clinic']);
});

test('the status filter, applied with Done, shows only the projects with that state, and clearing it shows all again', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertDontSeeHtml('dk-legend')
        ->call('applyFilters', ['state' => 'late'])
        ->assertSet('state', 'late')
        ->assertSee('Late Wells')
        ->assertDontSee('Fine Clinic')
        ->call('applyFilters', ['state' => ''])
        ->assertSet('state', '')
        ->assertSee('Fine Clinic');
});

test('the grid renders the same twelve months at L as at XL, for CSS to fit to the rendered width', function (string $size) {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => $size])
        ->assertSeeHtml('title="October 2025"')
        ->assertSeeHtml('title="September 2026"')
        ->assertDontSeeHtml('--months');
})->with(['L', 'XL']);

test('a late report in an old month marks its project row, so a narrow card that hides the month still shows it', function () {
    Activity::withoutRecording(function (): void {
        $old = Project::factory()->create(['name' => 'Old Late Farm']);
        MonthlyReport::factory()->create(['project_id' => $old->id, 'period' => '2025-11-01', 'status' => MonthlyReportStatus::Draft]);
    });

    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'L'])
        ->assertSeeHtml('<p class="dk-mx-recent">'.__('desk.matrix_latest_months').'</p>')
        ->assertSeeInOrder(['Late Wells', '2 late', 'Old Late Farm', '1 late']);
});

test('a report late before the card\'s twelve months and a report that is not late get no marker', function () {
    Activity::withoutRecording(function (): void {
        $tooOld = Project::factory()->create(['name' => 'Long Gone Farm']);
        MonthlyReport::factory()->create(['project_id' => $tooOld->id, 'period' => '2025-09-01', 'status' => MonthlyReportStatus::Draft]);
    });

    $html = Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'L'])->html();

    expect(substr_count($html, 'dk-mx2-late'))->toBe(1);
});

test('arabic month headers are numbers, because two months share their first letters, and english keeps the letters', function () {
    $viewer = userWithRole('General Manager', insertHrEmployee());

    Livewire::actingAs($viewer)->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('title="October 2025">Oct</span>');

    app()->setLocale(Locale::Arabic->value);
    CarbonImmutable::setLocale(Locale::Arabic->carbonLocale());

    Livewire::actingAs($viewer)->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('title="تشرين الأول 2025">10</span>')
        ->assertSeeHtml('title="تشرين الثاني 2025">11</span>')
        ->assertDontSeeHtml('>تشر</span>');

    CarbonImmutable::setLocale(Locale::English->carbonLocale());
});

test('at S the card lists the reports that need someone, late first', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'S'])
        ->assertDontSeeHtml('class="dk-mx2"')
        ->assertSeeInOrder(['Late Wells', 'July 2026', 'Late Wells', 'August 2026', 'Waiting School'])
        ->assertDontSee('Fine Clinic');
});
