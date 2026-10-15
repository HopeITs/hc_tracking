<?php

use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Livewire\invade;

test('guests are redirected from the project directory', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('authenticated users see the project directory', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertSeeText('Projects');
});

test('projects can be filtered by search city program owner and status', function () {
    $user = User::factory()->role('General Manager')->create();
    $firstOwner = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');
    $secondOwner = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil', firstNameAr: 'عمر', lastNameAr: 'خليل');
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');
    $water = Program::factory()->create(['name' => 'Water Security']);
    $shelter = Program::factory()->create(['name' => 'Safe Shelter']);

    Project::factory()->active()->create(['name' => 'Aleppo Wells', 'city_id' => $aleppo, 'program_id' => $water->id, 'user_id' => $firstOwner]);
    Project::factory()->closed()->create(['name' => 'Homs Shelter', 'city_id' => $homs, 'program_id' => $shelter->id, 'user_id' => $secondOwner]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->set('search', 'Wells')->assertSee('Aleppo Wells')->assertDontSee('Homs Shelter')
        ->call('clearFilters')->set('city', (string) $homs)->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('search', 'Homs')->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('program', (string) $water->id)->assertSee('Aleppo Wells')->assertDontSee('Homs Shelter')
        ->call('clearFilters')->set('owner', (string) $secondOwner)->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('search', 'Omar Khalil')->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('search', 'عمر')->assertDontSee('Aleppo Wells')->assertSee('Homs Shelter')
        ->call('clearFilters')->set('status', ProjectStatus::Active->value)->assertSee('Aleppo Wells')->assertDontSee('Homs Shelter')
        ->call('clearFilters')->assertSet('status', '')->assertSee('Aleppo Wells')->assertSee('Homs Shelter');
});

test('projects can be filtered by strategy across every one of its programs', function () {
    $user = User::factory()->role('General Manager')->create();
    $waterStrategy = Strategy::factory()->create();
    $otherStrategy = Strategy::factory()->create();
    $water = Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $waterStrategy->id]);
    $shelter = Program::factory()->create(['name' => 'Safe Shelter', 'strategy_id' => $otherStrategy->id]);

    Project::factory()->create(['name' => 'Aleppo Wells', 'program_id' => $water->id]);
    Project::factory()->create(['name' => 'Homs Shelter', 'program_id' => $shelter->id]);

    $this->actingAs($user)
        ->get(route('projects.index', ['strategy' => $waterStrategy->id]))
        ->assertOk()
        ->assertSeeText('Aleppo Wells')
        ->assertDontSeeText('Homs Shelter');
});

test('the status URL filter selects projects by lifecycle state', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->active()->create(['name' => 'Active Water Network']);
    Project::factory()->closed()->create(['name' => 'Closed Shelter Project']);

    $this->actingAs($user)
        ->get(route('projects.index', ['status' => ProjectStatus::Active->value]))
        ->assertOk()
        ->assertSeeText('Active Water Network')
        ->assertDontSeeText('Closed Shelter Project');
});

test('a project is created as a dateless draft with independent beneficiary targets', function () {
    $user = User::factory()->role('General Manager')->create();
    $city = insertHrCity();
    $owner = insertHrEmployee();
    $program = Program::factory()->create(['manager_id' => $owner]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set('name', '  Idlib Water Network  ')
        ->set('description', '  Restores reliable household water access.  ')
        ->set('targetBeneficiaries', '1000')
        ->set('targetMen', '180')
        ->set('targetWomen', '220')
        ->set('targetBoys', '140')
        ->set('targetGirls', '160')
        ->set('centerId', (string) insertHrCenter($city))
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner)
        ->call('saveProject')
        ->assertHasNoErrors()
        ->assertSet('showProjectModal', false);

    $project = Project::query()->where('name', 'Idlib Water Network')->sole();

    expect($project->description)->toBe('Restores reliable household water access.')
        ->and($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->start_date)->toBeNull()
        ->and($project->end_date)->toBeNull()
        ->and($project->target_beneficiaries)->toBe(1000)
        ->and($project->targetDemographicTotal())->toBe(700)
        ->and($project->city_id)->toBe($city)
        ->and($project->program_id)->toBe($program->id)
        ->and($project->user_id)->toBe($owner);
});

