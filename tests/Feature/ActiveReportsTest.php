<?php

use App\Enums\ActiveReportType;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\Desk\Panels;
use App\Support\Reporting\ActiveReports;
use App\Support\Reporting\ReportPlace;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Active Reports (M-02): one table of every report still open: monthly
| reports, donor reports and the existing (narrative) reports. Each viewer
| sees only the reports they may open today; permissions are unchanged.
| Open means: monthly draft or submitted, donor report not yet submitted,
| report draft or reviewed.
*/

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    $this->aleppo = insertHrCity('Aleppo');
    $this->mealEmployee = insertHrEmployee(firstNameEn: 'Huda', lastNameEn: 'Meal');
    $this->meal = userWithRole('MEAL', $this->mealEmployee);
    $this->preparerEmployee = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $this->preparer = userWithRole('Public Relations', $this->preparerEmployee);
});

/**
 * One place with a confirmed template, a proposal and the given months.
 *
 * @param  array<string, MonthlyReportStatus>  $months  'Y-m-d' => status
 * @return array{template: ReportTemplate, proposal: Proposal, reports: array<string, MonthlyReport>}
 */
function arpTemplate(int $cityId, string $proposalName, array $months, ?int $responsibleEmployeeId = null): array
{
    return Activity::withoutRecording(function () use ($cityId, $proposalName, $months, $responsibleEmployeeId): array {
        $place = new ReportPlace($cityId, Program::factory()->create()->id, insertHrCenter($cityId, $proposalName.' Center'), false);
        $proposal = Proposal::factory()->create(['name' => $proposalName, 'planned_date_begin' => '2026-07-01', 'planned_date_end' => '2027-01-31']);

        if ($responsibleEmployeeId !== null) {
            $row = ProposalLocation::factory()->create(['proposal_id' => $proposal->id, 'city_id' => $place->cityId, 'program_id' => $place->programId, 'responsible_employee_id' => $responsibleEmployeeId]);
            $row->centers()->create(['center_id' => $place->centerId]);
        }

        $template = ReportTemplate::factory()->confirmed()->forPlace($place, $proposal)->create();
        $reports = [];

        foreach ($months as $period => $status) {
            $factory = match ($status) {
                MonthlyReportStatus::Draft => MonthlyReport::factory(),
                MonthlyReportStatus::Submitted => MonthlyReport::factory()->submitted(),
                MonthlyReportStatus::Validated => MonthlyReport::factory()->validated(),
            };
            $reports[$period] = $factory->create(['project_id' => null, 'report_template_id' => $template->id, 'period' => $period]);
            MonthlyReportLine::factory()->create(['monthly_report_id' => $reports[$period]->id]);
        }

        return ['template' => $template, 'proposal' => $proposal, 'reports' => $reports];
    });
}

/**
 * A donor report on a fresh agreement.
 *
 * @param  array<string, mixed>  $attributes
 */
function arpDonorReport(string $proposalName, array $attributes = [], bool $submitted = false): DonorReport
{
    return Activity::withoutRecording(function () use ($proposalName, $attributes, $submitted): DonorReport {
        $donor = Donor::factory()->create();
        $proposal = Proposal::factory()->running()->create(['donor_id' => $donor->id, 'name' => $proposalName]);
        $agreement = DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $donor->id]);
        $factory = $submitted ? DonorReport::factory()->submitted() : DonorReport::factory();

        return $factory->create(['donor_proposal_id' => $agreement->id, ...$attributes]);
    });
}

/**
 * Everything the MEAL user may see, one open and one closed of each type.
 *
 * @return array<string, mixed>
 */
