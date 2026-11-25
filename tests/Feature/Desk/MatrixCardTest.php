<?php

use App\Enums\Locale;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Support\Reporting\ReportPlace;
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

test('the grid renders January to December of the year at L as at XL, for CSS to fit to the rendered width', function (string $size) {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => $size])
        ->assertSeeHtml('title="January 2026"')
        ->assertSeeHtml('title="December 2026"')
        ->assertDontSeeHtml('title="December 2025"')
        ->assertDontSeeHtml('--months');
})->with(['L', 'XL']);

test('a late report in an old month marks its project row, so a narrow card that hides the month still shows it', function () {
    Activity::withoutRecording(function (): void {
        $old = Project::factory()->create(['name' => 'Old Late Farm']);
        MonthlyReport::factory()->create(['project_id' => $old->id, 'period' => '2026-01-01', 'status' => MonthlyReportStatus::Draft]);
    });

    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'L'])
        ->assertSeeHtml('<p class="dk-mx-recent">'.__('desk.matrix_latest_months').'</p>')
        ->assertSeeInOrder(['Late Wells', '2 late'])
        ->assertSeeInOrder(['Old Late Farm', '1 late']);
});

test('a report late before the card\'s year and a report that is not late get no marker', function () {
    Activity::withoutRecording(function (): void {
        $tooOld = Project::factory()->create(['name' => 'Long Gone Farm']);
        MonthlyReport::factory()->create(['project_id' => $tooOld->id, 'period' => '2025-12-01', 'status' => MonthlyReportStatus::Draft]);
    });

    $html = Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'L'])->html();

    expect(substr_count($html, 'dk-mx2-late'))->toBe(1);
});

test('arabic month headers are numbers, because two months share their first letters, and english keeps the letters', function () {
    $viewer = userWithRole('General Manager', insertHrEmployee());

    Livewire::actingAs($viewer)->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('title="January 2026">Jan</span>');

    app()->setLocale(Locale::Arabic->value);
    CarbonImmutable::setLocale(Locale::Arabic->carbonLocale());

    Livewire::actingAs($viewer)->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('title="تشرين الأول 2026">10</span>')
        ->assertSeeHtml('title="تشرين الثاني 2026">11</span>')
        ->assertDontSeeHtml('>تشر</span>');

    CarbonImmutable::setLocale(Locale::English->carbonLocale());
});

test('at S the card lists the reports that need someone, late first', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'S'])
        ->assertDontSeeHtml('class="dk-mx2"')
        ->assertSeeInOrder(['Late Wells', 'July 2026', 'Late Wells', 'August 2026', 'Waiting School'])
        ->assertDontSee('Fine Clinic');
});

/*
| Y-03 slice 3 (D-76): a row is a report template, named by its place and the
| proposals it covers, so two reports of one place are told apart.
*/

function mxPlacedTemplate(ReportPlace $place, string $proposalName, string $status = 'draft'): ReportTemplate
{
    return Activity::withoutRecording(function () use ($place, $proposalName, $status): ReportTemplate {
        $proposal = Proposal::factory()->create(['name' => $proposalName]);
        $template = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
        MonthlyReport::factory()->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::from($status)]);

        return $template;
    });
}

test('two templates of one place are two rows, told apart by their proposals', function () {
    $city = insertHrCity('Homs');
    $place = new ReportPlace($city, Activity::withoutRecording(fn (): int => Program::factory()->create()->id), insertHrCenter($city, 'Zone Center'), false);
    $education = mxPlacedTemplate($place, 'Education for All');
    $protection = mxPlacedTemplate($place, 'Child Protection', 'submitted');

    $html = Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('data-matrix-template="'.$education->id.'"')
        ->assertSeeHtml('data-matrix-template="'.$protection->id.'"')
        ->html();

    $educationRow = strpos($html, 'data-matrix-template="'.$education->id.'"');
    $protectionRow = strpos($html, 'data-matrix-template="'.$protection->id.'"');

    expect(substr($html, $educationRow, 900))->toContain('Zone Center')->toContain('Education for All')->not->toContain('Child Protection')
        ->and(substr($html, $protectionRow, 900))->toContain('Zone Center')->toContain('Child Protection')->not->toContain('Education for All');
});

test('the card takes a fixed number of queries whatever the number of templates', function () {
    $viewer = userWithRole('General Manager', insertHrEmployee());
    $count = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::connection('hr')->flushQueryLog();
        DB::enableQueryLog();
        DB::connection('hr')->enableQueryLog();
        Livewire::actingAs($viewer)->test('desk.panels.matrix', ['size' => 'XL']);
        $queries = count(DB::getQueryLog()) + count(DB::connection('hr')->getQueryLog());
        DB::disableQueryLog();
        DB::connection('hr')->disableQueryLog();

        return $queries;
    };

    // One placed pair first: a template without a legacy project adds the bridge's fixed lookup once.
    $city = insertHrCity('Homs');
    $programId = Activity::withoutRecording(fn (): int => Program::factory()->create()->id);
    $first = new ReportPlace($city, $programId, insertHrCenter($city, 'Center 0'), false);
    mxPlacedTemplate($first, 'Education 0');
    mxPlacedTemplate($first, 'Protection 0');

    $count();
    $few = $count();

    foreach (range(1, 3) as $index) {
        $place = new ReportPlace($city, $programId, insertHrCenter($city, 'Center '.$index), false);
        mxPlacedTemplate($place, 'Education '.$index);
        mxPlacedTemplate($place, 'Protection '.$index);
    }

    $count();

    expect($count())->toBe($few);
});
