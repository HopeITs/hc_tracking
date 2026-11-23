<?php

use App\Actions\ReportTemplates\CreateReportTemplateForProposal;
use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplatePlacement;
use App\Models\User;
use App\Support\Reporting\ReportPlace;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| MEAL's "Templates to confirm" queue (contracts.md §12.4, T33 slice 3): the
| pending templates, and confirmed templates with pending lines, of the
| projects the user covers; every project for the MEAL Manager slot holder.
*/

/**
 * A template on a new approved project, with one line per given status, each
 * for a target of the named proposal.
 *
 * @param  list<ReportTemplateLineStatus>  $lineStatuses
 * @param  array<string, mixed>  $projectAttributes
 */
function rtqTemplate(string $projectName, bool $confirmed = false, array $lineStatuses = [ReportTemplateLineStatus::Pending], string $proposalName = 'Winter WASH', array $projectAttributes = []): ReportTemplate
{
    return Activity::withoutRecording(function () use ($projectName, $confirmed, $lineStatuses, $proposalName, $projectAttributes): ReportTemplate {
        $project = Project::factory()->approved()->create(['name' => $projectName, ...$projectAttributes]);
        $proposal = Proposal::factory()->create(['name' => $proposalName]);
        $template = ReportTemplate::factory()
            ->when($confirmed, fn ($factory) => $factory->confirmed())
            ->create(['project_id' => $project->id]);

        foreach ($lineStatuses as $status) {
            $target = PlanIndicator::factory()->forProposal($proposal)->create(['project_id' => $project->id]);

            ReportTemplateLine::factory()
                ->when($status === ReportTemplateLineStatus::Confirmed, fn ($factory) => $factory->confirmed())
                ->when($status === ReportTemplateLineStatus::Retired, fn ($factory) => $factory->retired())
                ->create(['report_template_id' => $template->id, 'plan_indicator_id' => $target->id]);
        }

        return $template;
    });
}

function rtqCover(int $projectId, int $employeeId): void
{
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($projectId)->create(['employee_id' => $employeeId]));
}

test('the queue is refused without report-templates.manage', function () {
    $this->actingAs(User::factory()->role('Team Member')->create())
        ->get(route('report-templates.index'))
        ->assertForbidden();
});

test('a responsible MEAL officer sees only the pending templates of the projects they cover', function () {
    holdMealManagerSlot();
    $officer = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Nour'));
    $covered = rtqTemplate('Aleppo Literacy');
    rtqTemplate('Homs Nutrition');
    $covered->forceFill(['center_id' => null, 'city_wide' => true])->save();
    $place = $covered->place();
    DB::table('meal_coverages')->insert([
        'scope_type' => 'place',
        'scope_key' => 'place:'.$place->key(),
        'city_id' => $place->cityId,
        'program_id' => $place->programId,
        'city_wide' => true,
        'employee_id' => $officer->employee_id,
    ]);

    $this->actingAs($officer)
        ->get(route('report-templates.index'))
        ->assertOk()
        ->assertSee('data-report-template-queue', false)
        ->assertSee('Aleppo Literacy')
        ->assertDontSee('Homs Nutrition')
        ->assertSee(route('projects.show', [$covered->project, 'tab' => 'template']), false);
});

test('the MEAL Manager slot holder sees every project', function () {
    $manager = mealManager();
    $first = rtqTemplate('Aleppo Literacy');
    rtqTemplate('Homs Nutrition');
    rtqCover($first->project_id, insertHrEmployee(firstNameEn: 'Nour'));

    $this->actingAs($manager)
        ->get(route('report-templates.index'))
        ->assertOk()
        ->assertSee('Aleppo Literacy')
        ->assertSee('Homs Nutrition');
});

test('a pending template of a closed project is not listed', function () {
    rtqTemplate('Aleppo Literacy');
    rtqTemplate('Homs Nutrition', projectAttributes: ['status' => ProjectStatus::Closed]);

    $this->actingAs(mealManager())
        ->get(route('report-templates.index'))
        ->assertOk()
        ->assertSee('Aleppo Literacy')
        ->assertDontSee('Homs Nutrition');
});

