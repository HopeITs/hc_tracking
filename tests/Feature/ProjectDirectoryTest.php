<?php

use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

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
    $water = Program::factory()->create(['name_en' => 'Water Security']);
    $shelter = Program::factory()->create(['name_en' => 'Safe Shelter']);

    rowFor(Project::factory()->active()->create(['name' => 'Aleppo Wells', 'city_id' => $aleppo, 'program_id' => $water->id, 'user_id' => $firstOwner]));
    rowFor(Project::factory()->closed()->create(['name' => 'Homs Shelter', 'city_id' => $homs, 'program_id' => $shelter->id, 'user_id' => $secondOwner]));

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
    $water = Program::factory()->create(['name_en' => 'Water Security', 'strategy_id' => $waterStrategy->id]);
    $shelter = Program::factory()->create(['name_en' => 'Safe Shelter', 'strategy_id' => $otherStrategy->id]);

    projectWithRow(['name' => 'Aleppo Wells', 'program_id' => $water->id]);
    projectWithRow(['name' => 'Homs Shelter', 'program_id' => $shelter->id]);

    $this->actingAs($user)
        ->get(route('projects.index', ['strategy' => $waterStrategy->id]))
        ->assertOk()
        ->assertSeeText('Aleppo Wells')
        ->assertDontSeeText('Homs Shelter');
});

test('the status URL filter selects projects by lifecycle state', function () {
    $user = User::factory()->role('General Manager')->create();
    rowFor(Project::factory()->active()->create(['name' => 'Active Water Network']));
    rowFor(Project::factory()->closed()->create(['name' => 'Closed Shelter Project']));

    $this->actingAs($user)
        ->get(route('projects.index', ['status' => ProjectStatus::Active->value]))
        ->assertOk()
        ->assertSeeText('Active Water Network')
        ->assertDontSeeText('Closed Shelter Project');
});

/*
| Desk page review, 6 Nov 2026: each Project flow stage opened the whole
| register, so "Running: 0" listed all 34 projects.
*/

test('the stage URL filter selects the projects the Desk puts on that stage, and the flow links to it', function () {
    $this->travelTo('2026-11-06 09:00:00');
    $user = User::factory()->role('General Manager')->create();
    $running = Project::factory()->status(ProjectStatus::Active)->create(['name' => 'Running Water Network', 'end_date' => '2027-06-30']);
    rowFor($running, Proposal::factory()->create(['name' => 'Running Water Network', 'status' => ProposalStatus::Approved]));
    $fundraising = Project::factory()->create(['name' => 'Draft Shelter Project']);
    rowFor($fundraising, Proposal::factory()->create(['name' => 'Draft Shelter Project', 'status' => ProposalStatus::ProposalDraft]));

    $this->actingAs($user)
        ->get(route('projects.index', ['stage' => 5]))
        ->assertOk()
        ->assertSeeText('Running Water Network')
        ->assertDontSeeText('Draft Shelter Project');

    Livewire::actingAs($user)->test('pages::projects.index')
        ->set('stage', 'not-a-stage')
        ->assertSet('stage', '');

    Livewire::actingAs($user)->test('desk.panels.flow', ['size' => 'XL'])
        ->assertSeeHtml('href="'.route('projects.index', ['stage' => 5]).'"');
});

/*
| Y-17: the four cases that drove the create form are gone with it — §8 of
| the [contract] drops `projects.create`, so nothing creates a place by hand
| (a place exists because a proposal has it, D-84/D-87). They are named in
| the Y-17 handoff: creating a dateless draft with independent targets,
| normalising nullable details, the create form's required fields, and a
| centre HR does not know.
*/

