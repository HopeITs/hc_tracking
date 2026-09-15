# Projects index · tests

File: `tests/Feature/ProjectDirectoryTest.php`. Run: `./php.cmd artisan test --compact tests/Feature/ProjectDirectoryTest.php`.

## 1. Existing tests: must stay green without edits

| Test name | Why it still passes |
| --- | --- |
| `guests are redirected from the project directory` | route/middleware unchanged |
| `authenticated users see the project directory` | title "Projects" unchanged |
| `projects can be filtered by search city program owner and status` | `search/city/program/owner/status` properties and `clearFilters()` unchanged (the status **select** is removed, but the test sets the property) |
| `the status URL filter selects projects by lifecycle state` | `#[Url] status` unchanged |
| `a project can be created with lifecycle dates and independent beneficiary targets` | modal props and `saveProject()` unchanged |
| `nullable project details are normalized before saving` | unchanged |
| `creating a project requires a name a city a program and an owner` | unchanged |
| `a project cannot be placed in a city that does not exist in hr` | unchanged |
| `project lifecycle dates and targets reject invalid values` | unchanged |
| `a project can be renamed relocated and moved through its lifecycle` | `openEditModal` unchanged |
| `the project directory offers no delete action` | no delete added; make sure no new string contains `Delete project` |
| `the project directory renders with no projects` | empty state still uses `common.no_projects_yet` ("No projects yet") |

If any of these fails, fix the page, not the test.

## 2. New tests (add to the same file)

Add imports at the top if missing: `use App\Enums\MilestoneStatus;`, `use App\Models\Milestone;`, `use App\Models\Risk;`, `use Illuminate\Support\Carbon;`.

> Check the factories before writing: `Milestone::factory()`, `Risk::factory()` exist (see `database/factories`). If a factory lacks a state you need, pass attributes explicitly as below; don't edit factories (Foundation rule).

```php
test('the lifecycle rail counts projects per status and ignores the status filter', function () {
    $user = User::factory()->create();
    $aleppo = insertHrCity('Aleppo');
    $homs = insertHrCity('Homs');

    Project::factory()->active()->count(2)->create(['city_id' => $aleppo]);
    Project::factory()->closed()->create(['city_id' => $aleppo]);
    Project::factory()->active()->create(['city_id' => $homs]);

    $component = Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('city', (string) $aleppo)
        ->set('status', ProjectStatus::Closed->value);

    expect($component->instance()->statusCounts)
        ->toMatchArray([ProjectStatus::Active->value => 2, ProjectStatus::Closed->value => 1]);
});

test('selecting the active lifecycle segment again clears the status filter', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('status', ProjectStatus::Active->value)
        ->assertSeeHtml('aria-pressed="true"')
        ->set('status', '')
        ->assertSet('status', '');
});

test('milestone progress weighs reached milestones and excludes cancelled ones', function () {
    $user = User::factory()->create();
    $project = Project::factory()->active()->create(['name' => 'Weighted Wells']);

    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Reached, 'weight_percentage' => 30]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Pending, 'weight_percentage' => 70]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::Cancelled, 'weight_percentage' => 50]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSeeHtml('aria-valuenow="30"')
        ->assertSee('1 of 3 milestones reached');
});

test('a project without weighted milestones shows no progress value', function () {
    $user = User::factory()->create();
    Project::factory()->active()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSee('No weighted milestones');
});

test('active projects past their end date are counted and can be isolated', function () {
    Carbon::setTestNow('2026-09-15');
    $user = User::factory()->create();

    Project::factory()->active()->create(['name' => 'Late Network', 'end_date' => '2026-09-01']);
    Project::factory()->active()->create(['name' => 'On Time Network', 'end_date' => '2026-12-01']);
    Project::factory()->closed()->create(['name' => 'Closed Late Network', 'end_date' => '2026-01-01']);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSee('1 active project is past its end date')
        ->set('overdue', true)
        ->assertSee('Late Network')
        ->assertDontSee('On Time Network')
        ->assertDontSee('Closed Late Network')
        ->call('clearFilters')
        ->assertSet('overdue', false)
        ->assertSee('On Time Network');

    Carbon::setTestNow();
});

test('projects can be sorted by end date with undated projects last', function () {
    $user = User::factory()->create();

    Project::factory()->create(['name' => 'Alpha Undated', 'end_date' => null]);
    Project::factory()->create(['name' => 'Beta Late', 'end_date' => '2027-06-30']);
    Project::factory()->create(['name' => 'Gamma Soon', 'end_date' => '2026-10-31']);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('sort', 'end_date')
        ->assertSeeInOrder(['Gamma Soon', 'Beta Late', 'Alpha Undated']);
});

test('an unknown sort value falls back to name order', function () {
    $user = User::factory()->create();

    Project::factory()->create(['name' => 'Zeta']);
    Project::factory()->create(['name' => 'Alpha']);

    $this->actingAs($user)
        ->get(route('projects.index', ['sort' => 'drop table']))
        ->assertOk()
        ->assertSeeInOrder(['Alpha', 'Zeta']);
});

test('the cards view lists the same projects as the table view', function () {
    $user = User::factory()->create();
    Project::factory()->create(['name' => 'Card Project']);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->set('view', 'cards')
        ->assertSeeHtml('wire:key="project-card-')
        ->assertSee('Card Project')
        ->set('view', 'nonsense')
        ->assertSet('view', 'table');
});

test('a materialised risk marks the project risk signal as critical', function () {
    $user = User::factory()->create();
    $project = Project::factory()->active()->create();
    Risk::factory()->create(['project_id' => $project->id, 'happened' => true]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSee('1 risk, 1 materialised');
});

test('the project row status renders as a status block with its tone', function () {
    $user = User::factory()->create();
    Project::factory()->active()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSeeHtml('data-tone="good"');
});

test('the directory stays within its query budget', function () {
    $user = User::factory()->create();
    Project::factory()->count(20)->create();

    DB::enableQueryLog();

    $this->actingAs($user)->get(route('projects.index'))->assertOk();

    // Budget: README §6. Allow the auth/session queries of the request on top.
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(16);
});
```

(Add `use Illuminate\Support\Facades\DB;` for the last test. HR tables live on a second connection in tests. If `DB::getQueryLog()` only captures the default connection, enable the log on both connections: `DB::connection('hr')->enableQueryLog()` — check `config/database.php` for the HR connection name — and sum the two logs.)

## 3. Other suites to run before review

- `tests/Feature/LocalizationTest.php` (new keys in en + ar)
- `tests/Feature/UiComponentsTest.php` (components you rely on)
- `tests/Feature/NavigationTest.php` (sidebar current item on `/projects`)
- Full suite: `./php.cmd artisan test --compact`
