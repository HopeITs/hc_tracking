<?php

use App\Enums\MonthlyReportStatus;
use App\Enums\ProjectStatus;
use App\Http\Middleware\SyncProgramsFromHr;
use App\Models\AnnualPlan;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from program management', function () {
    $this->get(route('programs.index'))->assertRedirect(route('login'));
});

test('all authenticated roles can access program management', function (string $roleName) {
    $user = User::factory()->role($roleName)->create();

    $this->actingAs($user)
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('Programs')
        ->assertSee(route('programs.index'), false);
})->with([
    'team member' => 'Team Member',
    'manager' => 'Managers',
    'meal' => 'MEAL',
    'admin' => 'Super Admin',
]);

test('authenticated users can search and filter programs by strategy and manager', function () {
    $user = User::factory()->role('General Manager')->create();
    $firstManager = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $secondManager = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil');
    $firstStrategy = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    $secondStrategy = Strategy::factory()->create(['name' => 'Regional Strategy', 'start_year' => 2031, 'end_year' => 2035]);

    Program::factory()->create([
        'name_en' => 'Water Security',
        'strategy_id' => $firstStrategy->id,
        'manager_id' => $firstManager,
    ]);
    Program::factory()->create([
        'name_en' => 'Safe Shelter',
        'strategy_id' => $secondStrategy->id,
        'manager_id' => $secondManager,
    ]);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter')
        ->set('search', 'Layla')
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->set('strategy', (string) $secondStrategy->id)
        ->assertDontSee('Water Security')
        ->assertSee('Safe Shelter')
        ->call('clearFilters')
        ->set('manager', (string) $firstManager)
        ->assertSee('Water Security')
        ->assertDontSee('Safe Shelter')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('strategy', '')
        ->assertSet('manager', '')
        ->assertSee('Water Security')
        ->assertSee('Safe Shelter');
});

test('a program can be linked to a strategy and manager without touching HR', function () {
    $user = User::factory()->role('General Manager')->create();
    $strategy = Strategy::factory()->create();
    $manager = insertHrEmployee();
    $program = Program::factory()->unassigned()->create(['name_en' => 'Hope Center']);

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->call('openLinkModal')
        ->assertSet('strategyId', '')
        ->assertSet('managerId', '')
        ->set('strategyId', (string) $strategy->id)
        ->set('managerId', (string) $manager)
        ->call('saveProgram')
        ->assertHasNoErrors()
        ->assertSet('showProgramModal', false);

    expect($program->refresh())
        ->name->toBe('Hope Center')
        ->strategy_id->toBe($strategy->id)
        ->manager_id->toBe($manager)
        ->and(DB::connection('hr')->table('programs')->count())->toBe(0);
});

test('a program assignment can be cleared back to not assigned', function () {
    $program = Program::factory()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->call('openLinkModal')
        ->set('strategyId', '')
        ->set('managerId', '')
        ->call('saveProgram')
        ->assertHasNoErrors();

    expect($program->refresh())
        ->strategy_id->toBeNull()
        ->manager_id->toBeNull();
});

test('unassigned programs are listed as not assigned', function () {
    Program::factory()->unassigned()->create(['name_en' => 'Study Zone']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSee('Study Zone')
        ->assertSee('Not assigned');
});

test('strategy and manager assignments must reference available records', function () {
    $program = Program::factory()->unassigned()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->call('openLinkModal')
        ->set('strategyId', '999999')
        ->set('managerId', '999999')
        ->call('saveProgram')
        ->assertHasErrors(['strategyId' => 'exists', 'managerId' => 'exists'])
        ->assertSee('The selected strategy is no longer available.')
        ->assertSee('The selected manager is no longer available.');

    expect($program->refresh()->strategy_id)->toBeNull();
});

test('the public relations manager can add programs but a centre manager cannot (D-68)', function () {
    Livewire::actingAs(User::factory()->role('Public Relations Manager')->create())
        ->test('pages::programs.index')
        ->assertSeeHtml('data-test="create-program-button"');

    Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::programs.index')
        ->assertDontSeeHtml('data-test="create-program-button"');
});

test('program strategy and manager names are escaped in the directory', function () {
    $user = User::factory()->role('General Manager')->create();
    $unsafeProgram = '<script>alert("program")</script>';
    $unsafeStrategy = '<script>alert("strategy")</script>';
    $unsafeManager = '<script>alert("manager")</script>';
    $strategy = Strategy::factory()->create(['name' => $unsafeStrategy]);
    $manager = insertHrEmployee(firstNameEn: $unsafeManager, lastNameEn: '');
    $program = Program::factory()->create([
        'name_en' => $unsafeProgram,
        'strategy_id' => $strategy->id,
        'manager_id' => $manager,
    ]);
    Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Active, 'end_date' => today()->addYear()]);

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->set('strategy', (string) $strategy->id)
        ->assertSee($unsafeProgram)
        ->assertSee($unsafeStrategy)
        ->assertSee($unsafeManager)
        ->assertDontSee($unsafeProgram, false)
        ->assertDontSee($unsafeStrategy, false)
        ->assertDontSee($unsafeManager, false);
});