test('the edit form loads what the place delivers and saves it, keeping its status', function () {
    // Y-17: the form changes the description, the five targets and the
    // sectors. The name, the placement, the owner and the dates are not
    // offered — a place is named by where it is, placed by its proposal and
    // dated by the grant.
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(projectWithRow([
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
    ]));

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openEditModal', $row->id)
        ->assertSet('description', 'Original scope')
        ->assertSet('targetBeneficiaries', '500')
        ->assertSet('targetMen', '100')
        ->assertSet('targetWomen', '120')
        ->assertSet('targetBoys', '80')
        ->assertSet('targetGirls', '90')
        ->assertDontSeeHtml('wire:model.live.debounce.400ms="name"')
        ->assertDontSeeHtml('wire:model.live="cityId"')
        ->assertDontSeeHtml('wire:model="userId"')
        ->set('description', 'Revised scope')
        ->set('targetMen', '110')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($row->fresh())
        ->description->toBe('Revised scope')
        ->target_men->toBe(110)
        ->status->toBe(ProjectStatus::Approved)
        ->start_date->toDateString()->toBe('2026-01-01');
});

test('the project directory offers no delete action', function () {
    $user = User::factory()->role('General Manager')->create();
    projectWithRow();

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
 * One location row per triage lens, each tripping exactly one of them.
 *
 * @return array<string, ProposalLocation>
 */
function projectsForEachLens(): array
{
    // Y-16 (D-93): every row has a proposal, so "unfunded" is a row whose
    // proposal carries no donor agreement.
    $fund = function (ProposalLocation $row): ProposalLocation {
        DonorProposal::factory()->create(['proposal_id' => $row->proposal_id, 'budget' => '1000.00']);

        return $row;
    };

    $unfunded = rowFor(Project::factory()->active()->create(['name' => 'Unfunded Wells', 'end_date' => today()->addYear()]));

    $overdue = $fund(rowFor(Project::factory()->active()->create(['name' => 'Overdue Clinic', 'start_date' => today()->subYears(2), 'end_date' => today()->subDay()])));

    $risky = $fund(rowFor(Project::factory()->active()->create(['name' => 'Risky Bakery', 'end_date' => today()->addYear()])));
    Risk::factory()->happened()->create(['project_id' => $risky->project_id, 'proposal_location_id' => $risky->id]);

    return ['unfunded' => $unfunded, 'overdue' => $overdue, 'risks' => $risky];
}

test('each triage lens selects only the projects that trip it', function (string $lens, string $expected) {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $component = Livewire::actingAs($user)->test('pages::projects.index')->set('lens', $lens);

    expect($component->instance()->projects->getCollection()->map(fn (ProposalLocation $row): string => $row->headline())->all())->toBe([$expected]);
})->with([
    ['unfunded', 'Unfunded Wells'],
    ['overdue', 'Overdue Clinic'],
    ['risks', 'Risky Bakery'],
]);

test('an unknown lens filters nothing away', function () {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $component = Livewire::actingAs($user)->test('pages::projects.index')->set('lens', 'not-a-lens');

    expect($component->get('lens'))->toBe('')
        ->and($component->instance()->projects->total())->toBe(3);
});

test('triage counts ignore the active lens so a tile never shrinks itself', function () {
    $user = User::factory()->role('General Manager')->create();
    projectsForEachLens();

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->triageCounts)->toBe(['unfunded' => 1, 'overdue' => 1, 'risks' => 1]);

    $component->set('lens', 'overdue');

    expect($component->instance()->triageCounts)->toBe(['unfunded' => 1, 'overdue' => 1, 'risks' => 1])
        ->and($component->instance()->projects->total())->toBe(1);
});

test('the active triage lens reads as pressed', function () {
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
        ->and($activeLens->getAttribute('class'))->toContain('dk-chip');
});

test('lifecycle counts ignore the status filter but respect every other filter', function () {
    $user = User::factory()->role('General Manager')->create();
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');

    rowFor(Project::factory()->active()->create(['city_id' => $aleppo]));
    rowFor(Project::factory()->closed()->create(['city_id' => $aleppo]));
    rowFor(Project::factory()->active()->create(['city_id' => $homs]));

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->statusCounts)->toBe(['active' => 2, 'closed' => 1]);

    $component->set('status', ProjectStatus::Active->value);

    expect($component->instance()->statusCounts)->toBe(['active' => 2, 'closed' => 1]);

    $component->set('city', (string) $aleppo);

    expect($component->instance()->statusCounts)->toBe(['active' => 1, 'closed' => 1]);
});