test('nullable project details are normalized before saving', function () {
    $user = User::factory()->role('General Manager')->create();
    $city = insertHrCity();
    $owner = insertHrEmployee();
    $program = Program::factory()->create(['manager_id' => $owner]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set('name', 'Project without targets')
        ->set('description', '   ')
        ->set('centerId', (string) insertHrCenter($city))
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner)
        ->call('saveProject')
        ->assertHasNoErrors();

    $project = Project::query()->where('name', 'Project without targets')->sole();

    expect($project->description)->toBeNull()
        ->and($project->start_date)->toBeNull()
        ->and($project->end_date)->toBeNull()
        ->and($project->target_beneficiaries)->toBeNull()
        ->and($project->targetDemographicTotal())->toBeNull();
});

test('creating a project requires a name a center a program and an owner', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set('name', '')
        ->set('centerId', '')
        ->set('programId', '')
        ->set('userId', '')
        ->call('saveProject')
        ->assertHasErrors(['name', 'centerId', 'programId', 'userId']);

    expect(Project::query()->count())->toBe(0);
});

test('a project cannot be placed at a center that does not exist in hr', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set('name', 'Ghost Center Project')
        ->set('centerId', '999999')
        ->set('programId', (string) Program::factory()->create()->id)
        ->set('userId', (string) $user->id)
        ->call('saveProject')
        ->assertHasErrors(['centerId']);

    expect(Project::query()->count())->toBe(0);
});

test('project lifecycle dates and targets reject invalid values', function () {
    $user = User::factory()->role('General Manager')->create();
    $city = insertHrCity();
    $owner = insertHrEmployee();
    $program = Program::factory()->create(['manager_id' => $owner]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set('name', 'Invalid Project')
        ->set('centerId', (string) insertHrCenter($city))
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner)
        ->set('startDate', '2026-09-10')
        ->set('endDate', '2026-09-09')
        ->set('targetBeneficiaries', '-1')
        ->set('targetMen', '1.5')
        ->set('targetWomen', '-2')
        ->set('targetBoys', 'not-a-number')
        ->set('targetGirls', '-3')
        ->call('saveProject')
        ->assertHasErrors(['endDate', 'targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls']);

    expect(Project::query()->count())->toBe(0);
});

test('a project can be renamed and moved to another center while keeping its status', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create([
        'name' => 'Old Name',
        'description' => 'Original scope',
        'status' => ProjectStatus::Approved,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'target_beneficiaries' => 500,
        'target_men' => 100,
        'target_women' => 120,
        'target_boys' => 80,
        'target_girls' => 90,
    ]);
    $newCity = insertHrCity('Hama');

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->assertSet('name', 'Old Name')
        ->assertSet('description', 'Original scope')
        ->assertSet('startDate', '2026-01-01')
        ->assertSet('endDate', '2026-12-31')
        ->assertSet('targetBeneficiaries', '500')
        ->assertSet('targetMen', '100')
        ->assertSet('targetWomen', '120')
        ->assertSet('targetBoys', '80')
        ->assertSet('targetGirls', '90')
        ->set('name', 'New Name')
        ->set('description', '  Updated scope  ')
        ->set('startDate', '2026-02-01')
        ->set('endDate', '2027-01-31')
        ->set('centerId', (string) insertHrCenter($newCity))
        ->set('targetBeneficiaries', '450')
        ->set('targetMen', '100')
        ->set('targetWomen', '120')
        ->set('targetBoys', '80')
        ->set('targetGirls', '90')
        ->call('saveProject')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->name)->toBe('New Name')
        ->and($project->description)->toBe('Updated scope')
        ->and($project->status)->toBe(ProjectStatus::Approved)
        ->and($project->start_date?->toDateString())->toBe('2026-02-01')
        ->and($project->end_date?->toDateString())->toBe('2027-01-31')
        ->and($project->city_id)->toBe($newCity)
        ->and($project->target_beneficiaries)->toBe(450)
        ->and($project->targetDemographicTotal())->toBe(390);
});

test('the project directory offers no delete action', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertDontSee('wire:click="deleteProject', false)
        ->assertDontSee('Delete project', false);
});

test('the project directory renders with no projects', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)->test('pages::projects.index')->assertSee('No projects yet');
});

/**
 * One project per triage lens, each tripping exactly one of them.
 *
 * @return array<string, Project>
 */