function arpSeedAllTypes(): array
{
    $place = arpTemplate(test()->aleppo, 'Education for All', [
        '2026-09-01' => MonthlyReportStatus::Validated,
        '2026-10-01' => MonthlyReportStatus::Submitted,
        '2026-11-01' => MonthlyReportStatus::Draft,
    ]);

    return Activity::withoutRecording(fn (): array => [
        'monthlyClosed' => $place['reports']['2026-09-01'],
        'monthlyOpen' => $place['reports']['2026-10-01'],
        'monthlyDraftElsewhere' => $place['reports']['2026-11-01'],
        'donorOpen' => arpDonorReport('Safe Schools', ['title' => 'Q4 narrative', 'due_on' => '2026-11-25', 'responsible_employee_id' => test()->mealEmployee]),
        'donorClosed' => arpDonorReport('Winter Shelter', ['title' => 'Final financial', 'due_on' => '2026-10-01'], submitted: true),
        'reportOpen' => Report::factory()->create(['user_id' => test()->meal->id, 'planned_date' => '2026-11-10']),
        'reportReviewed' => Report::factory()->reviewed()->create(['planned_date' => '2026-11-05']),
        'reportClosed' => Report::factory()->approved()->create(['planned_date' => '2026-10-05']),
        'reportMirror' => Report::factory()->create(['monthly_report_id' => $place['reports']['2026-10-01']->id, 'planned_date' => '2026-10-31']),
    ]);
}