test('committed funding counts a proposal shared by two places once', function () {
    $user = User::factory()->role('General Manager')->create();
    $proposal = Proposal::factory()->create();
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'budget' => '1000.00']);

    foreach (Project::factory()->count(2)->create() as $project) {
        rowFor($project, $proposal);
    }

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->committedFunding)->toBe(['USD' => 1000.0]);
});

test('committed funding reports one line per currency and never adds them together', function () {
    $user = User::factory()->role('General Manager')->create();
    // One agreement per proposal, and one proposal per row (D-93), so two
    // currencies mean two places.
    foreach ([Currency::Usd, Currency::Eur] as $currency) {
        $row = rowFor(Project::factory()->create());
        DonorProposal::factory()->create(['proposal_id' => $row->proposal_id, 'budget' => '2500.00', 'currency' => $currency]);
    }

    $funding = Livewire::actingAs($user)->test('pages::projects.index')->instance()->committedFunding;

    ksort($funding);

    expect($funding)->toBe(['EUR' => 2500.0, 'USD' => 2500.0]);
});

test('every view renders the same filtered project set and an unknown view falls back to the list', function () {
    $user = User::factory()->role('General Manager')->create();
    rowFor(Project::factory()->active()->create(['name' => 'Aleppo Wells']));
    rowFor(Project::factory()->closed()->create(['name' => 'Homs Shelter']));

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    foreach (['list', 'timeline'] as $view) {
        $component->set('view', $view)->assertSee('Aleppo Wells')->assertSee('Homs Shelter');
    }

    $component->set('view', 'board');

    expect($component->get('view'))->toBe('list');

    $component->assertSee('Aleppo Wells')->assertSee('Homs Shelter');
});

test('projects can be sorted by name dates and recency with undated projects last', function () {
    $user = User::factory()->role('General Manager')->create();
    projectWithRow(['name' => 'Beta', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'updated_at' => '2026-01-01 00:00:00']);
    projectWithRow(['name' => 'Alpha', 'start_date' => '2026-06-01', 'end_date' => '2026-07-31', 'updated_at' => '2026-02-01 00:00:00']);
    projectWithRow(['name' => 'Gamma', 'start_date' => null, 'end_date' => null, 'updated_at' => '2026-03-01 00:00:00']);

    $component = Livewire::actingAs($user)->test('pages::projects.index')->set('view', 'list');

    $order = fn (): array => $component->instance()->projects->getCollection()->map(fn (ProposalLocation $row): string => $row->headline())->all();

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

test('the list pages projects in twenties and the timeline in fifteens', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->count(25)->create()->each(fn (Project $project) => rowFor($project));

    $component = Livewire::actingAs($user)->test('pages::projects.index');

    expect($component->instance()->projects->perPage())->toBe(20)
        ->and($component->instance()->projects->count())->toBe(20);

    $component->set('view', 'timeline');

    expect($component->instance()->projects->perPage())->toBe(15);
});

test('an old board or table link opens the list', function (string $oldView) {
    $user = User::factory()->role('General Manager')->create();

    Livewire::withQueryParams(['view' => $oldView])
        ->actingAs($user)
        ->test('pages::projects.index')
        ->assertSet('view', 'list');
})->with(['board', 'table']);

test('the project directory renders within its query budget', function () {
    $user = User::factory()->role('General Manager')->create();
    Project::factory()->count(5)->create()->each(fn (Project $project) => rowFor($project));

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::actingAs($user)->test('pages::projects.index');

    // 17 + one query that loads every owner assignment for the Rejected badge
    // + one load of the rows' centers, which label() names (Y-16: the project
    //   carried a single center_id column and needed no join)
    // + one count of the projects without a center (shown to managers)
    // + the two HR queries of EmployeeProfile behind the owner chips' positions
    // + the sector filter's options and the list preview's sector eager load (T34).
    // + one read of the HR centers lookup for the «Center inactive» badge (W-02), flat however many rows.
    // + one load of the linked proposals' statuses for the funding badge (T08).
    // + one read of the program name lookup, which W-34b moved from the HR
    //   connection onto ours (D-68); flat however many projects are listed.
    expect($queries)->toBeLessThanOrEqual(27);
});

test('a list preview names only the relations a project has and flags a materialised risk', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));
    Risk::factory()->count(2)->create(['project_id' => $row->project_id, 'proposal_location_id' => $row->id, 'happened' => false]);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeText(trans_choice('programs.timeline_open_risks', 2, ['count' => 2]))
        ->assertDontSeeHtml('dk-pjl-link dk-bad');

    Risk::factory()->create(['project_id' => $row->project_id, 'proposal_location_id' => $row->id, 'happened' => true]);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeHtml('dk-pjl-link dk-bad');
});

