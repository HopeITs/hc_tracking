<?php

use App\Actions\Targets\SaveProposalTargetsGrid;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\ProgramRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = mealManager();
    $this->actingAs($this->meal);

    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();

    $cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerA = Project::factory()->create([
        'program_id' => $this->program->id,
        'center_id' => insertHrCenter($cityId, 'Hope Center Sarmada', 'مركز الأمل سرمدا'),
        'city_id' => $cityId,
    ]);
    $this->centerB = Project::factory()->create(['program_id' => $this->program->id, 'name' => 'Unmapped Water Project']);
    DB::table('project_proposal')->insert([
        ['project_id' => $this->centerA->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 180],
        ['project_id' => $this->centerB->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => null],
    ]);
    $this->rowA = proposalRowForProject($this->proposal, $this->centerA, (int) $this->centerA->center_id);
    $this->rowB = proposalRowForProject($this->proposal, $this->centerB, false);

    $this->output = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);
    $this->indicator = tabIndicator($this->output);
});

function tabIndicator(Result $result, IndicatorMeasure $measure = IndicatorMeasure::Numeric, bool $linked = true): Indicator
{
    $entry = LibraryIndicator::factory()->create(['measure' => $measure, 'program_id' => $result->program_id]);

    return Indicator::factory()->create([
        'result_id' => $result->id,
        'library_indicator_id' => $linked ? $entry->id : null,
        'code' => $linked ? $entry->code : null,
        'measure' => $measure,
    ]);
}

function targetsTab(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('proposals.targets', ['proposalId' => $proposal->id]);
}

function cellKey(Indicator $indicator, ProposalLocation $row): string
{
    return $indicator->id.'_'.$row->id;
}

test('the proposal page renders the targets component on the targets tab', function () {
    Livewire::actingAs($this->meal)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->set('tab', 'targets')
        ->assertSee(__('proposals.tab_targets'))
        ->assertSeeLivewire('proposals.targets');
});

test('the grid has one column per location row, labelled by its city, never one per center (Y-10)', function () {
    $this->rowA->centers()->create(['center_id' => insertHrCenter((int) $this->centerA->city_id, 'Hope Center Dana', 'مركز الأمل الدانا')]);

    $html = targetsTab($this->meal, $this->proposal)
        ->assertSee('Idlib')
        ->assertSeeHtml('data-targets-grid')
        ->assertSeeHtml('data-column="'.$this->rowA->id.'"')
        ->assertSeeHtml('data-column="'.$this->rowB->id.'"')
        ->html();

    expect(substr_count($html, 'data-column="'))->toBe(2);
});

test('MEAL enters a target per center and the grid saves it in one batch', function () {
    targetsTab($this->meal, $this->proposal)
        ->assertSeeHtml('name="grid['.cellKey($this->indicator, $this->rowA).'][value]"')
        ->set('grid.'.cellKey($this->indicator, $this->rowA).'.value', '120')
        ->set('grid.'.cellKey($this->indicator, $this->rowA).'.period', 'annual')
        ->set('grid.'.cellKey($this->indicator, $this->rowB).'.value', '0')
        ->set('grid.'.cellKey($this->indicator, $this->rowB).'.period', 'annual')
        ->call('saveGrid')
        ->assertHasNoErrors()
        ->assertSet('grid.'.cellKey($this->indicator, $this->rowA).'.value', '120');

    expect(DB::table('plan_indicators')->where('source_type', 'proposal')->where('source_id', $this->proposal->id)->pluck('target', 'project_id')->all())
        ->toEqual([$this->centerA->id => '120.00', $this->centerB->id => '0.00']);
});

test('an invalid cell refuses the whole batch and names the cell', function () {
    targetsTab($this->meal, $this->proposal)
        ->set('grid.'.cellKey($this->indicator, $this->rowA).'.value', '120')
        ->set('grid.'.cellKey($this->indicator, $this->rowA).'.period', 'annual')
        ->set('grid.'.cellKey($this->indicator, $this->rowB).'.value', '-5')
        ->set('grid.'.cellKey($this->indicator, $this->rowB).'.period', 'annual')
        ->call('saveGrid')
        ->assertHasErrors(['grid.'.cellKey($this->indicator, $this->rowB).'.value', 'cells'])
        ->assertSee(__('targets.value_negative'));

    expect(PlanIndicator::query()->count())->toBe(0);
});