test('a confirmed template is listed only while it has pending lines, with their count and proposals', function () {
    $manager = mealManager();
    rtqTemplate('Settled Project', confirmed: true, lineStatuses: [ReportTemplateLineStatus::Confirmed, ReportTemplateLineStatus::Retired]);
    $grown = rtqTemplate('Growing Project', confirmed: true, lineStatuses: [ReportTemplateLineStatus::Confirmed, ReportTemplateLineStatus::Pending, ReportTemplateLineStatus::Pending], proposalName: 'Spring Hygiene');

    Livewire::actingAs($manager)
        ->test('pages::report-templates.index')
        ->assertDontSee('Settled Project')
        ->assertSee('Growing Project')
        ->assertSee('Spring Hygiene')
        ->assertSeeHtml('data-pending-lines="2"')
        ->assertSee('Lines added');
    expect($grown->fresh()->status->value)->toBe('confirmed');
});

test('an empty queue says so', function () {
    Livewire::actingAs(mealManager())
        ->test('pages::report-templates.index')
        ->assertSee('No templates to confirm');
});

test('the city filter keeps the projects of that governorate', function () {
    $manager = mealManager();
    $aleppo = insertHrCity('Aleppo', 'حلب');
    rtqTemplate('Aleppo Literacy', projectAttributes: ['city_id' => $aleppo]);
    rtqTemplate('Homs Nutrition', projectAttributes: ['city_id' => insertHrCity('Homs', 'حمص')]);

    Livewire::actingAs($manager)
        ->withQueryParams(['city' => (string) $aleppo])
        ->test('pages::report-templates.index')
        ->assertSee('Aleppo Literacy')
        ->assertDontSee('Homs Nutrition');
});

test('the queue runs a fixed number of queries however many templates it lists', function () {
    $manager = mealManager();

    $countQueries = function () use ($manager): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::actingAs($manager)->test('pages::report-templates.index');

        return $queries;
    };

    rtqTemplate('Project One');
    $countQueries();
    $small = $countQueries();

    rtqTemplate('Project Two', proposalName: 'Second proposal');
    rtqTemplate('Project Three', confirmed: true, lineStatuses: [ReportTemplateLineStatus::Confirmed, ReportTemplateLineStatus::Pending], proposalName: 'Third proposal');
    $large = $countQueries();

    expect($large)->toBe($small);
});

test('the queue renders in Arabic', function () {
    $manager = mealManager();
    $manager->forceFill(['locale' => 'ar'])->save();
    rtqTemplate('Aleppo Literacy');

    $this->actingAs($manager)
        ->get(route('report-templates.index'))
        ->assertOk()
        ->assertSee('قوالب بانتظار التأكيد')
        ->assertSee('الأسطر المعلّقة');
});

test('the sidebar links to the queue only for report-templates.manage holders', function () {
    $link = 'href="'.route('report-templates.index').'"';

    $this->actingAs(userWithRole('MEAL'))->get(route('dashboard'))->assertOk()->assertSee($link, false);
    $this->actingAs(User::factory()->role('Team Member')->create())->get(route('dashboard'))->assertOk()->assertDontSee($link, false);
});

test('the two status buttons count what a click shows, and pressing one again clears it', function () {
    $manager = mealManager();
    rtqTemplate('Fresh Project');
    rtqTemplate('Growing Project', confirmed: true, lineStatuses: [ReportTemplateLineStatus::Confirmed, ReportTemplateLineStatus::Pending]);
    rtqTemplate('Growing Again', confirmed: true, lineStatuses: [ReportTemplateLineStatus::Pending]);

    $page = Livewire::actingAs($manager)->test('pages::report-templates.index');

    expect($page->instance()->focusCounts)->toBe(['pending' => 1, 'lines' => 2, 'placement' => 0, 'total' => 3]);

    $page->call('setFocus', 'lines')
        ->assertSeeHtml('data-test="focus-tab-clear-lines"')
        ->assertSeeText('Showing 2 of 3 templates.')
        ->assertSeeHtml('wire:click="setFocus(&#039;lines&#039;)" class="cursor-pointer')
        ->assertSet('focus', 'lines')
        ->assertSee('Growing Project')
        ->assertSee('Growing Again')
        ->assertDontSee('Fresh Project')
        ->call('setFocus', 'lines')
        ->assertSet('focus', '')
        ->assertDontSeeHtml('data-test="focus-showing"')
        ->assertDontSeeHtml('data-test="focus-tab-clear-lines"')
        ->assertSee('Fresh Project')
        ->call('setFocus', 'bogus')
        ->assertSet('focus', '');
});