test('guests are sent to sign in and a user without reports.view is refused', function () {
    $this->get(route('active-reports.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('active-reports.index'))->assertForbidden();
    $this->actingAs($this->meal)->get(route('active-reports.index'))->assertOk();
});

test('the door screen offers only the kinds the viewer can open', function () {
    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->assertSet('kind', '')
        ->assertSeeHtml('data-tracker-door="monthly"')
        ->assertSeeHtml('data-tracker-door="duties"')
        ->assertSeeHtml('data-tracker-door="donor"');

    // Only monthly reports: no doors, the monthly view opens with a note saying why.
    Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::active-reports.index')
        ->assertSet('kind', 'monthly')
        ->assertDontSeeHtml('data-tracker-door=')
        ->assertDontSeeHtml('data-tracker-switch=')
        ->assertSeeHtml('data-tracker-single');
});

test('choosing a kind is remembered until logout and going back to the doors forgets it', function () {
    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'donor')
        ->assertSet('kind', 'donor');

    expect(session('active_reports.kind'))->toBe('donor');

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->assertSet('kind', 'donor')
        ->call('backToDoors')
        ->assertSet('kind', '');

    expect(session()->has('active_reports.kind'))->toBeFalse();

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')->assertSet('kind', '');
});

test('door numbers equal the switcher numbers and ignore the search', function () {
    arpSeedAllTypes();
    arpTemplate($this->aleppo, 'Late Place', ['2026-09-01' => MonthlyReportStatus::Draft, '2026-10-01' => MonthlyReportStatus::Draft], $this->mealEmployee);

    $doors = Livewire::actingAs($this->meal)->test('pages::active-reports.index');
    $counts = $doors->instance()->doorCounts;

    expect($counts['duties']['total'])->toBeGreaterThan(0)
        ->and($counts['monthly']['needs'])->toBeGreaterThan(0)
        ->and($counts['donor']['needs'])->toBe(1);

    $doors->assertSeeHtml('data-door-count="'.$counts['monthly']['needs'].'"')
        ->assertSeeHtml('data-door-count="'.$counts['duties']['total'].'"')
        ->call('chooseKind', 'monthly')
        ->set('search', 'nothing matches this')
        ->assertSeeHtml('data-tracker-badge="monthly">'.$counts['monthly']['needs'].'</span>')
        ->assertSeeHtml('data-tracker-badge="duties">'.$counts['duties']['total'].'</span>')
        ->assertSeeHtml('data-tracker-badge="donor">'.$counts['donor']['needs'].'</span>');
});

test('old tab and type links land on the matching kind', function (array $query, string $kind) {
    Livewire::withQueryParams($query)->actingAs($this->meal)->test('pages::active-reports.index')->assertSet('kind', $kind);
})->with([
    'needs me' => [['tab' => 'needs'], 'duties'],
    'all' => [['tab' => 'all'], ''],
    'done' => [['tab' => 'done'], ''],
    'donor type' => [['type' => 'donor'], 'donor'],
    'monthly type' => [['type' => 'monthly'], 'monthly'],
    'unknown kind' => [['kind' => 'bogus'], ''],
]);

test('a kind the viewer cannot open is never shown', function () {
    Livewire::withQueryParams(['kind' => 'donor'])->actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::active-reports.index')
        ->assertSet('kind', 'monthly')
        ->call('chooseKind', 'donor')
        ->assertSet('kind', 'monthly');
});

test('the monthly grid shows each place by month with its state', function () {
    $seed = arpSeedAllTypes();
    $newPlace = arpTemplate($this->aleppo, 'New Place', ['2026-11-01' => MonthlyReportStatus::Draft], $this->mealEmployee);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->assertSeeHtml('data-state="done" data-tracker-cell="monthly-'.$seed['monthlyClosed']->id.'"')
        ->assertSeeHtml('data-state="wait" data-tracker-cell="monthly-'.$seed['monthlyOpen']->id.'"')
        ->assertSeeHtml('data-tracker-place="'.$newPlace['template']->id.'"')
        ->assertSeeHtml('data-state="draft" data-tracker-cell="monthly-'.$newPlace['reports']['2026-11-01']->id.'"')
        ->assertSeeHtml('data-state="none"')
        ->assertSee(route('monthly-reports.show', $seed['monthlyOpen']), false)
        // A draft of somebody else's template is not the validator's to see.
        ->assertDontSeeHtml('data-tracker-cell="monthly-'.$seed['monthlyDraftElsewhere']->id.'"');
});

test('a late month says how late it is and a place two months behind says so', function () {
    $place = arpTemplate($this->aleppo, 'Behind Place', ['2026-08-01' => MonthlyReportStatus::Draft, '2026-09-01' => MonthlyReportStatus::Draft], $this->mealEmployee);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->assertSeeHtml('data-state="late" data-tracker-cell="monthly-'.$place['reports']['2026-08-01']->id.'"')
        ->assertSeeText('2 months behind');
});

test('a chip dims the cells it does not keep and keeps every row', function () {
    $seed = arpSeedAllTypes();

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->call('setChip', 'wait')
        ->assertSet('show', 'wait')
        ->assertSeeHtml('class="rt-cell" data-state="wait" data-tracker-cell="monthly-'.$seed['monthlyOpen']->id.'"')
        ->assertSeeHtml('class="rt-cell is-dim" data-state="done" data-tracker-cell="monthly-'.$seed['monthlyClosed']->id.'"')
        ->call('setChip', 'wait')
        ->assertSet('show', '');
});

test('the program filter and the search narrow the places', function () {
    $first = arpTemplate($this->aleppo, 'Water Wells', ['2026-10-01' => MonthlyReportStatus::Submitted]);
    $second = arpTemplate($this->aleppo, 'School Meals', ['2026-10-01' => MonthlyReportStatus::Submitted]);

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->call('chooseKind', 'monthly');

    $page->set('search', 'water')
        ->assertSeeHtml('data-tracker-place="'.$first['template']->id.'"')
        ->assertDontSeeHtml('data-tracker-place="'.$second['template']->id.'"')
        ->set('search', '')
        ->set('program', (string) $second['template']->program_id)
        ->assertSeeHtml('data-tracker-place="'.$second['template']->id.'"')
        ->assertDontSeeHtml('data-tracker-place="'.$first['template']->id.'"');
});

test('a month range with From after To or longer than twelve months is refused', function () {
    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->call('setRange', '2026-10', '2026-08')
        ->assertHasErrors('from')
        ->assertSet('from', '')
        ->call('setRange', '2025-10', '2026-11')
        ->assertHasErrors('from')
        ->assertSet('from', '')
        ->call('setRange', '2026-08', '2026-10')
        ->assertHasNoErrors()
        ->assertSet('from', '2026-08')
        ->assertSet('to', '2026-10');

    Livewire::withQueryParams(['kind' => 'monthly', 'from' => '2026-10', 'to' => '2026-01'])->actingAs($this->meal)
        ->test('pages::active-reports.index')
        ->assertSet('from', '');
});

test('donor reports sit in lanes by due date', function () {
    $overdue = arpDonorReport('Lane A', ['due_on' => '2026-11-10']);
    $soon = arpDonorReport('Lane B', ['due_on' => '2026-11-30']);
    $later = arpDonorReport('Lane C', ['due_on' => '2027-01-30']);
    $sent = arpDonorReport('Lane D', ['due_on' => '2026-09-20'], submitted: true);
    $old = arpDonorReport('Lane E', ['due_on' => '2025-09-01'], submitted: true);

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->call('chooseKind', 'donor');
    $ids = fn (string $lane): array => array_map(fn ($row) => $row->id, $page->instance()->donorLanes[$lane]);

    expect($ids('overdue'))->toBe([$overdue->id])
        ->and($ids('soon'))->toBe([$soon->id])
        ->and($ids('later'))->toBe([$later->id])
        ->and($ids('submitted'))->toBe([$sent->id]);

    $page->assertSeeHtml('data-tracker-card="donor-'.$overdue->id.'"')
        ->assertSee(route('donor-reports.show', $overdue), false)
        ->assertDontSeeHtml('data-tracker-card="donor-'.$old->id.'"');
});

test('Mine to send keeps only the donor reports the viewer is responsible for', function () {
    $mine = arpDonorReport('Mine', ['due_on' => '2026-12-01', 'responsible_employee_id' => $this->mealEmployee]);
    $theirs = arpDonorReport('Theirs', ['due_on' => '2026-12-02', 'responsible_employee_id' => insertHrEmployee(firstNameEn: 'Other', lastNameEn: 'Person')]);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'donor')
        ->assertSeeHtml('data-tracker-card="donor-'.$theirs->id.'"')
        ->call('setChip', 'mine')
        ->assertSeeHtml('data-tracker-card="donor-'.$mine->id.'"')
        ->assertDontSeeHtml('data-tracker-card="donor-'.$theirs->id.'"');
});