test('a cell changed by someone else refuses the batch with a reload', function () {
    app(SaveProposalTargetsGrid::class)->handle($this->proposal, [
        ['indicator_id' => $this->indicator->id, 'location_id' => $this->rowA->id, 'value' => '120', 'period' => 'annual', 'version' => null],
    ]);

    $tab = targetsTab($this->meal, $this->proposal);
    DB::table('plan_indicators')->update(['target' => '999', 'updated_at' => now()->addMinute()]);

    $tab->set('grid.'.cellKey($this->indicator, $this->rowA).'.value', '150')
        ->call('saveGrid')
        ->assertHasErrors(['grid.'.cellKey($this->indicator, $this->rowA)])
        ->assertSet('isStale', true)
        ->assertSee(__('targets.cell_stale'))
        ->call('reloadGrid')
        ->assertSet('isStale', false)
        ->assertSet('grid.'.cellKey($this->indicator, $this->rowA).'.value', '999');

    expect(DB::table('plan_indicators')->value('target'))->toBe('999.00');
});

test('non-MEAL roles see the grid read-only and are refused on save', function (string $role) {
    $user = User::factory()->role($role)->create();

    targetsTab($user, $this->proposal)
        ->assertSee(__('targets.meal_enters_targets'))
        ->assertDontSeeHtml('name="grid[')
        ->assertDontSee(__('targets.save_grid'))
        ->set('grid.'.cellKey($this->indicator, $this->rowA).'.value', '120')
        ->call('saveGrid')
        ->assertForbidden();

    targetsTab($user, $this->proposal)->call('acceptSuggestion', cellKey($this->indicator, $this->rowA))->assertForbidden();

    expect(PlanIndicator::query()->count())->toBe(0);
})->with(['General Manager', 'Managers', 'Public Relations Manager']);

test('a proposal editor without indicator rights cannot attach or detach', function () {
    $editor = User::factory()->role('Public Relations Manager')->create();
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);

    targetsTab($editor, $this->proposal)
        ->assertDontSee(__('targets.library_picker'))
        ->call('attach', $entry->id, $this->output->id)
        ->assertForbidden();

    targetsTab($editor, $this->proposal)->call('confirmDetach', $this->indicator->id)->assertForbidden();
    targetsTab($editor, $this->proposal)->call('openLibraryEntryFor', (string) $this->program->id)->assertForbidden();

    expect(Indicator::query()->pluck('id')->all())->toBe([$this->indicator->id]);
});

test('a rejected proposal shows the grid read-only and says why', function () {
    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'rejected']);

    targetsTab($this->meal, $this->proposal)
        ->assertSee(__('targets.proposal_read_only'))
        ->assertDontSeeHtml('name="grid[');
});

test('the picker lists the proposal programs and institutional entries only, searchable', function () {
    $own = LibraryIndicator::factory()->create(['program_id' => $this->program->id, 'name_en' => 'Children enrolled', 'name_ar' => 'أطفال مسجلون']);
    $institutional = LibraryIndicator::factory()->create(['program_id' => null, 'name_en' => 'Staff trained', 'name_ar' => 'موظفون مدربون']);
    $foreign = LibraryIndicator::factory()->create(['program_id' => Program::factory()->create()->id, 'name_en' => 'Wells drilled']);
    LibraryIndicator::factory()->inactive()->create(['program_id' => $this->program->id, 'name_en' => 'Retired entry']);

    targetsTab($this->meal, $this->proposal)
        ->assertSee([$own->code, $institutional->code, 'Children enrolled', 'Staff trained'])
        ->assertDontSee(['Wells drilled', 'Retired entry'])
        ->set('pickerSearch', 'Staff')
        ->assertSee('Staff trained')
        ->assertDontSee('Children enrolled');

    expect($foreign->exists)->toBeTrue();
});

test('attaching from the picker creates the instance under the chosen node', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);

    targetsTab($this->meal, $this->proposal)
        ->call('attach', $entry->id, $this->output->id)
        ->assertHasNoErrors();

    expect(Indicator::query()->where('library_indicator_id', $entry->id)->sole())
        ->result_id->toBe($this->output->id)
        ->code->toBe($entry->code);
});

test('attaching under another proposal node is refused', function () {
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id]);
    $foreignNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => Proposal::factory()->create()->id]);

    targetsTab($this->meal, $this->proposal)->call('attach', $entry->id, $foreignNode->id)->assertNotFound();

    expect(Indicator::query()->where('library_indicator_id', $entry->id)->exists())->toBeFalse();
});