function projectsForEachLens(): array
{
    $fund = function (Project $project): Project {
        $proposal = Proposal::factory()->create();
        DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '1000.00']);
        $project->proposals()->attach($proposal);

        return $project;
    };

    $unfunded = Project::factory()->active()->create(['name' => 'Unfunded Wells', 'end_date' => today()->addYear()]);

    $overdue = $fund(Project::factory()->active()->create(['name' => 'Overdue Clinic', 'start_date' => today()->subYears(2), 'end_date' => today()->subDay()]));

    $slipping = $fund(Project::factory()->active()->create(['name' => 'Slipping Shelter', 'end_date' => today()->addYear()]));
    Milestone::factory()->create(['project_id' => $slipping->id, 'planned_start_date' => today()->subWeek(), 'status' => MilestoneStatus::Planned]);

    $risky = $fund(Project::factory()->active()->create(['name' => 'Risky Bakery', 'end_date' => today()->addYear()]));
    Risk::factory()->happened()->create(['project_id' => $risky->id]);

    return ['unfunded' => $unfunded, 'overdue' => $overdue, 'slipping' => $slipping, 'risks' => $risky];
}

test('each triage lens selects only the projects that trip it', function (string $lens, string $expected) {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $component = Livewire::actingAs($user)->test('pages::projects.index')->set('lens', $lens);

    expect($component->instance()->projects->getCollection()->pluck('name')->all())->toBe([$expected]);
})->with([
    ['unfunded', 'Unfunded Wells'],
    ['overdue', 'Overdue Clinic'],
    ['slipping', 'Slipping Shelter'],
    ['risks', 'Risky Bakery'],
]);

test('an unknown lens filters nothing away', function () {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $component = Livewire::actingAs($user)->test('pages::projects.index')->set('lens', 'not-a-lens');

    expect($component->get('lens'))->toBe('')
        ->and($component->instance()->projects->total())->toBe(4);
});

test('triage counts ignore the active lens so a tile never shrinks itself', function () {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->triageCounts)->toBe(['unfunded' => 1, 'overdue' => 1, 'slipping' => 1, 'risks' => 1]);

    $component->set('lens', 'overdue');

    expect($component->instance()->triageCounts)->toBe(['unfunded' => 1, 'overdue' => 1, 'slipping' => 1, 'risks' => 1])
        ->and($component->instance()->projects->total())->toBe(1);
});

test('the active triage lens renders a complete inset border', function () {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $html = Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('lens', 'overdue')
        ->html();

    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $activeLens = $xpath->query('//button[@data-test="lens-overdue" and @aria-pressed="true"]')->item(0);

    expect($activeLens)->not->toBeNull()
        ->and($activeLens->getAttribute('class'))->toContain('box-border', 'border-2', 'border-primary');
});

test('lifecycle counts ignore the status filter but respect every other filter', function () {
    $user = User::factory()->role('General Manager')->create();
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');

    Project::factory()->active()->create(['city_id' => $aleppo]);
    Project::factory()->closed()->create(['city_id' => $aleppo]);
    Project::factory()->active()->create(['city_id' => $homs]);

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->statusCounts)->toBe(['active' => 2, 'closed' => 1]);

    $component->set('status', ProjectStatus::Active->value);

    expect($component->instance()->statusCounts)->toBe(['active' => 2, 'closed' => 1]);

    $component->set('city', (string) $aleppo);

    expect($component->instance()->statusCounts)->toBe(['active' => 1, 'closed' => 1]);
});

test('committed funding counts a proposal shared by two projects once', function () {
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create();
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '1000.00']);

    foreach (Project::factory()->count(2)->create() as $project) {
        $project->proposals()->attach($proposal);
    }

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->committedFunding)->toBe(['USD' => 1000.0]);
});

test('committed funding reports one line per currency and never adds them together', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create();

    foreach ([Currency::Usd, Currency::Eur] as $currency) {
        $proposal = Proposal::factory()->create();
        DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '2500.00', 'currency' => $currency]);
        $project->proposals()->attach($proposal);
    }

    $funding = Livewire::actingAs($user)->test('pages::projects.index')->instance()->committedFunding;

    ksort($funding);

    expect($funding)->toBe(['EUR' => 2500.0, 'USD' => 2500.0]);
});

