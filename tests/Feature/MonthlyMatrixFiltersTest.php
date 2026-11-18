<?php

use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| Y-05 slice 1: the Monthly Matrix filter bar. Proposal · City · Program ·
| Center · From · To are visible; Donor, Indicator, Pillar (and My projects)
| sit behind More filters. Places: a center in Aleppo, Field work in Homs
| (D-88), a place whose center is not set in Homs (D-82).
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');

    Activity::withoutRecording(function (): void {
        $aleppo = insertHrCity('Aleppo');
        $homs = insertHrCity('Homs', 'حمص');
        $program = Program::factory()->create();
        $centerOne = insertHrCenter($aleppo, 'Center One');
        $library = LibraryIndicator::factory()->create();
        $proposal = Proposal::factory()->create([
            'status' => ProposalStatus::Active,
            'actual_date_begin' => '2026-01-01',
            'actual_date_end' => '2026-12-31',
        ]);

        $templates = [];

        foreach ([
            new ReportPlace($aleppo, $program->id, $centerOne, false),
            new ReportPlace($homs, $program->id, null, true),
            new ReportPlace($homs, $program->id, null, false),
        ] as $place) {
            $templates[] = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
            PlanIndicator::factory()->forProposal($proposal)->create([
                'indicator_id' => Indicator::factory()->create(['library_indicator_id' => $library->id, 'measure' => $library->measure])->id,
                'target' => '50',
                'city_id' => $place->cityId,
                'program_id' => $place->programId,
                'center_id' => $place->centerId,
                'city_wide' => $place->cityWide,
            ]);
        }

        $this->mxf = compact('aleppo', 'homs', 'centerOne', 'library', 'program', 'proposal', 'templates');
    });
});

test('the six main filters are visible and the rest sit behind More filters, open only while one is set', function () {
    $html = Livewire::actingAs(userWithRole('MEAL'))->test('pages::reports.monthly-matrix')->html();
    $moreFilters = strpos($html, 'data-matrix-more-filters');

    expect($moreFilters)->not->toBeFalse();

    foreach (['proposal' => 'all_proposals', 'city' => 'all_cities', 'program' => 'all_programs', 'center' => 'all_centers'] as $label => $choice) {
        $picker = e(__('common.filter_choice', ['label' => __('reports.matrix.'.$label), 'choice' => __('reports.matrix.'.$choice)]));

        expect(strpos($html, $picker))->not->toBeFalse()->toBeLessThan($moreFilters);
    }

    expect(strpos($html, 'id="matrix-from"'))->toBeLessThan($moreFilters)
        ->and(strpos($html, 'id="matrix-to"'))->toBeLessThan($moreFilters)
        ->and(strpos($html, __('reports.matrix.donor'), $moreFilters))->not->toBeFalse()
        ->and(strpos($html, __('reports.matrix.pillar'), $moreFilters))->not->toBeFalse()
        ->and(strpos($html, 'data-test="matrix-mine"', $moreFilters))->not->toBeFalse()
        ->and($html)->toContain('type="month"')
        ->toContain('x-data="{ open: false }"')
        ->toContain(__('reports.matrix.more_filters'))
        ->not->toContain('wire:model.live="year"');

    Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['pillar' => '1'])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('x-data="{ open: true }"');
});

test('This year sits in the actions, Whole grant only while one proposal is filtered', function () {
    $proposal = Proposal::query()->firstOrFail();

    Livewire::actingAs(userWithRole('MEAL'))->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('data-test="matrix-this-year"')
        ->assertDontSeeHtml('data-test="matrix-whole-grant"');

    Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['proposal' => (string) $proposal->id])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('data-test="matrix-whole-grant"');
});

test('the center picker lists center · city, Field work · city and Center not set, never merged', function () {
    $labels = collect(Livewire::actingAs(userWithRole('MEAL'))->test('pages::reports.monthly-matrix')
        ->assertSee('Center One · Aleppo')
        ->assertSee('Field work · Homs')
        ->assertSee('Center not set')
        ->instance()->centerOptions)->pluck('label', 'value');

    expect($labels->get('c'.$this->mxf['centerOne']))->toBe('Center One · Aleppo')
        ->and($labels->get('field-'.$this->mxf['homs']))->toBe('Field work · Homs')
        ->and($labels->get('unset'))->toBe('Center not set');
});