test('search narrows the counts and the list, and Reset filters is the way out of no results', function () {
    $manager = mealManager();
    rtqTemplate('Aleppo Literacy', proposalName: 'Winter WASH');
    rtqTemplate('Homs Nutrition', confirmed: true, proposalName: 'Spring Hygiene');

    $page = Livewire::actingAs($manager)->test('pages::report-templates.index')
        ->set('search', 'hygiene')
        ->assertSee('Homs Nutrition')
        ->assertDontSee('Aleppo Literacy');

    expect($page->instance()->focusCounts['total'])->toBe(1);

    $page->set('search', 'zzz')
        ->assertSeeHtml('data-test="reset-filters"')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSee('Aleppo Literacy')
        ->assertSee('Homs Nutrition');
});

test('the status buttons, search and review links sit inside the component root', function () {
    $template = rtqTemplate('Aleppo Literacy');

    $html = Livewire::actingAs(mealManager())->test('pages::report-templates.index')->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);

    foreach (['queue-tab-pending', 'queue-tab-lines', 'queue-search'] as $hook) {
        expect($xpath->query('//*[@*[name()="wire:id"]]//*[@data-test="'.$hook.'"]')->length)->toBeGreaterThan(0, $hook);
    }

    expect($xpath->query('//*[@*[name()="wire:id"]]//a[@data-queue-open][contains(@href, "'.$template->project->slug.'")]')->length)->toBe(1);
});