test('the donor filter narrows the lanes', function () {
    $first = arpDonorReport('First', ['due_on' => '2026-12-01']);
    $second = arpDonorReport('Second', ['due_on' => '2026-12-02']);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'donor')
        ->set('donor', (string) $first->donorProposal->donor_id)
        ->assertSeeHtml('data-tracker-card="donor-'.$first->id.'"')
        ->assertDontSeeHtml('data-tracker-card="donor-'.$second->id.'"');
});

test('my reporting duties group what to fill in, check and send', function () {
    $seed = arpSeedAllTypes();
    $mine = arpTemplate($this->aleppo, 'My Place', ['2026-10-01' => MonthlyReportStatus::Draft], $this->mealEmployee);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'duties')
        ->assertSeeHtml('data-tracker-group="fill"')
        ->assertSeeHtml('data-tracker-group="check"')
        ->assertSeeHtml('data-tracker-group="send"')
        ->assertSeeHtml('data-tracker-duty="monthly-'.$mine['reports']['2026-10-01']->id.'"')
        ->assertSeeHtml('data-tracker-duty="monthly-'.$seed['monthlyOpen']->id.'"')
        ->assertSeeHtml('data-tracker-duty="donor-'.$seed['donorOpen']->id.'"');
});

test('open months of one place show as one line that opens the oldest', function () {
    $mine = arpTemplate($this->aleppo, 'Backlog Place', [
        '2026-08-01' => MonthlyReportStatus::Draft,
        '2026-09-01' => MonthlyReportStatus::Draft,
        '2026-10-01' => MonthlyReportStatus::Draft,
    ], $this->mealEmployee);

    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'duties')
        ->assertSeeHtml('data-tracker-duty="monthly-'.$mine['reports']['2026-08-01']->id.'"')
        ->assertDontSeeHtml('data-tracker-duty="monthly-'.$mine['reports']['2026-09-01']->id.'"')
        ->assertSee(route('monthly-reports.show', $mine['reports']['2026-08-01']), false)
        ->assertSeeText('3 months open');
});