test('weighted milestone progress is derived from the reached weight and capped at 100', function () {
    $user = User::factory()->role('General Manager')->create();
    $weighted = Project::factory()->create(['name' => 'Weighted Works']);
    $unweighted = Project::factory()->create(['name' => 'Unweighted Works']);
    $overweight = Project::factory()->create(['name' => 'Overweight Works']);

    Milestone::factory()->reached()->create(['project_id' => $weighted->id, 'weight_percentage' => '40.00']);
    Milestone::factory()->create(['project_id' => $weighted->id, 'weight_percentage' => '60.00']);
    Milestone::factory()->create(['project_id' => $weighted->id, 'weight_percentage' => '10.00', 'status' => MilestoneStatus::Cancelled]);

    Milestone::factory()->create(['project_id' => $unweighted->id, 'weight_percentage' => '0.00']);

    Milestone::factory()->reached()->create(['project_id' => $overweight->id, 'weight_percentage' => '90.00']);
    Milestone::factory()->reached()->create(['project_id' => $overweight->id, 'weight_percentage' => '60.00']);

    $component = Livewire::actingAs($user)->test('pages::projects.index');
    $page = $component->instance()->projects->getCollection()->keyBy('name');

    expect(invade($component->instance())->progressFor($page['Weighted Works']))->toBe(40.0)
        ->and(invade($component->instance())->progressFor($page['Unweighted Works']))->toBeNull()
        ->and(invade($component->instance())->progressFor($page['Overweight Works']))->toBe(100.0);
});

test('every view renders the same filtered project set and an unknown view falls back to the board', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->active()->create(['name' => 'Aleppo Wells']);
    Project::factory()->closed()->create(['name' => 'Homs Shelter']);

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    foreach (['board', 'timeline', 'table'] as $view) {
        $component->set('view', $view)->assertSee('Aleppo Wells')->assertSee('Homs Shelter');
    }

    $component->set('view', 'gantt');

    expect($component->get('view'))->toBe('board');

    $component->assertSee('Aleppo Wells')->assertSee('Homs Shelter');
});

test('projects can be sorted by name dates and recency with undated projects last', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->create(['name' => 'Beta', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'updated_at' => '2026-01-01 00:00:00']);
    Project::factory()->create(['name' => 'Alpha', 'start_date' => '2026-06-01', 'end_date' => '2026-07-31', 'updated_at' => '2026-02-01 00:00:00']);
    Project::factory()->create(['name' => 'Gamma', 'start_date' => null, 'end_date' => null, 'updated_at' => '2026-03-01 00:00:00']);

    $component = Livewire::actingAs($user)->test('pages::projects.index')->set('view', 'table');

    $order = fn (): array => $component->instance()->projects->getCollection()->pluck('name')->all();

    $component->set('sort', 'name');
    expect($order())->toBe(['Alpha', 'Beta', 'Gamma']);

    $component->set('sort', 'start_date');
    expect($order())->toBe(['Beta', 'Alpha', 'Gamma']);

    $component->set('sort', 'end_date');
    expect($order())->toBe(['Alpha', 'Beta', 'Gamma']);

    $component->set('sort', 'updated_at');
    expect($order())->toBe(['Gamma', 'Alpha', 'Beta']);

    $component->set('sort', 'sideways');
    expect($component->get('sort'))->toBe('name')
        ->and($order())->toBe(['Alpha', 'Beta', 'Gamma']);
});

test('the board groups projects by lifecycle state and pages them in twenty-fours', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->count(2)->closed()->create();
    Project::factory()->count(2)->active()->create();
    Project::factory()->count(21)->create();

    $component = Livewire::actingAs($user)->test('pages::projects.index');
    $page = $component->instance()->projects;

    expect($page->perPage())->toBe(24)
        ->and($page->count())->toBe(24)
        ->and($page->getCollection()->pluck('status.value')->unique()->values()->all())->toBe(['draft', 'active', 'closed']);

    $component->set('view', 'table');

    expect($component->instance()->projects->perPage())->toBe(15);
});

test('the project directory renders within its query budget', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->count(5)->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::actingAs($user)->test('pages::projects.index');

    // 17 + one query that loads every owner assignment for the Rejected badge
    // + one count of the projects without a center (shown to managers)
    // + the two HR queries of EmployeeProfile behind the owner chips' positions
    // + the sector filter's options (T34; the table view adds one eager load).
    expect($queries)->toBeLessThanOrEqual(22);
});