test('detaching an indicator with targets is refused with the count', function () {
    app(SaveProposalTargetsGrid::class)->handle($this->proposal, [
        ['indicator_id' => $this->indicator->id, 'location_id' => $this->rowA->id, 'value' => '120', 'period' => 'annual', 'version' => null],
    ]);

    targetsTab($this->meal, $this->proposal)
        ->call('confirmDetach', $this->indicator->id)
        ->assertSet('showDetachModal', true)
        ->call('detach')
        ->assertHasErrors('indicator');

    $bare = tabIndicator($this->output);

    targetsTab($this->meal, $this->proposal)
        ->call('confirmDetach', $bare->id)
        ->call('detach')
        ->assertHasNoErrors()
        ->assertSet('showDetachModal', false);

    expect(Indicator::query()->whereKey($bare->id)->exists())->toBeFalse();
});

test('percentage cells show the derived figure and the missing beneficiaries badge', function () {
    $percentage = tabIndicator($this->output, IndicatorMeasure::Percentage);
    app(SaveProposalTargetsGrid::class)->handle($this->proposal, [
        ['indicator_id' => $percentage->id, 'location_id' => $this->rowA->id, 'value' => '80', 'period' => 'annual', 'version' => null],
    ]);

    targetsTab($this->meal, $this->proposal)
        ->assertSee('80% × 180 = 144')
        ->assertSee(__('targets.beneficiaries_missing'));
});

test('an instance not linked to the library takes no input and says so', function () {
    $legacy = tabIndicator($this->output, linked: false);

    targetsTab($this->meal, $this->proposal)
        ->assertSee(__('targets.not_linked_to_library'))
        ->assertDontSeeHtml('name="grid['.cellKey($legacy, $this->rowA).'][value]"');
});

test('a reported target keeps its value editable and shows it is locked to its indicator', function () {
    app(SaveProposalTargetsGrid::class)->handle($this->proposal, [
        ['indicator_id' => $this->indicator->id, 'location_id' => $this->rowA->id, 'value' => '120', 'period' => 'annual', 'version' => null],
    ]);
    Report::factory()->create(['plan_indicator_id' => PlanIndicator::query()->value('id')]);

    targetsTab($this->meal, $this->proposal)
        ->assertSee(__('targets.cell_reported'))
        ->assertSeeHtml('name="grid['.cellKey($this->indicator, $this->rowA).'][value]"');
});

test('a renewal suggests last year achievement and saves it only once accepted', function () {
    $previous = Proposal::factory()->create();
    DB::table('proposals')->where('id', $this->proposal->id)->update(['previous_proposal_id' => $previous->id]);
    DB::table('project_proposal')->insert(['project_id' => $this->centerA->id, 'proposal_id' => $previous->id]);
    $oldNode = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $previous->id]);
    $oldInstance = Indicator::factory()->create(['result_id' => $oldNode->id, 'library_indicator_id' => $this->indicator->library_indicator_id]);
    $oldTarget = PlanIndicator::factory()->create(['source_type' => 'proposal', 'source_id' => $previous->id, 'indicator_id' => $oldInstance->id, 'project_id' => $this->centerA->id, 'target' => 100]);
    Report::factory()->approved()->create(['plan_indicator_id' => $oldTarget->id, 'value' => 90]);

    $key = cellKey($this->indicator, $this->rowA);

    targetsTab($this->meal, $this->proposal)
        ->assertSeeHtml('data-suggestion="'.$key.'"')
        ->assertSet('grid.'.$key.'.value', '')
        ->call('acceptSuggestion', $key)
        ->assertSet('grid.'.$key.'.value', '90');

    expect(PlanIndicator::query()->where('source_id', $this->proposal->id)->count())->toBe(0);
});