test('a list preview names the one proposal a bare row carries and no empty chips', function () {
    $user = User::factory()->role('General Manager')->create();
    rowFor(Project::factory()->active()->create(['name' => 'Bare Project']));

    // Y-16 (D-84, D-87): a row exists only under a proposal, so the preview
    // always has that one relation to name and never reads as "nothing".
    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeText(trans_choice('projects.count_proposal', 1, ['count' => 1]))
        ->assertDontSeeText('Nothing linked yet')
        ->assertDontSeeHtml('aria-hidden="true">0</span>');
});

test('a list row opens the project through an eye action, not a clickable name', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeHtml('aria-label="Open Wells Of Hope"')
        ->assertSeeHtml('href="'.route('projects.show', $row).'"')
        // The name is plain text; nothing stretches a link across the card.
        ->assertDontSeeHtml('after:absolute after:inset-0');
});

test('a list row never prints the progress bar accessibility label as visible copy', function () {
    $user = User::factory()->role('General Manager')->create();
    rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));
    $rendered = Livewire::actingAs($user)->test('pages::projects.index');

    // The name still reaches assistive tech through aria-label, but is not drawn.
    $rendered->assertSeeHtml('aria-label="Progress for Wells Of Hope"')
        ->assertDontSeeText('Progress for Wells Of Hope');
});

test('a list row rates an overdue project critical', function () {
    $user = User::factory()->role('General Manager')->create();
    rowFor(Project::factory()->active()->create([
        'name' => 'Overdue Project',
        'start_date' => now()->subYear(),
        'end_date' => now()->subMonth(),
    ]));

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeHtml('dk-pjl-row dk-pjl-alert')
        ->assertSeeHtml('dk-health dk-bad')
        ->assertSeeText(__('programs.timeline_past_end'));
});

test('every view opens a project through the same eye action instead of a clickable name', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));
    $showUrl = route('projects.show', $row);

    foreach (['list', 'timeline'] as $view) {
        Livewire::actingAs($user)->test('pages::projects.index')
            ->set('view', $view)
            ->assertSeeHtml('aria-label="Open Wells Of Hope"')
            ->assertSeeHtml('href="'.$showUrl.'"')
            // The name is plain text in every view; only the eye navigates.
            ->assertDontSeeHtml('href="'.$showUrl.'" wire:navigate.hover title="Wells Of Hope"');
    }
});

test('a place is archived rather than deleted, keeping every record it carries', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));
    Risk::factory()->create(['project_id' => $row->project_id, 'proposal_location_id' => $row->id]);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->call('confirmArchive', $row->id)
        // The dialog only opens from the boolean, so both must be set.
        ->assertSet('archivingProjectId', $row->id)
        ->assertSet('showArchiveModal', true)
        ->call('archiveProject')
        ->assertSet('archivingProjectId', null)
        ->assertSet('showArchiveModal', false);

    expect($row->fresh()->status)->toBe(ProjectStatus::Archived)
        // Archiving destroys nothing.
        ->and(Risk::query()->where('proposal_location_id', $row->id)->count())->toBe(1);
});