test('a board card hides relations it has none of and keeps a materialised risk', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    Milestone::factory()->count(2)->create(['project_id' => $project->id]);

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    // Two milestones earn a chip; the five empty relations are not rendered at all.
    $component->assertSeeHtml('aria-hidden="true">2</span>')
        ->assertSeeText('2 milestones')
        ->assertDontSeeHtml('aria-hidden="true">0</span>');

    Risk::factory()->create(['project_id' => $project->id, 'happened' => true]);

    // A materialised risk always shows, and takes the solid alert treatment.
    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeHtml('border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white');
});

test('a board card with no relations at all says so instead of rendering empty chips', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->active()->create(['name' => 'Bare Project']);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeText('Nothing linked yet')
        ->assertDontSeeHtml('aria-hidden="true">0</span>');
});

test('a board card opens the project through an eye action, not a clickable name', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeHtml('aria-label="Open Wells Of Hope"')
        ->assertSeeHtml('href="'.route('projects.show', $project).'"')
        // The name is plain text; nothing stretches a link across the card.
        ->assertDontSeeHtml('after:absolute after:inset-0');
});

test('a board card never prints the progress bar accessibility label as visible copy', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    Milestone::factory()->create(['project_id' => $project->id, 'weight_percentage' => 100, 'status' => MilestoneStatus::Reached]);

    $rendered = Livewire::actingAs($user)->test('pages::projects.index');

    // The name still reaches assistive tech through aria-label, but is not drawn.
    $rendered->assertSeeHtml('aria-label="Milestone progress for Wells Of Hope"')
        ->assertDontSeeText('Milestone progress for Wells Of Hope');
});

test('a board card rates an overdue project critical and an on-track project good', function () {
    $user = User::factory()->role('General Manager')->create();
    $overdue = Project::factory()->active()->create([
        'name' => 'Overdue Project',
        'start_date' => now()->subYear(),
        'end_date' => now()->subMonth(),
    ]);
    Milestone::factory()->create(['project_id' => $overdue->id, 'weight_percentage' => 100, 'status' => MilestoneStatus::Planned]);

    Livewire::actingAs($user)->test('pages::projects.index')
        // Critical fills solid in light (white type); dark uses the soft critical chip.
        ->assertSeeHtml('border-primary bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical font-semibold text-white')
        ->assertSeeHtml('border-primary/40');
});

test('every view opens a project through the same eye action instead of a clickable name', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    $showUrl = route('projects.show', $project);

    foreach (['board', 'timeline', 'table'] as $view) {
        Livewire::actingAs($user)->test('pages::projects.index')
            ->set('view', $view)
            ->assertSeeHtml('aria-label="Open Wells Of Hope"')
            ->assertSeeHtml('href="'.$showUrl.'"')
            // The name is plain text in every view; only the eye navigates.
            ->assertDontSeeHtml('href="'.$showUrl.'" wire:navigate title="Wells Of Hope"');
    }
});