test('two templates at one place are listed with their own proposals at a fixed query count', function () {
    holdMealManagerSlot();
    $manager = mealManager();
    $countQueries = function () use ($manager): int {
        $this->actingAs($manager);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('report-templates.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $program = Program::factory()->create();
    $cityId = insertHrCity('Queue '.Str::random(4), 'طابور');
    $centerId = insertHrCenter($cityId, 'Queue center '.Str::random(4));
    $place = new ReportPlace($cityId, $program->id, $centerId, false);
    $make = function (string $name) use ($place): ReportTemplate {
        $proposal = Proposal::factory()->create(['name' => $name]);
        $template = ReportTemplate::factory()->forPlace($place, $proposal)->create();
        $target = PlanIndicator::factory()->forProposal($proposal)->create();
        ReportTemplateLine::factory()->create(['report_template_id' => $template->id, 'plan_indicator_id' => $target->id]);

        return $template;
    };

    $make('First proposal');
    $countQueries();
    $before = $countQueries();
    $make('Second proposal');
    $make('Third proposal');

    $this->get(route('report-templates.index'))->assertSee('First proposal')->assertSee('Second proposal')->assertSee('Third proposal');
    expect($countQueries())->toBe($before);
});

test('the pending-choice queue joins the selected report and clears the decision', function (string $locale) {
    app()->setLocale($locale);
    $fixture = placementFixture();
    $manager = mealManager();
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $template = ReportTemplate::query()->sole();
    $placement = ReportTemplatePlacement::query()->where('proposal_id', $fixture['proposals'][1]->id)->sole();

    $page = Livewire::actingAs($manager)->test('pages::report-templates.index')
        ->assertSeeHtml('data-test="queue-tab-placement"')
        ->call('setFocus', 'placement')
        ->assertSeeHtml('data-placement-row="'.$placement->id.'"')
        ->call('openPlacement', $placement->id)
        ->assertSet('showPlacementModal', true)
        ->set('selectedPlacementTemplateId', (string) $template->id)
        ->assertSee(__('report_templates.placement_shared'))
        ->call('askJoinPlacement')->assertSet('showPlacementConfirm', true)
        ->call('decidePlacement')->assertHasNoErrors()
        ->assertSet('showPlacementConfirm', false);

    expect($placement->fresh()->status->value)->toBe('joined')
        ->and($page->instance()->focusCounts['placement'])->toBe(0)
        ->and(ReportTemplate::query()->count())->toBe(1);
})->with(['en', 'ar']);

test('the separate-report dialog explains the consequence and creates its own template', function () {
    $fixture = placementFixture();
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $placement = ReportTemplatePlacement::query()->where('proposal_id', $fixture['proposals'][1]->id)->sole();

    Livewire::actingAs(mealManager())->test('pages::report-templates.index')
        ->call('askCreatePlacement', $placement->id)
        ->assertSee(__('report_templates.placement_create_help'))
        ->assertSet('showPlacementConfirm', true)
        ->call('decidePlacement')->assertHasNoErrors();

    expect(ReportTemplate::query()->count())->toBe(2)
        ->and($placement->fresh()->status->value)->toBe('created');
});

test('a covered officer sees and decides only their place choices', function () {
    $fixture = placementFixture();
    $officer = userWithRole('MEAL', insertHrEmployee());
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $placement = ReportTemplatePlacement::query()->where('proposal_id', $fixture['proposals'][1]->id)->sole();
    $place = $placement->place();
    DB::table('meal_coverages')->insert([
        'scope_type' => 'place',
        'scope_key' => 'place:'.$place->key(),
        'city_id' => $place->cityId,
        'program_id' => $place->programId,
        'center_id' => $place->centerId,
        'city_wide' => $place->cityWide,
        'employee_id' => $officer->employee_id,
    ]);

    Livewire::actingAs($officer)->test('pages::report-templates.index')
        ->assertSeeHtml('data-placement-row="'.$placement->id.'"')
        ->call('openPlacement', $placement->id)->assertHasNoErrors();

    Livewire::actingAs(userWithRole('MEAL', insertHrEmployee()))->test('pages::report-templates.index')
        ->assertDontSeeHtml('data-placement-row="'.$placement->id.'"')
        ->call('openPlacement', $placement->id)->assertForbidden();
});

test('MEAL selects among multiple templates at the same place', function () {
    $fixture = placementFixture();
    $manager = mealManager();
    $this->actingAs($manager);
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $first = ReportTemplate::query()->sole();
    $second = app(CreateReportTemplateForProposal::class)->handle($fixture['proposals'][1], $first->place());
    $third = Proposal::factory()->create([
        'name' => 'Third grant', 'status' => 'approved', 'actual_date_begin' => '2026-01-01', 'actual_date_end' => '2026-12-31',
    ]);
    $third->projects()->attach($fixture['project']->id);
    proposalRowForProject($third, $fixture['project'], $first->center_id);
    donorReportTarget($third, $fixture['project'], $fixture['library']);
    $prefiller->prefillForProposal($third);
    $placement = ReportTemplatePlacement::query()->where('proposal_id', $third->id)->sole();

    $page = Livewire::actingAs($manager)->test('pages::report-templates.index')->call('openPlacement', $placement->id);
    expect($page->instance()->placementTemplates)->toHaveCount(2);
    $page->set('selectedPlacementTemplateId', (string) $second->id)->call('askJoinPlacement')->call('decidePlacement')->assertHasNoErrors();

    expect($first->proposals()->count())->toBe(1)
        ->and($second->proposals()->count())->toBe(2);
});

test('templates without a center explain that it must be set first', function (string $locale) {
    $manager = mealManager();
    $template = rtqTemplate('Center still missing');
    $template->forceFill(['center_id' => null, 'city_wide' => false])->save();
    app()->setLocale($locale);

    Livewire::actingAs($manager)->test('pages::report-templates.index')
        ->assertSeeHtml('data-center-unset')
        ->assertSee(__('report_templates.placement_center_missing'));
})->with(['en', 'ar']);