test('program management explains how programs appear when there are none', function () {
    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.index'))
        ->assertOk()
        ->assertSeeText('No programs yet')
        ->assertSeeText('Programs appear here once they are added.')
        ->assertDontSeeText('HR system');
});

test('program cards link to their details', function () {
    $program = Program::factory()->create(['name_en' => 'Water Security']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSee('Water Security')
        ->assertSee(route('programs.show', $program), false)
        ->assertDontSee('Programs come from the HR system.')
        ->assertDontSee('matching program');
});

test('every program card shows at once, with no load more button', function () {
    foreach (range(1, 9) as $number) {
        Program::factory()->create(['name_en' => 'Program '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
    }

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSee('Program 01')
        ->assertSee('Program 09')
        ->assertDontSee('Load more');
});

test('program gap filter focuses programs without a manager', function () {
    Program::factory()->create(['name_en' => 'Managed Program']);
    Program::factory()->unassigned()->create(['name_en' => 'Unmanaged Program']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->set('gap', 'no_manager')
        ->assertSee('Unmanaged Program')
        ->assertDontSee('Managed Program');
});

test('program filter pickers apply strategy and manager selections', function () {
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy']);
    $manager = insertHrEmployee(firstNameEn: 'Amal', lastNameEn: 'Manager');
    Program::factory()->create(['name_en' => 'Selected Program', 'strategy_id' => $strategy->id, 'manager_id' => $manager]);
    Program::factory()->create(['name_en' => 'Other Program']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('filterByStrategy', $strategy->id)
        ->assertSet('strategy', (string) $strategy->id)
        ->assertSee('Selected Program')
        ->assertDontSee('Other Program')
        ->call('filterByManager', $manager)
        ->assertSet('manager', (string) $manager)
        ->assertSee('Selected Program')
        ->assertDontSee('Other Program');
});

test('visiting the app no longer brings HR programs in: tracking owns them (D-68)', function () {
    Cache::forget(SyncProgramsFromHr::CacheKey);
    $hrProgramId = insertHrProgram('Children University');

    $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('programs.index'))
        ->assertOk()
        ->assertDontSeeText('Children University');

    expect(Program::query()->find($hrProgramId))->toBeNull();
});

test('the link form on the program page is rendered only while its dialog is open', function () {
    $user = User::factory()->role('General Manager')->create();
    $program = Program::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::programs.show', ['program' => $program])
        ->assertDontSeeHtml('wire:model="managerId"')
        ->call('openLinkModal')
        ->assertSeeHtml('wire:model="managerId"');
});

test('the strategy and manager filters can list programs nobody is linked to', function () {
    Program::factory()->create(['name_en' => 'Linked Program']);
    Program::factory()->unassigned()->create(['name_en' => 'Orphan Program']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->set('strategy', 'none')
        ->assertSee('Orphan Program')
        ->assertDontSee('Linked Program')
        ->set('strategy', '')
        ->set('manager', 'none')
        ->assertSee('Orphan Program')
        ->assertDontSee('Linked Program');
});

test('the card list no longer repeats a "showing" count under the cards', function () {
    foreach (range(1, 9) as $number) {
        Program::factory()->create(['name_en' => 'Program '.str_pad((string) $number, 2, '0', STR_PAD_LEFT)]);
    }

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertDontSee('Showing 8 of 9 programs')
        ->assertDontSeeHtml('data-program-shown');
});

test('an empty search offers one reset button, not two', function () {
    Program::factory()->create(['name_en' => 'Water Security']);

    $html = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->set('search', 'zzzz-no-match')
        ->assertSee('No matching programs')
        ->html();

    expect(substr_count($html, 'Reset filters'))->toBe(1);
});

test('the link dialog on the program page names what it links and guards unsaved changes', function () {
    $program = Program::factory()->unassigned()->create(['name_en' => 'Water Security']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertDontSeeHtml('data-discard-guard')
        ->call('openLinkModal')
        ->assertSee('Link Water Security to a strategy and manager')
        ->assertSeeHtml('data-discard-guard')
        ->assertSet('originalStrategyId', '')
        ->assertSeeText('Discard your changes?');
});

test('the no plan gap lists the strategy programs without a plan for this year', function () {
    $this->travelTo('2026-06-15 09:00:00');
    $strategy = Strategy::factory()->create();
    $planned = Program::factory()->create(['name_en' => 'Planned Program', 'strategy_id' => $strategy->id]);
    $shell = Program::factory()->create(['name_en' => 'Shell Program', 'strategy_id' => $strategy->id]);
    $lastYear = Program::factory()->create(['name_en' => 'Last Year Program', 'strategy_id' => $strategy->id]);
    Program::factory()->create(['name_en' => 'Elsewhere Program', 'strategy_id' => Strategy::factory()->create()->id]);

    PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['program_id' => $planned->id, 'year' => 2026]))->create();
    AnnualPlan::factory()->create(['program_id' => $shell->id, 'year' => 2026]);
    PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['program_id' => $lastYear->id, 'year' => 2025]))->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->set('strategy', (string) $strategy->id)
        ->set('gap', 'no_plan')
        ->assertSet('gap', 'no_plan')
        ->assertSee('Shell Program')
        ->assertSee('Last Year Program')
        ->assertDontSee('Planned Program')
        ->assertDontSee('Elsewhere Program');
});

test('the Needs attention filter lists programs with overdue, risky or proposal-less open projects', function (string $gap, string $shown) {
    $this->travelTo('2026-09-15 09:00:00');
    $make = fn (string $name, array $project) => Project::factory()->create(['program_id' => Program::factory()->create(['name_en' => $name])->id] + $project);

    $make('Late Program', ['status' => ProjectStatus::Active, 'end_date' => '2026-08-01']);
    $risky = $make('Risk Program', ['status' => ProjectStatus::Active, 'end_date' => '2027-08-01']);
    Risk::factory()->happened()->create(['project_id' => $risky->id]);
    $risky->proposals()->attach(Proposal::factory()->create());
    $closed = $make('Closed Program', ['status' => ProjectStatus::Closed, 'end_date' => '2025-08-01']);
    Risk::factory()->happened()->create(['project_id' => $closed->id]);

    $html = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->set('gap', $gap)
        ->assertSet('gap', $gap)
        ->html();

    $cards = collect(['Late Program', 'Risk Program', 'Closed Program'])
        ->filter(fn (string $name): bool => str_contains($html, '>'.$name.'</h2>'))
        ->values()
        ->all();

    expect($cards)->toBe([$shown]);
})->with([
    'overdue' => ['overdue', 'Late Program'],
    'risk happened' => ['risk', 'Risk Program'],
    'no proposal' => ['no_proposal', 'Late Program'],
]);

test('the no proposal choice is funding data and is refused without proposal access', function () {
    $user = User::factory()->role('MEAL')->create();
    expect($user->can('proposals.view'))->toBeFalse();

    Livewire::actingAs($user)
        ->test('pages::programs.index')
        ->assertDontSee('Open projects with no proposal')
        ->set('gap', 'no_proposal')
        ->assertSet('gap', '');
});

test('the program page counts last month monthly reports and how many are validated', function () {
    $this->travelTo('2026-09-15 09:00:00');
    $program = Program::factory()->create();
    $first = Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Active, 'end_date' => '2027-01-01']);
    $second = Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Active, 'end_date' => '2027-01-01']);
    MonthlyReport::factory()->create(['project_id' => $first->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Validated]);
    MonthlyReport::factory()->create(['project_id' => $second->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Submitted]);
    MonthlyReport::factory()->create(['project_id' => $first->id, 'period' => '2026-07-01', 'status' => MonthlyReportStatus::Validated]);

    Livewire::actingAs(User::factory()->role('MEAL')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('August reports: 1 of 2 validated');
});