test('a project is archived rather than deleted, keeping every record it carries', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    Milestone::factory()->count(2)->create(['project_id' => $project->id]);
    Risk::factory()->create(['project_id' => $project->id]);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->call('confirmArchive', $project->id)
        // The dialog only opens from the boolean, so both must be set.
        ->assertSet('archivingProjectId', $project->id)
        ->assertSet('showArchiveModal', true)
        ->call('archiveProject')
        ->assertSet('archivingProjectId', null)
        ->assertSet('showArchiveModal', false);

    expect($project->fresh()->status)->toBe(ProjectStatus::Archived)
        // Archiving destroys nothing.
        ->and(Milestone::query()->where('project_id', $project->id)->count())->toBe(2)
        ->and(Risk::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('an archived project can be restored back into the portfolio as a draft', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->create(['name' => 'Wells Of Hope', 'status' => ProjectStatus::Archived]);

    Livewire::actingAs($user)->test('pages::projects.index')->call('restoreProject', $project->id);

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('restoring only applies to archived projects', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);

    Livewire::actingAs($user)->test('pages::projects.index')->call('restoreProject', $project->id);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('cancelling the archive confirmation leaves the project where it was', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->call('confirmArchive', $project->id)
        ->call('cancelArchive')
        ->assertSet('archivingProjectId', null)
        ->assertSet('showArchiveModal', false);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('the archive confirmation names the work the project keeps', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    Milestone::factory()->count(3)->create(['project_id' => $project->id]);

    $component = Livewire::actingAs($user)->test('pages::projects.index')->call('confirmArchive', $project->id);
    $pending = $component->instance()->archivingProject;

    // Nothing with a zero count is listed.
    expect($pending['carries'])->toBe(['Milestones' => 3]);

    $component->assertSeeText('Archive this project?')
        ->assertSeeText('3 Milestones');
});

test('archiving a project that vanished mid-confirmation closes cleanly instead of erroring', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);

    $component = Livewire::actingAs($user)->test('pages::projects.index')->call('confirmArchive', $project->id);

    Project::query()->whereKey($project->id)->delete();

    $component->call('archiveProject')
        ->assertSet('archivingProjectId', null)
        ->assertSet('showArchiveModal', false)
        ->assertHasNoErrors();
});

test('every view offers archive for a live project and restore for an archived one', function () {
    $user = User::factory()->role('General Manager')->create();
    $live = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    $archived = Project::factory()->create(['name' => 'Retired Project', 'status' => ProjectStatus::Archived]);

    foreach (['board', 'timeline', 'table'] as $view) {
        Livewire::actingAs($user)->test('pages::projects.index')
            ->set('view', $view)
            ->assertSeeHtml('confirmArchive('.$live->id.')')
            ->assertSeeHtml('Archive Wells Of Hope')
            ->assertSeeHtml('restoreProject('.$archived->id.')')
            ->assertSeeHtml('Restore Retired Project');
    }
});

test('the project form lays its fields along a three-section rail with no native selects', function () {
    $user = User::factory()->role('General Manager')->create();

    $html = Livewire::actingAs($user)->test('pages::projects.index')->call('openCreateModal')->html();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);
    $dialog = '//dialog[contains(@class, "modal-shell")][.//*[@data-test="save-project-button"]]';
    $headings = array_map(
        fn (DOMNode $heading): string => trim($heading->textContent),
        iterator_to_array($xpath->query($dialog.'//*[contains(@class, "form-section-heading")]')),
    );

    expect($xpath->query($dialog)->length)->toBe(1)
        ->and($headings)->toBe(['Details', 'Placement', 'Beneficiaries'])
        ->and($xpath->query($dialog.'//select')->length)->toBe(0)
        ->and($xpath->query($dialog.'//*[@data-form-select]')->length)->toBe(3)
        ->and($xpath->query($dialog.'[@*[name()="wire:close"]="closeProjectModal"]')->length)->toBe(1)
        ->and($xpath->query($dialog.'//form[@*[name()="wire:submit"]="saveProject"]')->length)->toBe(1);
});

test('a failed project save points at the rail sections that hold the problems', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->call('saveProject')
        ->assertHasErrors(['name', 'centerId', 'programId', 'userId'])
        ->assertSeeText('1 field needs attention')
        ->assertSeeText('3 fields need attention')
        ->assertSeeHtml('aria-invalid="true"');
});

test('editing a project shows its saved choices in the form pickers', function () {
    $user = User::factory()->role('General Manager')->create();
    $city = insertHrCity('Idlib', 'إدلب');
    $owner = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad', firstNameAr: 'لينا', lastNameAr: 'حداد');
    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'city_id' => $city,
        'center_id' => insertHrCenter($city, 'Hope Center Idlib', 'مركز الأمل إدلب'),
        'program_id' => $program->id,
        'user_id' => $owner,
    ]);

    $html = Livewire::actingAs($user)->test('pages::projects.index')->call('openEditModal', $project->id)->html();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);
    $triggerLabel = fn (string $model): string => trim($xpath->query('//*[@data-form-select="'.$model.'"]//*[@data-trigger-label]')->item(0)->textContent);

    expect($triggerLabel('centerId'))->toBe('Hope Center Idlib')
        ->and($triggerLabel('programId'))->toBe($program->name)
        ->and($triggerLabel('userId'))->toBe('Lina Haddad · لينا حداد');
});

test('the project form is rendered only while its modal is open', function () {
    $user = User::factory()->role('General Manager')->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertDontSeeHtml('wire:model="targetBeneficiaries"')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->assertSeeHtml('wire:model="targetBeneficiaries"');
});