test('a preparer sees their started drafts and submitted months, not months still to come', function () {
    $mine = arpTemplate($this->aleppo, 'Child Protection', [
        '2026-10-01' => MonthlyReportStatus::Draft,
        '2026-11-01' => MonthlyReportStatus::Submitted,
        '2026-12-01' => MonthlyReportStatus::Draft,
    ], $this->preparerEmployee);
    $notMine = arpTemplate($this->aleppo, 'Hidden Elsewhere', ['2026-10-01' => MonthlyReportStatus::Draft], insertHrEmployee(firstNameEn: 'Other', lastNameEn: 'Person'));

    Livewire::actingAs($this->preparer)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->assertSeeHtml('data-tracker-cell="monthly-'.$mine['reports']['2026-10-01']->id.'"')
        ->assertSeeHtml('data-tracker-cell="monthly-'.$mine['reports']['2026-11-01']->id.'"')
        ->assertDontSeeHtml('data-tracker-cell="monthly-'.$mine['reports']['2026-12-01']->id.'"')
        ->assertDontSeeHtml('data-tracker-place="'.$notMine['template']->id.'"')
        // Only monthly reports: "Needs me" keeps the preparer's late draft, not the month waiting on MEAL.
        ->call('setChip', 'needs')
        ->assertSeeHtml('class="rt-cell" data-state="late" data-tracker-cell="monthly-'.$mine['reports']['2026-10-01']->id.'"')
        ->assertSeeHtml('class="rt-cell is-dim" data-state="wait" data-tracker-cell="monthly-'.$mine['reports']['2026-11-01']->id.'"');
});

test('a finished report is hidden from anyone who may not open it', function () {
    $seed = arpSeedAllTypes();

    Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::active-reports.index')
        ->assertDontSeeHtml('data-tracker-cell="monthly-'.$seed['monthlyClosed']->id.'"')
        ->assertDontSeeHtml('donor-'.$seed['donorClosed']->id);
});

test('no money is read', function () {
    arpSeedAllTypes();
    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach (['monthly', 'duties', 'donor'] as $kind) {
        Livewire::actingAs($this->meal)->test('pages::active-reports.index')->call('chooseKind', $kind);
    }

    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect($sql)->not->toContain('transfers')->not->toContain('accounts')->not->toContain('`amount')->not->toContain('salary');
});

test('the query count does not grow with the number of reports', function (string $kind) {
    arpSeedAllTypes();
    $count = function () use ($kind): int {
        ActiveReports::forget(); // Measure a cold build, not the cached rows.
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['kind' => $kind])->actingAs($this->meal)->test('pages::active-reports.index');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count(); // Warm the once-per-process lookups (HR directory, schema checks).
    $few = $count();

    foreach (['One', 'Two', 'Three'] as $name) {
        arpTemplate($this->aleppo, 'Extra '.$name, ['2026-10-01' => MonthlyReportStatus::Submitted, '2026-11-01' => MonthlyReportStatus::Submitted]);
        arpDonorReport('Extra donor '.$name, ['due_on' => '2026-12-01']);
    }

    $count(); // Warm again: saving the new programs cleared the once-per-request program lookup.

    expect($count())->toBe($few);
})->with(['doors' => [''], 'monthly' => ['monthly'], 'duties' => ['duties'], 'donor' => ['donor']]);