test('the program page says plainly when last month has no reports', function () {
    $this->travelTo('2026-09-15 09:00:00');
    $program = Program::factory()->create();
    Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Active, 'end_date' => '2027-01-01']);

    Livewire::actingAs(User::factory()->role('MEAL')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSee('No August reports yet')
        ->assertDontSee('0 of 0 validated');
});

test('only people who may update a program see its link button', function () {
    $program = Program::factory()->create();

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertDontSeeHtml('data-test="link-program-button"');

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.show', ['program' => $program])
        ->assertSeeHtml('data-test="link-program-button"');
});

test('the card pencil links a program to a strategy and manager from the list', function () {
    $strategy = Strategy::factory()->create();
    $program = Program::factory()->unassigned()->create(['name_en' => 'Hope Center']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertDontSeeHtml('data-discard-guard')
        ->call('openEditModal', $program->id)
        ->assertSet('editingProgramName', 'Hope Center')
        ->assertSee(__('programs.edit_name', ['name' => 'Hope Center']))
        ->assertSeeHtml('data-discard-guard')
        ->set('strategyId', (string) $strategy->id)
        ->call('saveProgram')
        ->assertHasNoErrors()
        ->assertSet('showProgramModal', false);

    expect($program->refresh()->strategy_id)->toBe($strategy->id);
});

test('the manager filter lists only people who manage a program, with their count', function () {
    $manager = insertHrEmployee(firstNameEn: 'Amal', lastNameEn: 'Manager');
    insertHrEmployee(firstNameEn: 'Idle', lastNameEn: 'Bystander');
    Program::factory()->count(2)->create(['manager_id' => $manager]);
    Program::factory()->unassigned()->create();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSee('Amal Manager')
        ->assertSee('(2)')
        ->assertDontSee('Idle Bystander')
        ->assertSee('Not assigned (1)');
});

test('the manager filter shows one name in the interface language with its count last', function (string $locale, string $shown, string $hidden) {
    $manager = insertHrEmployee(firstNameEn: 'Antoine', lastNameEn: 'Arbaji', firstNameAr: 'أنطوان', lastNameAr: 'عربجي');
    Program::factory()->create(['manager_id' => $manager]);
    app()->setLocale($locale);

    $options = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->instance()->managerOptions;

    expect($options[0]['label'])->toBe("\u{2068}{$shown}\u{2069} (1)")
        ->not->toContain($hidden);
})->with([
    'english' => ['en', 'Antoine Arbaji', 'أنطوان'],
    'arabic' => ['ar', 'أنطوان عربجي', 'Antoine'],
]);

test('a strategy name keeps its own direction so Arabic cards cut its end, not its start', function () {
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy 2026-2030', 'start_year' => 2026, 'end_year' => 2030]);
    Program::factory()->create(['strategy_id' => $strategy->id]);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSeeHtml('dir="auto" title="Country Strategy 2026-2030"');
});

test('a program with no strategy reads "Not linked yet" with an Assign shortcut', function () {
    Program::factory()->unassigned()->create(['name_en' => 'Orphan Program']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSeeHtml('data-program-unlinked')
        ->assertSee('Not linked yet')
        ->assertSee('Assign a strategy and manager to Orphan Program');
});

test('a register narrowed to a gap names it in the Needs attention picker', function () {
    $this->travelTo('2026-06-15 09:00:00');

    Livewire::withQueryParams(['gap' => 'no_plan'])
        ->actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSeeText('Needs attention')
        ->assertSeeHtml('<span class="truncate">No 2026 plan</span>')
        ->set('gap', '')
        ->assertSet('gap', '')
        ->assertSeeHtml('<span class="truncate">Everything</span>');
});

test('each card shows only strategy, manager, open projects and closed projects', function () {
    $this->travelTo('2026-09-15 09:00:00');
    $strategy = Strategy::factory()->create(['name' => 'Country Strategy 2026-2030', 'start_year' => 2026, 'end_year' => 2030]);
    $program = Program::factory()->create(['name_en' => 'Split Program', 'strategy_id' => $strategy->id, 'manager_id' => insertHrEmployee(firstNameEn: 'Amal', lastNameEn: 'Manager')]);
    $late = Project::factory()->create(['program_id' => $program->id, 'status' => ProjectStatus::Active, 'end_date' => '2026-08-01']);
    Risk::factory()->happened()->create(['project_id' => $late->id]);
    Project::factory()->count(2)->create(['program_id' => $program->id, 'status' => ProjectStatus::Closed, 'end_date' => '2025-01-01']);
    MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Validated]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::programs.index')
        ->assertSee('Country Strategy 2026-2030')
        ->assertSee('Amal Manager')
        ->assertSeeHtml('data-program-open-projects>1</p>')
        ->assertSeeHtml('data-program-closed-projects>2</p>')
        ->assertDontSee('project overdue')
        ->assertDontSee('live risk')
        ->assertDontSee('has no proposal')
        ->assertDontSee('reports:')
        ->assertDontSeeHtml('data-program-current-plan')
        ->assertDontSeeHtml('data-program-health');
});