test('an archived place can be restored back into the portfolio as a draft', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->create(['name' => 'Wells Of Hope', 'status' => ProjectStatus::Archived]));

    Livewire::actingAs($user)->test('pages::projects.index')->call('restoreProject', $row->id);

    expect($row->fresh()->status)->toBe(ProjectStatus::Draft);
});

test('restoring only applies to archived places', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));

    Livewire::actingAs($user)->test('pages::projects.index')->call('restoreProject', $row->id);

    expect($row->fresh()->status)->toBe(ProjectStatus::Active);
});

test('cancelling the archive confirmation leaves the project where it was', function () {
    $user = User::factory()->role('General Manager')->create();
    $project = Project::factory()->active()->create(['name' => 'Wells Of Hope']);
    $row = rowFor($project);

    Livewire::actingAs($user)->test('pages::projects.index')
        ->call('confirmArchive', $row->id)
        ->call('cancelArchive')
        ->assertSet('archivingProjectId', null)
        ->assertSet('showArchiveModal', false);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('the archive confirmation names the work the project keeps', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));
    Risk::factory()->count(3)->create(['project_id' => $row->project_id, 'proposal_location_id' => $row->id]);

    $component = Livewire::actingAs($user)->test('pages::projects.index')->call('confirmArchive', $row->id);
    $pending = $component->instance()->archivingProject;

    // A row is of exactly one proposal (D-93), so it always carries that one.
    expect($pending['carries'])->toBe(['Risks' => 3, 'Proposals' => 1]);

    $component->assertSeeText('Archive this project?')
        ->assertSeeText('3 Risks');
});

test('archiving a project that vanished mid-confirmation closes cleanly instead of erroring', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));

    $component = Livewire::actingAs($user)->test('pages::projects.index')->call('confirmArchive', $row->id);

    ProposalLocation::query()->whereKey($row->id)->update(['project_id' => null]);

    $component->call('archiveProject')
        ->assertSet('archivingProjectId', null)
        ->assertSet('showArchiveModal', false)
        ->assertHasNoErrors();
});

test('every view offers archive for a live project and restore for an archived one', function () {
    $user = User::factory()->role('General Manager')->create();
    $live = rowFor(Project::factory()->active()->create(['name' => 'Wells Of Hope']));
    $archived = rowFor(Project::factory()->create(['name' => 'Retired Project', 'status' => ProjectStatus::Archived]));

    foreach (['list', 'timeline'] as $view) {
        Livewire::actingAs($user)->test('pages::projects.index')
            ->set('view', $view)
            ->assertSeeHtml('confirmArchive('.$live->id.')')
            ->assertSeeHtml('Archive Wells Of Hope')
            ->assertSeeHtml('restoreProject('.$archived->id.')')
            ->assertSeeHtml('Restore Retired Project');
    }
});

test('the place form lays its fields along a rail with no pickers and no native selects', function () {
    // Y-17: the form offers the description, the sectors and the five
    // targets. The placement pickers (centre, city, host centre, programme,
    // owner) are gone with the create path, so the only `data-form-select`
    // left is none at all.
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(projectWithRow());

    $html = Livewire::actingAs($user)->test('pages::projects.index')->call('openEditModal', $row->id)->html();

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);
    $dialog = '//dialog[contains(@class, "modal-shell")][.//*[@data-test="save-project-button"]]';
    $headings = array_map(
        fn (DOMNode $heading): string => trim($heading->textContent),
        iterator_to_array($xpath->query($dialog.'//*[contains(@class, "form-section-title")]')),
    );

    expect($xpath->query($dialog)->length)->toBe(1)
        ->and($headings)->toBe(['Details', 'Placement', 'Beneficiaries'])
        ->and($xpath->query($dialog.'//section[@data-optional]//*[contains(@class, "form-section-title")]')->item(0)?->textContent)->toBe('Beneficiaries')
        ->and($xpath->query($dialog.'//select')->length)->toBe(0)
        ->and($xpath->query($dialog.'//*[@data-form-select]')->length)->toBe(0)
        ->and($xpath->query($dialog.'[@*[name()="wire:close"]="closeProjectModal"]')->length)->toBe(1)
        ->and($xpath->query($dialog.'//form[@*[name()="wire:submit"]="saveProject"]')->length)->toBe(1);
});