test('the tab reads a flat number of queries for 3 or 30 cells', function () {
    $countQueries = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        targetsTab($this->meal, $this->proposal);

        return $queries;
    };

    $seedRows = function (int $rows): void {
        $cells = [];

        foreach (range(1, $rows) as $index) {
            $indicator = tabIndicator($this->output);
            $cells[] = ['indicator_id' => $indicator->id, 'location_id' => $this->rowA->id, 'value' => '10', 'period' => 'annual', 'version' => null];
            $cells[] = ['indicator_id' => $indicator->id, 'location_id' => $this->rowB->id, 'value' => '5', 'period' => 'annual', 'version' => null];
        }

        app(SaveProposalTargetsGrid::class)->handle($this->proposal, $cells);
    };

    // Warm the per-process HR and permission lookups so both counts compare the tab alone.
    targetsTab($this->meal, $this->proposal);

    $seedRows(1);
    $small = $countQueries();

    $seedRows(14);
    $large = $countQueries();

    expect($large)->toBe($small);
});

test('the tab renders in Arabic', function () {
    app()->setLocale('ar');

    targetsTab($this->meal, $this->proposal)
        ->assertSee(__('targets.grid_heading', locale: 'ar'))
        ->assertSee('إدلب');
});

/**
 * The header cell of a row's column, as rendered.
 */
function columnHeader(string $html, ProposalLocation $row): string
{
    preg_match('/<th[^>]*data-column="'.$row->id.'".*?<\/th>/s', $html, $match);

    return $match[0] ?? '';
}

test('each column names its centers, field work or a missing center, and never splits a target (Y-10)', function () {
    $unset = ProposalLocation::factory()->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);

    $html = targetsTab($this->meal, $this->proposal)->html();

    expect(columnHeader($html, $this->rowA))->toContain('Hope Center Sarmada')
        ->and(columnHeader($html, $this->rowB))->toContain(__('targets.column_field_work'))
        ->and(columnHeader($html, $unset))->toContain(__('targets.column_center_not_set'))
        ->and($html)->not->toContain(__('targets.column_shared_target'));
});

test('a row with several centers says once that its centers reach one target together (D-90)', function () {
    $this->rowA->centers()->create(['center_id' => insertHrCenter((int) $this->centerA->city_id, 'Hope Center Dana', 'مركز الأمل الدانا')]);

    $html = targetsTab($this->meal, $this->proposal)->html();

    expect(columnHeader($html, $this->rowA))->toContain('Hope Center Sarmada')->toContain('Hope Center Dana')->toContain(__('targets.column_shared_target'))
        ->and(substr_count($html, __('targets.column_shared_target')))->toBe(1)
        ->and(substr_count($html, 'data-column="'))->toBe(2);
});

test('a row waiting for its program shows its column disabled with the reason and sends nothing', function () {
    $waiting = ProposalLocation::factory()->isNew()->create(['proposal_id' => $this->proposal->id, 'program_id' => null]);
    ProgramRequest::factory()->create(['proposal_location_id' => $waiting->id, 'name_en' => 'Mobile clinics', 'name_ar' => 'عيادات متنقلة']);
    $key = cellKey($this->indicator, $waiting);

    $tab = targetsTab($this->meal, $this->proposal);
    $html = $tab->html();

    expect(columnHeader($html, $waiting))->toContain('data-waiting')->toContain(__('targets.column_waiting_program'))->toContain('Mobile clinics')
        ->and($html)->not->toContain('name="grid['.$key.'][value]"')
        ->and($html)->toContain('name="grid['.cellKey($this->indicator, $this->rowA).'][value]"');

    $tab->set('grid.'.$key.'.value', '50')
        ->set('grid.'.$key.'.period', 'annual')
        ->call('saveGrid')
        ->assertHasNoErrors();

    expect(PlanIndicator::query()->count())->toBe(0);
});

test('the grid shows no money to a holder without money rights', function () {
    DonorProposal::factory()->create(['proposal_id' => $this->proposal->id, 'budget' => 987654.32]);

    $html = targetsTab($this->meal, $this->proposal)->html();

    expect($html)->not->toContain('987,654')->not->toContain('987654');
});

test('the tab reads a flat number of queries for 2 or 6 rows with centers', function () {
    $countQueries = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        targetsTab($this->meal, $this->proposal);

        return $queries;
    };

    targetsTab($this->meal, $this->proposal);
    $small = $countQueries();

    foreach (range(1, 4) as $index) {
        $project = Project::factory()->create(['program_id' => $this->program->id]);
        $row = proposalRowForProject($this->proposal, $project);
        $row->centers()->create(['center_id' => insertHrCenter((int) $project->city_id, 'Extra '.$index)]);
    }

    targetsTab($this->meal, $this->proposal);
    $large = $countQueries();

    expect($large)->toBe($small);
});