test('the Board and Portfolio views of the reports register land on Active Reports, keeping the search', function () {
    $this->actingAs($this->meal);

    $this->get(route('reports.index', ['view' => 'board', 'search' => 'water']))->assertRedirect(route('active-reports.index', ['search' => 'water']));
    $this->get(route('reports.index', ['view' => 'portfolio']))->assertRedirect(route('active-reports.index'));
    $this->get(route('reports.index', ['view' => 'calendar']))->assertRedirect(route('active-reports.index'));
    $this->get(route('reports.index'))->assertOk();
    $this->get(route('monthly-reports.index', ['status' => 'all']))->assertOk();
    $this->get(route('donor-reports.index'))->assertOk();
});

test('the reports register has no view switch any more', function () {
    Livewire::actingAs($this->meal)->test('pages::reports.index')
        ->assertDontSeeHtml('data-desk-register-views');
});

test('a monthly row knows its template, whether it was returned and its tracker state', function () {
    $place = arpTemplate($this->aleppo, 'State Check', [
        '2026-07-01' => MonthlyReportStatus::Validated,
        '2026-08-01' => MonthlyReportStatus::Submitted,
        '2026-09-01' => MonthlyReportStatus::Draft,
        '2026-10-01' => MonthlyReportStatus::Draft,
        '2026-11-01' => MonthlyReportStatus::Draft,
    ], $this->mealEmployee);
    $place['reports']['2026-09-01']->forceFill(['returned_at' => '2026-10-20 09:00:00'])->save();

    $reports = app(ActiveReports::class);
    $rows = $reports->for($this->meal)->concat($reports->for($this->meal, closed: true))
        ->where('type', ActiveReportType::Monthly)
        ->keyBy('periodKey');

    expect($rows['2026-07']->state())->toBe('done')
        ->and($rows['2026-08']->state())->toBe('wait')
        ->and($rows['2026-08']->needsCheck)->toBeTrue()
        ->and($rows['2026-09']->returned)->toBeTrue()
        ->and($rows['2026-09']->state())->toBe('returned')
        ->and($rows['2026-10']->state())->toBe('late')
        ->and($rows['2026-11']->state())->toBe('draft')
        ->and($rows['2026-11']->needsCheck)->toBeFalse()
        ->and($rows->pluck('templateId')->unique()->all())->toBe([$place['template']->id]);
});

test('hand-recorded reports are not part of the tracker', function () {
    $seed = arpSeedAllTypes();
    $reports = app(ActiveReports::class);

    expect(ActiveReportType::values())->toBe(['monthly', 'donor'])
        ->and($reports->for($this->meal)->concat($reports->for($this->meal, closed: true))->map->key()->filter(fn (string $key): bool => str_starts_with($key, 'report-'))->all())->toBe([]);
});

test('the monthly grid shows at most six months and steps back through the window', function () {
    $place = arpTemplate($this->aleppo, 'Long Place', ['2026-01-01' => MonthlyReportStatus::Submitted, '2026-11-01' => MonthlyReportStatus::Submitted]);

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->call('setRange', '2026-01', '2026-11');

    expect($page->instance()->visibleMonths)->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11']);
    $page->assertSeeHtml('data-tracker-cell="monthly-'.$place['reports']['2026-11-01']->id.'"')
        ->assertDontSeeHtml('data-tracker-cell="monthly-'.$place['reports']['2026-01-01']->id.'"')
        ->call('shiftMonths', 99)
        ->assertSet('monthShift', 5)
        ->assertSeeHtml('data-tracker-cell="monthly-'.$place['reports']['2026-01-01']->id.'"');
});

test('places are paged and a chip drops the places it keeps nothing of', function () {
    foreach (range(1, 16) as $n) {
        arpTemplate($this->aleppo, sprintf('Paged %02d', $n), ['2026-10-01' => MonthlyReportStatus::Submitted]);
    }
    $validated = arpTemplate($this->aleppo, 'Only Validated', ['2026-10-01' => MonthlyReportStatus::Validated]);

    $page = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->call('chooseKind', 'monthly');

    expect($page->instance()->placePage)->toMatchArray(['total' => 17, 'pages' => 2, 'first' => 1, 'last' => 15]);
    $page->call('goToPage', 2)->assertSet('page', 2)
        ->call('setChip', 'wait')
        ->assertSet('page', 1)
        ->assertDontSeeHtml('data-tracker-place="'.$validated['template']->id.'"');
    expect($page->instance()->placePage['total'])->toBe(16);
});