test('a negative or fractional target is refused and the place is left alone', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(projectWithRow(['target_beneficiaries' => 500]));

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openEditModal', $row->id)
        ->set('targetBeneficiaries', '-1')
        ->set('targetMen', '1.5')
        ->set('targetWomen', '-2')
        ->set('targetBoys', 'not-a-number')
        ->set('targetGirls', '-3')
        ->call('saveProject')
        ->assertHasErrors(['targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls']);

    expect($row->fresh()->target_beneficiaries)->toBe(500);
});

test('editing a place shows where it is, read-only', function () {
    $user = User::factory()->role('General Manager')->create();
    $city = insertHrCity('Idlib', 'إدلب');
    $row = rowFor(Project::factory()->create([
        'status' => ProjectStatus::Active,
        'city_id' => $city,
        'center_id' => insertHrCenter($city, 'Hope Center Idlib', 'مركز الأمل إدلب'),
        'program_id' => Program::factory()->create()->id,
        'user_id' => insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad', firstNameAr: 'لينا', lastNameAr: 'حداد'),
    ]));

    Livewire::actingAs($user)->test('pages::projects.index')
        ->call('openEditModal', $row->id)
        ->assertSet('editingPlaceLabel', $row->label())
        ->assertSeeText($row->label())
        // The grant sets the period; the dialog says so rather than offering it.
        ->assertSeeText('Project dates come from the approved grant')
        ->assertDontSeeHtml('data-form-select="centerId"')
        ->assertDontSeeHtml('data-form-select="userId"');
});

test('the place form is rendered only while its modal is open', function () {
    $user = User::factory()->role('General Manager')->create();
    $row = rowFor(projectWithRow());

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertDontSeeHtml('wire:model="targetBeneficiaries"')
        ->call('openEditModal', $row->id)
        ->assertSeeHtml('wire:model="targetBeneficiaries"');
});

/*
| Y-16 (D-88): a row has no host center. A place with no center of its own is
| field work across the city, which the row says itself, so the directory
| names the place and never "hosted by".
*/

test('a city-wide row reads as field work and is not counted as unplaced', function () {
    $user = User::factory()->role('General Manager')->create();
    $idleb = insertHrCity('Idleb', 'إدلب');
    $aleppo = insertHrCity('Aleppo', 'حلب');
    $center = insertHrCenter($aleppo, 'Head Office Aleppo', 'المكتب الرئيسي حلب');
    $field = rowFor(Project::factory()->create(['name' => 'Wells Idleb', 'city_id' => $idleb]));
    $field->update(['city_wide' => true]);
    rowFor(Project::factory()->create(['name' => 'Aleppo Clinics', 'city_id' => $aleppo, 'center_id' => $center]));

    Livewire::actingAs($user)->test('pages::projects.index')
        ->assertSeeText('Field work')
        ->assertSeeText('Head Office Aleppo')
        ->assertDontSeeText('Projects without a center');
});

test('places add no query per row to the directory', function () {
    $user = User::factory()->role('General Manager')->create();
    $center = insertHrCenter(insertHrCity('Aleppo', 'حلب'), 'Head Office Aleppo');
    rowFor(Project::factory()->create(['center_id' => $center]));

    $countQueries = function () use ($user): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::actingAs($user)->test('pages::projects.index');

        return $queries;
    };

    $withOne = $countQueries();
    Project::factory()->count(4)->create(['center_id' => $center])->each(fn (Project $project) => rowFor($project));

    expect($countQueries())->toBeLessThanOrEqual($withOne);
});