test('a row shows its place and city and never links to a project', function () {
    Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['center' => 'c'.$this->mxf['centerOne']])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('data-matrix-place')
        ->assertSee('Center One')
        ->assertDontSeeHtml('/projects/');
});

test('a From after To is refused with a field error next to From', function () {
    Livewire::actingAs(userWithRole('MEAL'))->test('pages::reports.monthly-matrix')
        ->set('from', '2026-09')
        ->set('to', '2026-07')
        ->assertHasErrors('from')
        ->assertSeeHtml('data-matrix-from-error')
        ->assertSee(__('reports.matrix.from_after_to'));
});

test('an empty result names the filter that emptied it and Reset filters clears it', function () {
    $unused = LibraryIndicator::factory()->create();

    $page = Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['indicator' => (string) $unused->id])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('data-matrix-empty')
        ->assertSee(__('reports.matrix.empty_by_filter', ['filter' => __('reports.matrix.indicator')]))
        ->assertSeeHtml('data-test="matrix-reset-filters"');

    $page->call('clearFilters')
        ->assertSet('indicator', '')
        ->assertDontSeeHtml('data-matrix-empty');

    expect($page->instance()->matrix['rows'])->not->toBeEmpty();
});

/**
 * A validated October report of the template with one line of the library
 * entry.
 */
function mxfReport(ReportTemplate $template, LibraryIndicator $library): void
{
    Activity::withoutRecording(function () use ($template, $library): void {
        $report = MonthlyReport::factory()->validated()->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => '2026-10-01']);
        MonthlyReportLine::factory()->create(['monthly_report_id' => $report->id, 'library_indicator_id' => $library->id, 'value' => 7]);
    });
}

/**
 * The query of each cell link the page renders.
 *
 * @return list<array<string, string>>
 */
function mxfCellLinks(string $html): array
{
    preg_match_all('/<a[^>]*data-matrix-cell-link[^>]*>/', $html, $anchors);

    return array_map(function (string $anchor): array {
        preg_match('/href="([^"]*)"/', $anchor, $href);
        parse_str((string) parse_url(html_entity_decode($href[1]), PHP_URL_QUERY), $query);

        return $query;
    }, $anchors[0]);
}

test('a month cell with a report links to that month of the monthly-reports list at the place of its row', function () {
    mxfReport($this->mxf['templates'][0], $this->mxf['library']);

    $html = Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['proposal' => (string) $this->mxf['proposal']->id, 'from' => '2026-10', 'to' => '2026-10'])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('data-matrix-cell-link')
        ->assertSeeHtml('<span class="sr-only">'.e(__('reports.matrix.open_month_list', ['month' => CarbonImmutable::create(2026, 10, 1)->translatedFormat(__('formats.month_year'))])).'</span>')
        ->html();

    expect(mxfCellLinks($html))->toBe([[
        'proposal' => (string) $this->mxf['proposal']->id,
        'city' => (string) $this->mxf['aleppo'],
        'program' => (string) $this->mxf['program']->id,
        'center' => 'c'.$this->mxf['centerOne'],
        'from' => '2026-10',
        'to' => '2026-10',
        'status' => 'all',
    ]])->and($html)->toContain(route('monthly-reports.index'));
});

test('a cell of a place whose center is not set links with center=unset', function () {
    mxfReport($this->mxf['templates'][2], $this->mxf['library']);

    $links = mxfCellLinks(Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['from' => '2026-10', 'to' => '2026-10'])
        ->test('pages::reports.monthly-matrix')
        ->html());

    expect($links)->toBe([[
        'city' => (string) $this->mxf['homs'],
        'program' => (string) $this->mxf['program']->id,
        'center' => 'unset',
        'from' => '2026-10',
        'to' => '2026-10',
        'status' => 'all',
    ]]);
});

test('a cell with no report has no link', function () {
    Livewire::actingAs(userWithRole('MEAL'))
        ->withQueryParams(['from' => '2026-10', 'to' => '2026-10'])
        ->test('pages::reports.monthly-matrix')
        ->assertSeeHtml('data-matrix-cell="not_submitted"')
        ->assertDontSeeHtml('data-matrix-cell-link');
});