test('donor reports are grouped by donor with the overdue donor first', function () {
    $calm = arpDonorReport('Calm', ['due_on' => '2027-01-30']);
    $late = arpDonorReport('Late', ['due_on' => '2026-11-10']);

    $groups = Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'donor')
        ->assertSeeHtml('data-tracker-donor="'.$late->donorProposal->donor_id.'"')
        ->instance()->donorGroups;

    expect($groups[0]['key'])->toBe((string) $late->donorProposal->donor_id)
        ->and($groups[0]['overdue'])->toBe(1)
        ->and(collect($groups)->pluck('key'))->toContain((string) $calm->donorProposal->donor_id);
});

test('inside a kind the back link returns to the report kinds instead of the desk', function () {
    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->assertSeeHtml('data-desk-register-back')
        ->call('chooseKind', 'donor')
        ->assertSeeHtml('data-tracker-back')
        ->assertDontSeeHtml('data-desk-register-back')
        ->call('backToDoors')
        ->assertSet('kind', '')
        ->assertSeeHtml('data-desk-register-back');
});

test('the rows are kept between clicks and forgotten when a report changes', function () {
    $place = arpTemplate($this->aleppo, 'Cached Place', ['2026-10-01' => MonthlyReportStatus::Submitted]);
    $report = $place['reports']['2026-10-01'];
    $reads = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($this->meal)->test('pages::active-reports.index')->call('chooseKind', 'monthly');
        $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `monthly_reports`'))->count();
        DB::disableQueryLog();

        return $reads;
    };

    $reads();
    expect($reads())->toBe(0);

    $report->update(['status' => MonthlyReportStatus::Validated]);

    expect($reads())->toBeGreaterThan(0);
    Livewire::actingAs($this->meal)->test('pages::active-reports.index')
        ->call('chooseKind', 'monthly')
        ->assertSeeHtml('data-state="done" data-tracker-cell="monthly-'.$report->id.'"');
});

test('the Desk panel shows the same door numbers as the page and links each door', function () {
    arpSeedAllTypes();
    arpTemplate($this->aleppo, 'Late Place', ['2026-09-01' => MonthlyReportStatus::Draft, '2026-10-01' => MonthlyReportStatus::Draft], $this->mealEmployee);

    $counts = Livewire::actingAs($this->meal)->test('pages::active-reports.index')->instance()->doorCounts;

    Livewire::actingAs($this->meal)->test('desk.panels.tracker', ['size' => 'L'])
        ->assertSeeHtml('data-tracker-panel-door="monthly" data-door-count="'.$counts['monthly']['needs'].'"')
        ->assertSeeHtml('data-tracker-panel-door="duties" data-door-count="'.$counts['duties']['total'].'"')
        ->assertSeeHtml('data-tracker-panel-door="donor" data-door-count="'.$counts['donor']['needs'].'"')
        ->assertSeeHtml(e(route('active-reports.index', ['kind' => 'duties'])));
});

test('the Desk panel offers only the monthly door to a viewer without donor reports', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())->test('desk.panels.tracker', ['size' => 'L'])
        ->assertSeeHtml('data-tracker-panel-door="monthly"')
        ->assertDontSeeHtml('data-tracker-panel-door="donor"')
        ->assertDontSeeHtml('data-tracker-panel-door="duties"');
});

test('Report Tracker goes on the Desk as its own panel, not a quick access button', function () {
    expect(app(Panels::class)->itemForRoute($this->meal, 'active-reports.index'))->toBe('tracker')
        ->and(app(Panels::class)->itemForRoute(User::factory()->create(), 'active-reports.index'))->toBeNull();
});
