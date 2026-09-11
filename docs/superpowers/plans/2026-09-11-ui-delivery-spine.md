# Delivery Spine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `projects`, `risks`, `milestones`, `tasks`, `task_updates` and `task_attachments` reachable, readable and — where deletion is safe — writable.

**Architecture:** Livewire 4 single-file pages assembled from the `x-ui.*` components built in the Foundation plan. Projects and Tasks are hubs with detail pages; Risks and Milestones get cross-cutting indexes plus panels on Project detail. The milestone tree renders through `HasTree::childrenRecursive`, and its parent picker excludes the node's own subtree so `MilestoneObserver`'s `InvalidParentException` stays a backstop rather than the primary guard.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Flux 2, Tailwind 4, Pest 5.

**Depends on:** `docs/superpowers/plans/2026-09-11-ui-foundation.md` — all 8 tasks complete. Task 7 Step 5 of that plan guards `projects.show` behind `Route::has()`; **Task 2 of this plan removes that guard** once the route exists.

## Global Constraints

Identical to the Foundation plan. Repeated here because a task's implementer may read this file alone.

- **Strict mode is on.** `Model::shouldBeStrict(! app()->isProduction())`. Lazy loading throws. Every relation a Blade view touches is eager-loaded in the `#[Computed]` query, nested paths included.
- **Palette is fixed:** `primary #be1f24`, `secondary-dark #58595b`, `secondary-medium #6d6e71`, `secondary-light #939598`, `secondary-soft #dcddde`. No green, no amber. Badge tone is palette-only: filled primary = terminal-negative, filled dark = active/achieved, outlined = pending/neutral.
- **Morph keys, never class names:** `'yearly_plan'`, `'proposal'`, `'project'`, `'milestone'`.
- **The morph map holds four entries**, not two — widened by the DB schema update below for the new `media` table. `MorphMapTest` asserts the map holds exactly these four. No fifth is added in this plan.
- **Money is never summed across currencies.** Not relevant to this spine, but it holds.
- **No further schema changes.** The additions below (Project lifecycle, demographics, `media`) are already migrated, seeded and tested. No migration, model, observer, factory or seeder is modified while executing these tasks.
- **Enum values are lower case** (`'todo'`, `'in progress'`), except `Currency` (upper) and `UserRole` (`'MEAL'`). Display capitalisation is the UI's job. A value differing only by case throws `ValueError` on read.
- **Tests** live at `tests/Feature/<Feature>Test.php`.
- **Pint** before any task is done: `vendor/bin/pint --dirty --format agent`.
- **Never commit.** Each task stages files and stops for the user's review.

## DB Schema Update (2026-09-11)

Implemented, migrated, seeded and tested before this plan runs — not a task
within it. Both changes below land directly in `projects` and `milestones`, so
Tasks 1–2 (Projects) and Task 4 (Milestones) build against the new columns from
their first step, not as a later addition.

**`projects` gained a lifecycle and a beneficiary target.** New columns:
`description` (text, nullable), `status` (`ProjectStatus` enum — draft →
submitted → approved → active → closed → archived, NOT NULL, defaults
`draft`), `start_date`/`end_date` (nullable dates), `target_beneficiaries`
(nullable int, the headline total) and four nullable breakdown columns
`target_men`/`target_women`/`target_boys`/`target_girls`. The breakdown is
**five discrete integer columns, not a JSON blob** — MariaDB implements
`$table->json()` as an unindexable, unsummable longtext (`.ai/rules/feature.md`),
and donor reporting needs `SUM()` over these routinely. `target_beneficiaries`
is deliberately **not** a generated column over the four breakdown columns — a
proposal often commits a total before the disaggregation is agreed, and the
two are allowed to disagree. `Project::targetDemographicTotal()` sums the four
and returns `null` (not `0`) when none are set; render an em dash for that
case, not a zero, since "no breakdown yet" and "targets nobody" are different
claims. `Project::scopeOpen()` exists for filtering to the first four
lifecycle states.

**Obligation for Task 1 (Projects index) and Task 2 (Project detail).** The
create/edit form needs fields for `description`, `status` (a `ProjectStatus`
select), `start_date`/`end_date`, and the beneficiary targets. The index and
detail pages should filter/display `status` via `x-ui.enum-badge` (extend its
tone-match with a `ProjectStatus` arm — filled dark for Active, outlined for
Draft/Submitted/Approved, filled primary for none since there's no
terminal-negative state here, or leave outlined throughout since nothing in
this lifecycle is a failure state; the spec's original write permissions for
Projects — create/edit, no delete, because cascades reach risks/milestones/
tasks — are unchanged by this addition). The task's original test list should
gain a case asserting the demographic-total-vs-headline-total disagreement is
preserved, not silently reconciled.

**A polymorphic `media` table now exists, attaching files to `projects` and
`milestones`.** `mediable_type` stores the morph map key (`'project'` /
`'milestone'`), never a class name. Columns: `collection` (`MediaCollection`
enum — proposal, agreement, mou, photo, evidence, other), `disk` (defaults
`local` — no cloud driver is installed), `path`, `original_name`, `mime_type`,
`size`, `type` (`FileType` — the extension, same enum `task_attachments` and
`report_files` already use), `uploaded_at`, `uploaded_by` (RESTRICTs). Like
`task_attachments` and `report_files`, **no file exists on disk** —
`storage/app` holds nothing but `.gitignore` files, so any UI panel is metadata
only: filename, type badge, upload date. No download link, no upload control,
matching the spec's original rule for the two file tables it already covered.
`MediaOwnerObserver` deletes a project's or milestone's media on Eloquent
delete, including the hop through cascaded child milestones — `mediable_id`
carries no foreign key, so nothing at the database level does this, exactly
the situation `.ai/rules/observers.md` already documents for
`PlanIndicatorSourceObserver`.

**Obligation for Task 2 (Project detail) and Task 4 (Milestones).** Both
detail views should add a "Files" panel reading `$project->media` /
`$milestone->media`, rendered the same metadata-only way as the existing
Task-detail attachments panel in this same plan (Task 6). This extends the
spec's page inventory by one panel per page, not a new route — no change to
the write-permissions table.

---

## Delete rules governing this spine

Read before writing any delete action. Taken from the migrations, not from memory.

| Column | Rule | Consequence for the UI |
| --- | --- | --- |
| `risks.project_id` | cascade | Deleting a project silently removes its risks |
| `milestones.project_id` | cascade | Same |
| `milestones.parent_milestone_id` | cascade | Deleting a parent milestone removes its whole subtree |
| `milestones.responsible_user_id` | **restrict** | A user responsible for a milestone cannot be deleted |
| `tasks.milestone_id` | **nullOnDelete** | Deleting a milestone orphans its tasks into "No milestone" |
| `tasks.proposal_id` | cascade | Deleting a proposal removes its tasks |
| `tasks.assigned_to`, `tasks.created_by` | **restrict** | Both users are protected |
| `task_updates.task_id`, `task_attachments.task_id` | cascade | A task's own children go with it — this is why Tasks may be deleted |
| `task_updates.user_id` | **restrict** | Comment authors are protected |

Therefore: **Projects have no delete.** **Milestones delete only when leaf and taskless.** **Risks and Tasks delete freely.**

---

## File Structure

| File | Responsibility |
| --- | --- |
| `resources/views/pages/projects/⚡index.blade.php` | Projects directory, filters, create/edit modal |
| `resources/views/pages/projects/⚡show.blade.php` | Project detail: milestones tree, risks, targets, proposals |
| `resources/views/pages/risks/⚡index.blade.php` | Risk register, full CRUD |
| `resources/views/pages/milestones/⚡index.blade.php` | Milestone directory grouped by project, CRUD, guarded delete |
| `resources/views/pages/tasks/⚡index.blade.php` | Task directory, filters, full CRUD |
| `resources/views/pages/tasks/⚡show.blade.php` | Task detail: updates timeline, attachments, metadata |
| `tests/Feature/ProjectDirectoryTest.php` | Projects index |
| `tests/Feature/ProjectDetailTest.php` | Project detail and its panels |
| `tests/Feature/RiskRegisterTest.php` | Risks |
| `tests/Feature/MilestoneDirectoryTest.php` | Milestones, tree and delete guard |
| `tests/Feature/TaskDirectoryTest.php` | Tasks index |
| `tests/Feature/TaskDetailTest.php` | Task detail, updates, attachments |

Modified: `routes/web.php`, `resources/views/components/navigation/sidebar.blade.php`, `resources/views/pages/programs/⚡show.blade.php` (guard removal).

---

### Task 1: Projects index

**Files:**
- Create: `resources/views/pages/projects/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProjectDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.input`, `x-ui.select`, `x-ui.button`, `x-ui.user-chip`.
- Produces: route `projects.index`; component `pages::projects.index`; public properties `$search`, `$city`, `$program`, `$owner` (filters), `$showProjectModal`, `$editingProjectId`, `$name`, `$cityId`, `$programId`, `$userId`; methods `openCreateModal()`, `openEditModal(int)`, `saveProject()`, `closeProjectModal()`, `clearFilters()`.

`projects.program_id` is NOT NULL, so there is no "unassigned" state here — `.ai/rules/executive-summary.md` records that this ratio is 100% forever and must not be turned into a coverage card.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\City;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the project directory', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('authenticated users see the project directory', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertSeeText('Projects');
});

test('projects can be filtered by search city and program', function () {
    $user = User::factory()->create();
    $aleppo = City::factory()->create(['name' => 'Aleppo']);
    $homs = City::factory()->create(['name' => 'Homs']);
    $water = Program::factory()->create(['name' => 'Water Security']);
    $shelter = Program::factory()->create(['name' => 'Safe Shelter']);

    Project::factory()->create(['name' => 'Aleppo Wells', 'city_id' => $aleppo->id, 'program_id' => $water->id]);
    Project::factory()->create(['name' => 'Homs Shelter', 'city_id' => $homs->id, 'program_id' => $shelter->id]);

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertSee('Aleppo Wells')
        ->assertSee('Homs Shelter')
        ->set('search', 'Wells')
        ->assertSee('Aleppo Wells')
        ->assertDontSee('Homs Shelter')
        ->call('clearFilters')
        ->set('city', (string) $homs->id)
        ->assertDontSee('Aleppo Wells')
        ->assertSee('Homs Shelter')
        ->call('clearFilters')
        ->set('program', (string) $water->id)
        ->assertSee('Aleppo Wells')
        ->assertDontSee('Homs Shelter')
        ->call('clearFilters')
        ->assertSee('Aleppo Wells')
        ->assertSee('Homs Shelter');
});

test('a project can be created with a trimmed name', function () {
    $user = User::factory()->create();
    $city = City::factory()->create();
    $program = Program::factory()->create();
    $owner = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('name', '  Idlib Water Network  ')
        ->set('cityId', (string) $city->id)
        ->set('programId', (string) $program->id)
        ->set('userId', (string) $owner->id)
        ->call('saveProject')
        ->assertHasNoErrors()
        ->assertSet('showProjectModal', false);

    $project = Project::query()->where('name', 'Idlib Water Network')->sole();

    expect($project->city_id)->toBe($city->id)
        ->and($project->program_id)->toBe($program->id)
        ->and($project->user_id)->toBe($owner->id);
});

test('creating a project requires a name a city a program and an owner', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('cityId', '')
        ->set('programId', '')
        ->set('userId', '')
        ->call('saveProject')
        ->assertHasErrors(['name', 'cityId', 'programId', 'userId']);
});

test('a project can be renamed and relocated', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Old Name']);
    $newCity = City::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('cityId', (string) $newCity->id)
        ->call('saveProject')
        ->assertHasNoErrors();

    $project->refresh();

    expect($project->name)->toBe('New Name')->and($project->city_id)->toBe($newCity->id);
});

test('the project directory offers no delete action', function () {
    $user = User::factory()->create();
    Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->assertDontSee('wire:click="deleteProject')
        ->assertDontSee('Delete project');
});

test('the project directory renders with no projects', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::projects.index')->assertSee('No projects yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ProjectDirectoryTest.php`
Expected: FAIL — `Route [projects.index] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `['auth', 'verified']` group, after the `yearly-plans` registrations:

```php
    Route::livewire('projects', 'pages::projects.index')
        ->name('projects.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/projects/⚡index.blade.php`:

```blade
<?php

use App\Models\City;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Projects')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $owner = '';

    public bool $showProjectModal = false;
    public ?int $editingProjectId = null;
    public string $name = '';
    public string $cityId = '';
    public string $programId = '';
    public string $userId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->city = '';
        $this->program = '';
        $this->owner = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Project::query()
            ->select(['id', 'name', 'city_id', 'program_id', 'user_id', 'updated_at'])
            ->with([
                'city:id,name',
                'program:id,name',
                'user:id,name,email,role',
            ])
            ->withCount(['risks', 'milestones', 'planIndicators', 'proposals'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('city', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('program', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('user', function ($query) use ($search): void {
                            $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($this->city !== '', fn ($query) => $query->where('city_id', (int) $this->city))
            ->when($this->program !== '', fn ($query) => $query->where('program_id', (int) $this->program))
            ->when($this->owner !== '', fn ($query) => $query->where('user_id', (int) $this->owner))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, City> */
    #[Computed]
    public function cityOptions(): Collection
    {
        return City::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function userOptions(): Collection
    {
        return User::query()->select(['id', 'name', 'email', 'role'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetProjectForm();
        $this->showProjectModal = true;
    }

    public function openEditModal(int $projectId): void
    {
        $project = Project::query()
            ->select(['id', 'name', 'city_id', 'program_id', 'user_id'])
            ->findOrFail($projectId);

        $this->resetValidation();
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->cityId = (string) $project->city_id;
        $this->programId = (string) $project->program_id;
        $this->userId = (string) $project->user_id;
        $this->showProjectModal = true;
    }

    public function saveProject(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'cityId' => ['required', 'integer', Rule::exists(City::class, 'id')],
            'programId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'userId' => ['required', 'integer', Rule::exists(User::class, 'id')],
        ], [
            'name.required' => __('Enter a project name.'),
            'cityId.required' => __('Select a city.'),
            'cityId.exists' => __('The selected city is no longer available.'),
            'programId.required' => __('Select a program.'),
            'programId.exists' => __('The selected program is no longer available.'),
            'userId.required' => __('Select a project owner.'),
            'userId.exists' => __('The selected owner is no longer available.'),
        ]);

        $project = $this->editingProjectId === null
            ? new Project
            : Project::query()->findOrFail($this->editingProjectId);

        $project->fill([
            'name' => $validated['name'],
            'city_id' => (int) $validated['cityId'],
            'program_id' => (int) $validated['programId'],
            'user_id' => (int) $validated['userId'],
        ])->save();

        $wasRecentlyCreated = $project->wasRecentlyCreated;

        $this->showProjectModal = false;
        $this->resetProjectForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Project created.') : __('Project updated.'),
        );
    }

    public function closeProjectModal(): void
    {
        $this->showProjectModal = false;
        $this->resetProjectForm();
    }

    protected function resetProjectForm(): void
    {
        $this->resetValidation();
        $this->editingProjectId = null;
        $this->name = '';
        $this->cityId = '';
        $this->programId = '';
        $this->userId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $city !== '' || $program !== '' || $owner !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="project-directory-heading">
    <x-ui.page-header
        heading-id="project-directory-heading"
        :title="__('Projects')"
        :count="$this->projects->total()"
        :description="__('Delivery sites, their city, program and accountable owner.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search projects')" :placeholder="__('Project, city, program, or owner')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('City')" wire:model.live="city">
                <flux:select.option value="">{{ __('All cities') }}</flux:select.option>
                @foreach ($this->cityOptions as $cityOption)
                    <flux:select.option :value="$cityOption->id" :wire:key="'city-filter-'.$cityOption->id">{{ $cityOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Program')" wire:model.live="program">
                <flux:select.option value="">{{ __('All programs') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'program-filter-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Owner')" wire:model.live="owner">
                <flux:select.option value="">{{ __('All owners') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'owner-filter-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->role->value }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-project-button">
                {{ __('Add project') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching project|[2,*] :count matching projects', $this->projects->total(), ['count' => $this->projects->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->projects->isEmpty())
            <x-ui.empty-state
                icon="rectangle-stack"
                :heading="$filtersAreActive ? __('No matching projects') : __('No projects yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Add the first project and place it in a city and program.')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('Reset filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('Add project') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->projects as $project)
                    <article class="flex flex-col gap-3 p-3.5 @4xl/directory:flex-row @4xl/directory:items-center" wire:key="project-{{ $project->id }}">
                        <div class="min-w-0 @4xl/directory:me-auto">
                            <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $project->name }}</a>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $project->city->name }} · {{ $project->program->name }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-secondary-soft @4xl/directory:w-72">
                            <span>{{ trans_choice(':count risk|:count risks', $project->risks_count, ['count' => $project->risks_count]) }}</span>
                            <span>{{ trans_choice(':count milestone|:count milestones', $project->milestones_count, ['count' => $project->milestones_count]) }}</span>
                            <span>{{ trans_choice(':count target|:count targets', $project->plan_indicators_count, ['count' => $project->plan_indicators_count]) }}</span>
                            <span>{{ trans_choice(':count proposal|:count proposals', $project->proposals_count, ['count' => $project->proposals_count]) }}</span>
                        </div>

                        <x-ui.user-chip :user="$project->user" class="@4xl/directory:w-56" />

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! shrink-0 touch-manipulation @4xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('Edit :name', ['name' => $project->name])" />
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->projects" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#project-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showProjectModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveProject" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingProjectId === null ? __('Add project') : __('Edit project') }}</flux:heading>
                <flux:subheading>{{ __('A project is delivered in one city, under one program, by one accountable owner.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Project name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select wire:model="cityId" :label="__('City')" required>
                    <flux:select.option value="">{{ __('Select a city') }}</flux:select.option>
                    @foreach ($this->cityOptions as $cityOption)
                        <flux:select.option :value="$cityOption->id" :wire:key="'city-option-'.$cityOption->id">{{ $cityOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="programId" :label="__('Program')" required>
                    <flux:select.option value="">{{ __('Select a program') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'program-option-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="userId" :label="__('Owner')" required>
                    <flux:select.option value="">{{ __('Select an owner') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'owner-option-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->role->value }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeProjectModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-project-button">
                    {{ $editingProjectId === null ? __('Create project') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/ProjectDirectoryTest.php`
Expected: the filter, create, edit, validation and empty-state tests PASS. The row link to `projects.show` throws `RouteNotFoundException` until Task 2. If that blocks the run, implement Task 2's route registration (Task 2 Step 3) first, then return — the two tasks are adjacent for this reason.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/projects tests/Feature/ProjectDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 2: Project detail

**Files:**
- Create: `resources/views/pages/projects/⚡show.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/pages/programs/⚡show.blade.php` (remove the Foundation plan's `Route::has('projects.show')` guard)
- Test: `tests/Feature/ProjectDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.relation-tabs`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.tree-rows`, `x-ui.enum-badge`, `x-ui.user-chip`, `x-ui.select`, `x-ui.button`.
- Produces: route `projects.show`; component `pages::projects.show`; public properties `public int $projectId`, `public string $tab` (`'milestones'`, `'risks'`, `'targets'`, `'proposals'`), `public string $proposalToAttach`; methods `attachProposal()`, `detachProposal(int)`.

This page owns the `project_proposal` pivot UI. The pivot has a unique index on the id pair, so `attach()` on an already-linked proposal raises a query exception — the options list therefore excludes already-attached proposals, and `attachProposal()` uses `syncWithoutDetaching()` as a second guard.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MilestoneStatus;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from project detail', function () {
    $project = Project::factory()->create();

    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
});

test('project detail shows the project its city program and owner', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Aleppo Wells']);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Aleppo Wells')
        ->assertSeeText($project->city->name)
        ->assertSeeText($project->program->name);
});

test('project detail renders its milestones as a tree', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = User::factory()->create();

    $parent = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'name' => 'Baseline assessment',
        'sequence' => 1,
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'parent_milestone_id' => $parent->id,
        'name' => 'Household survey',
        'sequence' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSet('tab', 'milestones')
        ->assertSee('Baseline assessment')
        ->assertSee('Household survey')
        ->assertSee('data-tree-depth="1"', false);
});

test('project detail shows only its own risks', function () {
    $user = User::factory()->create();
    $subject = Project::factory()->create();
    $other = Project::factory()->create();

    Risk::factory()->create(['project_id' => $subject->id, 'name' => 'Access restrictions']);
    Risk::factory()->create(['project_id' => $other->id, 'name' => 'Currency collapse']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $subject])
        ->set('tab', 'risks')
        ->assertSee('Access restrictions')
        ->assertDontSee('Currency collapse');
});

test('project detail shows targets naming this project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'People reached']);

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => \App\Models\YearlyPlan::factory()->create()->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'targets')
        ->assertSee('People reached');
});

test('a proposal can be attached to and detached from a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Emergency WASH Response']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->call('detachProposal', $proposal->id);

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeFalse();
});

test('attaching the same proposal twice does not duplicate the pivot row', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();

    $project->proposals()->attach($proposal->id);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal');

    $rows = DB::table('project_proposal')
        ->where('project_id', $project->id)
        ->where('proposal_id', $proposal->id)
        ->count();

    expect($rows)->toBe(1);
});

test('project detail renders a project with nothing attached', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Bare Project']);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Bare Project')
        ->assertSeeText('No milestones yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ProjectDetailTest.php`
Expected: FAIL — `Route [projects.show] not defined.`

- [ ] **Step 3: Add the route**

In `routes/web.php`, after `projects.index`:

```php
    Route::livewire('projects/{project}', 'pages::projects.show')
        ->name('projects.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/projects/⚡show.blade.php`:

```blade
<?php

use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Project')] class extends Component {
    public int $projectId;

    #[Url(except: 'milestones')]
    public string $tab = 'milestones';

    public string $proposalToAttach = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()
            ->select(['id', 'name', 'city_id', 'program_id', 'user_id', 'updated_at'])
            ->with([
                'city:id,name',
                'program:id,name,strategy_id',
                'program.strategy:id,name',
                'user:id,name,email,role',
            ])
            ->withCount(['risks', 'milestones', 'planIndicators', 'proposals'])
            ->findOrFail($this->projectId);
    }

    /**
     * The project's milestone roots with their subtrees loaded.
     *
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function milestoneRoots(): Collection
    {
        return Milestone::query()
            ->select(['id', 'project_id', 'name', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->where('project_id', $this->projectId)
            ->roots()
            ->with('childrenRecursive')
            ->get();
    }

    /** @return Collection<int, Risk> */
    #[Computed]
    public function risks(): Collection
    {
        return Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->where('project_id', $this->projectId)
            ->orderByDesc('happened')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targets(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->where('project_id', $this->projectId)
            ->with(['indicator:id,name,result_id,measure', 'indicator.result:id,statement,type'])
            ->withCount('reports')
            ->get();
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function attachedProposals(): Collection
    {
        return $this->project->proposals()
            ->select(['proposals.id', 'proposals.name', 'proposals.status', 'proposals.planned_date_begin', 'proposals.planned_date_end'])
            ->orderBy('proposals.name')
            ->get();
    }

    /**
     * Proposals not already linked, so attach() can never hit the pivot's
     * unique index on (project_id, proposal_id).
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function attachableProposals(): Collection
    {
        return Proposal::query()
            ->select(['id', 'name', 'status'])
            ->whereDoesntHave('projects', fn ($query) => $query->whereKey($this->projectId))
            ->orderBy('name')
            ->get();
    }

    public function attachProposal(): void
    {
        $validated = $this->validate([
            'proposalToAttach' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
        ], [
            'proposalToAttach.required' => __('Select a proposal to link.'),
            'proposalToAttach.exists' => __('The selected proposal is no longer available.'),
        ]);

        // syncWithoutDetaching rather than attach: the pivot carries a unique
        // index on the id pair, so a double submit would otherwise throw.
        $this->project->proposals()->syncWithoutDetaching([(int) $validated['proposalToAttach']]);

        $this->proposalToAttach = '';
        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('Proposal linked to this project.'));
    }

    public function detachProposal(int $proposalId): void
    {
        $this->project->proposals()->detach($proposalId);

        unset($this->project, $this->attachedProposals, $this->attachableProposals);

        Flux::toast(variant: 'success', text: __('Proposal unlinked from this project.'));
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    #[Computed]
    public function tabs(): array
    {
        return [
            ['key' => 'milestones', 'label' => __('Milestones'), 'count' => $this->project->milestones_count],
            ['key' => 'risks', 'label' => __('Risks'), 'count' => $this->project->risks_count],
            ['key' => 'targets', 'label' => __('Targets'), 'count' => $this->project->plan_indicators_count],
            ['key' => 'proposals', 'label' => __('Proposals'), 'count' => $this->project->proposals_count],
        ];
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Projects'), 'url' => route('projects.index')],
            ['label' => $this->project->name],
        ]"
        :title="$this->project->name"
        :subtitle="$this->project->city->name"
    >
        <x-slot:meta>
            <a href="{{ route('programs.show', $this->project->program) }}" wire:navigate class="font-medium hover:underline">{{ $this->project->program->name }}</a>
            <span>{{ $this->project->program->strategy->name }}</span>
            <span>{{ __('Updated :date', ['date' => $this->project->updated_at?->format('M j, Y')]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$this->project->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <x-ui.relation-tabs :tabs="$this->tabs" :active="$tab" wire-target="tab" />

    @if ($tab === 'milestones')
        <x-ui.panel :title="__('Milestones')" :count="$this->project->milestones_count" :subtitle="__('Nested as recorded, ordered by sequence.')">
            @if ($this->milestoneRoots->isEmpty())
                <x-ui.empty-state icon="flag" :heading="__('No milestones yet')" :body="__('Milestones planned for this project will appear here.')" />
            @else
                <x-ui.tree-rows
                    :nodes="$this->milestoneRoots"
                    :label="fn (\App\Models\Milestone $milestone): string => $milestone->name"
                    :badge="fn (\App\Models\Milestone $milestone) => $milestone->status"
                />
            @endif
        </x-ui.panel>
    @elseif ($tab === 'risks')
        <x-ui.panel :title="__('Risk register')" :count="$this->risks->count()" :subtitle="__('Risks that have materialised are listed first.')">
            @if ($this->risks->isEmpty())
                <x-ui.empty-state icon="exclamation-triangle" :heading="__('No risks logged')" :body="__('Risks logged against this project will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->risks as $risk)
                        <article class="flex flex-col gap-1.5 p-3.5" wire:key="project-risk-{{ $risk->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 text-sm font-semibold">{{ $risk->name }}</p>
                                @if ($risk->happened)
                                    <span class="shrink-0 rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('Materialised') }}</span>
                                @else
                                    <span class="shrink-0 rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Watching') }}</span>
                                @endif
                            </div>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Impact') }}:</span> {{ $risk->impact }}</p>
                            @if ($risk->solution !== null)
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Mitigation') }}:</span> {{ $risk->solution }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @elseif ($tab === 'targets')
        <x-ui.panel :title="__('Targets naming this project')" :count="$this->targets->count()">
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('No targets yet')" :body="__('Indicator targets that name this project will appear here.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->targets as $target)
                        <article class="flex flex-col gap-2 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="project-target-{{ $target->id }}">
                            <div class="min-w-0 @3xl/detail:me-auto">
                                <p class="truncate text-sm font-semibold">{{ $target->indicator->name }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">{{ $target->indicator->result->statement }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                    {{ $target->source_type === 'yearly_plan' ? __('Annual plan') : __('Proposal') }}
                                </span>
                                <x-ui.enum-badge :case="$target->period" />
                                <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @else
        <x-ui.panel :title="__('Linked proposals')" :count="$this->attachedProposals->count()" :subtitle="__('A project may be funded by several proposals, and a proposal may cover several projects.')">
            <x-slot:toolbar>
                <div class="flex items-end gap-2">
                    <x-ui.select :label="__('Link a proposal')" wire:model="proposalToAttach" class="min-w-56">
                        <flux:select.option value="">{{ __('Select a proposal') }}</flux:select.option>
                        @foreach ($this->attachableProposals as $proposalOption)
                            <flux:select.option :value="$proposalOption->id" :wire:key="'attachable-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button tone="primary" size="sm" icon="link" class="min-h-10" wire:click="attachProposal">{{ __('Link') }}</x-ui.button>
                </div>
            </x-slot:toolbar>

            @if ($this->attachedProposals->isEmpty())
                <x-ui.empty-state icon="document-text" :heading="__('No proposals linked')" :body="__('Link a proposal to record which funding covers this project.')" />
            @else
                <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->attachedProposals as $proposal)
                        <article class="flex items-center gap-3 p-3.5" wire:key="project-proposal-{{ $proposal->id }}">
                            <div class="min-w-0 me-auto">
                                @if (Route::has('proposals.show'))
                                    <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $proposal->name }}</a>
                                @else
                                    <p class="truncate text-sm font-semibold">{{ $proposal->name }}</p>
                                @endif
                                <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                    {{ $proposal->planned_date_begin?->format('M j, Y') }} – {{ $proposal->planned_date_end?->format('M j, Y') }}
                                </p>
                            </div>
                            <x-ui.enum-badge :case="$proposal->status" />
                            <x-ui.button tone="ghost" size="sm" icon="x-mark" class="size-9!" wire:click="detachProposal({{ $proposal->id }})" :aria-label="__('Unlink :name', ['name' => $proposal->name])" />
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    @endif
</section>
```

- [ ] **Step 5: Remove the Foundation plan's forward guard**

In `resources/views/pages/programs/⚡show.blade.php`, `projects.show` now exists. Replace the guarded block:

```blade
@if (Route::has('projects.show'))
    <a href="{{ route('projects.show', $project) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
@else
    <p class="truncate text-sm font-semibold">{{ $project->name }}</p>
@endif
```

with the plain link:

```blade
<a href="{{ route('projects.show', $project) }}" wire:navigate class="truncate text-sm font-semibold hover:underline">{{ $project->name }}</a>
```

Leave the `Route::has('proposals.show')` guard on **this** page in place; `proposals.show` arrives in the Funding plan.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/ProjectDetailTest.php tests/Feature/ProjectDirectoryTest.php tests/Feature/ProgramDetailTest.php`
Expected: PASS — all three, including Task 1's row-link test which was blocked on this route.

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages tests/Feature/ProjectDetailTest.php
```

Stop and report. Do not commit.

---

### Task 3: Risk register

**Files:**
- Create: `resources/views/pages/risks/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/RiskRegisterTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `risks.index`; component `pages::risks.index`; public properties `$search`, `$project`, `$status` (`''`, `'happened'`, `'watching'`), `$showRiskModal`, `$editingRiskId`, `$name`, `$impact`, `$solution`, `$happened`, `$riskProjectId`; methods `openCreateModal()`, `openEditModal(int)`, `saveRisk()`, `confirmDelete(int)`, `deleteRisk()`, `closeRiskModal()`, `clearFilters()`.

Risks are the one table in this spine that deletes freely: nothing has a foreign key pointing at `risks`.

`risks.happened` has a database default, so `RiskFactory` omits it (`.ai/rules/seeders.md`). The form sets it explicitly, which is correct — the default is only protected in the *factory*, not in user-facing writes.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Project;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the risk register', function () {
    $this->get(route('risks.index'))->assertRedirect(route('login'));
});

test('risks can be filtered by search project and whether they materialised', function () {
    $user = User::factory()->create();
    $first = Project::factory()->create(['name' => 'Aleppo Wells']);
    $second = Project::factory()->create(['name' => 'Homs Shelter']);

    Risk::factory()->create(['project_id' => $first->id, 'name' => 'Access restrictions', 'happened' => true]);
    Risk::factory()->create(['project_id' => $second->id, 'name' => 'Currency collapse', 'happened' => false]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->assertSee('Access restrictions')
        ->assertSee('Currency collapse')
        ->set('search', 'Access')
        ->assertSee('Access restrictions')
        ->assertDontSee('Currency collapse')
        ->call('clearFilters')
        ->set('project', (string) $second->id)
        ->assertDontSee('Access restrictions')
        ->assertSee('Currency collapse')
        ->call('clearFilters')
        ->set('status', 'happened')
        ->assertSee('Access restrictions')
        ->assertDontSee('Currency collapse')
        ->call('clearFilters')
        ->set('status', 'watching')
        ->assertDontSee('Access restrictions')
        ->assertSee('Currency collapse');
});

test('a risk can be logged against a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', '  Fuel shortage  ')
        ->set('impact', 'Generators cannot run, pumping stops.')
        ->set('solution', 'Pre-position a 30 day fuel reserve.')
        ->set('happened', false)
        ->set('riskProjectId', (string) $project->id)
        ->call('saveRisk')
        ->assertHasNoErrors()
        ->assertSet('showRiskModal', false);

    $risk = Risk::query()->where('name', 'Fuel shortage')->sole();

    expect($risk->project_id)->toBe($project->id)
        ->and($risk->happened)->toBeFalse()
        ->and($risk->impact)->toBe('Generators cannot run, pumping stops.');
});

test('logging a risk requires a name an impact and a project', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('impact', '')
        ->set('riskProjectId', '')
        ->call('saveRisk')
        ->assertHasErrors(['name', 'impact', 'riskProjectId']);
});

test('a risk can be marked as materialised through edit', function () {
    $user = User::factory()->create();
    $risk = Risk::factory()->create(['happened' => false]);

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('openEditModal', $risk->id)
        ->assertSet('happened', false)
        ->set('happened', true)
        ->call('saveRisk')
        ->assertHasNoErrors();

    expect($risk->refresh()->happened)->toBeTrue();
});

test('a risk can be deleted', function () {
    $user = User::factory()->create();
    $risk = Risk::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::risks.index')
        ->call('confirmDelete', $risk->id)
        ->assertSet('deletingRiskId', $risk->id)
        ->call('deleteRisk')
        ->assertHasNoErrors();

    expect(DB::table('risks')->find($risk->id))->toBeNull();
});

test('the risk register renders with no risks', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::risks.index')->assertSee('No risks logged');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/RiskRegisterTest.php`
Expected: FAIL — `Route [risks.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('risks', 'pages::risks.index')
        ->name('risks.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/risks/⚡index.blade.php`:

```blade
<?php

use App\Models\Project;
use App\Models\Risk;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Risks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showRiskModal = false;
    public ?int $editingRiskId = null;
    public ?int $deletingRiskId = null;
    public string $name = '';
    public string $impact = '';
    public string $solution = '';
    public bool $happened = false;
    public string $riskProjectId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->project = '';
        $this->status = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Risk> */
    #[Computed]
    public function risks(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id', 'updated_at'])
            ->with(['project:id,name,city_id', 'project.city:id,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('impact', 'like', '%'.$search.'%')
                        ->orWhereHas('project', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->project !== '', fn ($query) => $query->where('project_id', (int) $this->project))
            ->when($this->status === 'happened', fn ($query) => $query->where('happened', true))
            ->when($this->status === 'watching', fn ($query) => $query->where('happened', false))
            ->orderByDesc('happened')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetRiskForm();
        $this->showRiskModal = true;
    }

    public function openEditModal(int $riskId): void
    {
        $risk = Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->findOrFail($riskId);

        $this->resetValidation();
        $this->editingRiskId = $risk->id;
        $this->name = $risk->name;
        $this->impact = $risk->impact;
        $this->solution = $risk->solution ?? '';
        $this->happened = $risk->happened;
        $this->riskProjectId = (string) $risk->project_id;
        $this->showRiskModal = true;
    }

    public function saveRisk(): void
    {
        $this->name = trim($this->name);
        $this->impact = trim($this->impact);
        $this->solution = trim($this->solution);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'impact' => ['required', 'string'],
            'solution' => ['nullable', 'string'],
            'happened' => ['boolean'],
            'riskProjectId' => ['required', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'name.required' => __('Enter a short risk name.'),
            'impact.required' => __('Describe what happens if this risk materialises.'),
            'riskProjectId.required' => __('Select the project this risk affects.'),
            'riskProjectId.exists' => __('The selected project is no longer available.'),
        ]);

        $risk = $this->editingRiskId === null
            ? new Risk
            : Risk::query()->findOrFail($this->editingRiskId);

        $risk->fill([
            'name' => $validated['name'],
            'impact' => $validated['impact'],
            'solution' => $validated['solution'] === '' ? null : $validated['solution'],
            'happened' => $validated['happened'],
            'project_id' => (int) $validated['riskProjectId'],
        ])->save();

        $wasRecentlyCreated = $risk->wasRecentlyCreated;

        $this->showRiskModal = false;
        $this->resetRiskForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Risk logged.') : __('Risk updated.'),
        );
    }

    public function confirmDelete(int $riskId): void
    {
        $this->deletingRiskId = $riskId;
    }

    /**
     * Risks delete freely: no foreign key anywhere points at this table, so
     * removing one cannot take another row with it.
     */
    public function deleteRisk(): void
    {
        if ($this->deletingRiskId === null) {
            return;
        }

        Risk::query()->findOrFail($this->deletingRiskId)->delete();

        $this->deletingRiskId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Risk deleted.'));
    }

    public function closeRiskModal(): void
    {
        $this->showRiskModal = false;
        $this->resetRiskForm();
    }

    protected function resetRiskForm(): void
    {
        $this->resetValidation();
        $this->editingRiskId = null;
        $this->name = '';
        $this->impact = '';
        $this->solution = '';
        $this->happened = false;
        $this->riskProjectId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $project !== '' || $status !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="risk-register-heading">
    <x-ui.page-header
        heading-id="risk-register-heading"
        :title="__('Risks')"
        :count="$this->risks->total()"
        :description="__('Risks logged against delivery projects, materialised first.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search risks')" :placeholder="__('Risk, impact, or project')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Project')" wire:model.live="project">
                <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'risk-project-filter-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('State')" wire:model.live="status">
                <flux:select.option value="">{{ __('All risks') }}</flux:select.option>
                <flux:select.option value="happened">{{ __('Materialised') }}</flux:select.option>
                <flux:select.option value="watching">{{ __('Watching') }}</flux:select.option>
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-risk-button">
                {{ __('Log risk') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching risk|[2,*] :count matching risks', $this->risks->total(), ['count' => $this->risks->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->risks->isEmpty())
            <x-ui.empty-state
                icon="exclamation-triangle"
                :heading="$filtersAreActive ? __('No matching risks') : __('No risks logged')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Log the first risk against a project.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->risks as $risk)
                    <article class="flex flex-col gap-2 p-3.5" wire:key="risk-{{ $risk->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">{{ $risk->name }}</p>
                                <a href="{{ route('projects.show', $risk->project) }}" wire:navigate class="text-xs text-secondary-medium hover:underline dark:text-secondary-soft">
                                    {{ $risk->project->name }} · {{ $risk->project->city->name }}
                                </a>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if ($risk->happened)
                                    <span class="rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('Materialised') }}</span>
                                @else
                                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Watching') }}</span>
                                @endif
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $risk->id }})" :aria-label="__('Edit :name', ['name' => $risk->name])" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $risk->id }})" :aria-label="__('Delete :name', ['name' => $risk->name])" />
                            </div>
                        </div>
                        <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Impact') }}:</span> {{ $risk->impact }}</p>
                        @if ($risk->solution !== null)
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Mitigation') }}:</span> {{ $risk->solution }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->risks" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#risk-register-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showRiskModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveRisk" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingRiskId === null ? __('Log risk') : __('Edit risk') }}</flux:heading>
                <flux:subheading>{{ __('Record what could go wrong, what it would cost, and how it is mitigated.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Risk name')" type="text" maxlength="255" required autofocus />

            <flux:textarea wire:model="impact" :label="__('Impact')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <flux:textarea wire:model="solution" :label="__('Mitigation')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="riskProjectId" :label="__('Project')" required>
                    <flux:select.option value="">{{ __('Select a project') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'risk-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <flux:checkbox wire:model="happened" :label="__('This risk has materialised')" class="self-end" />
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeRiskModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-risk-button">
                    {{ $editingRiskId === null ? __('Log risk') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingRiskId !== null" wire:close="$set('deletingRiskId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this risk?') }}</flux:heading>
            <flux:subheading>{{ __('The risk and its mitigation notes are removed. Nothing else is affected.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingRiskId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteRisk" data-test="confirm-delete-risk">{{ __('Delete risk') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/RiskRegisterTest.php`
Expected: PASS

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/risks tests/Feature/RiskRegisterTest.php
```

Stop and report. Do not commit.

---

### Task 4: Milestone directory, tree and guarded delete

**Files:**
- Create: `resources/views/pages/milestones/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/MilestoneDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.tree-rows`, `x-ui.enum-badge`, `x-ui.input`, `x-ui.select`, `x-ui.button`, `x-ui.user-chip`.
- Produces: route `milestones.index`; component `pages::milestones.index`; public properties `$project`, `$status`, `$responsible`, `$showMilestoneModal`, `$editingMilestoneId`, `$deletingMilestoneId`, and the form fields `$name`, `$description`, `$plannedDate`, `$actualDate`, `$milestoneStatus`, `$sequence`, `$weightPercentage`, `$responsibleUserId`, `$milestoneProjectId`, `$parentMilestoneId`; methods `openCreateModal()`, `openEditModal(int)`, `saveMilestone()`, `confirmDelete(int)`, `deleteMilestone()`, `closeMilestoneModal()`, `clearFilters()`; computed `parentOptions()`, `canDelete(int)`.

**The two guards this task exists to get right:**

1. **Parent options exclude the node's own subtree and cross-project nodes.** `MilestoneObserver` throws `InvalidParentException` on a cycle, and nothing at the database level stops nesting under a milestone in another project (`.ai/rules/observers.md`). The picker must therefore filter to the same `project_id` and exclude `$milestone->descendants()` plus the node itself.
2. **Delete only when leaf and taskless.** `parent_milestone_id` cascades, so deleting a parent takes the subtree. `tasks.milestone_id` is `nullOnDelete`, so deleting a milestone with tasks orphans them into "No milestone" — silent data corruption rather than data loss, which is why it is refused rather than allowed.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the milestone directory', function () {
    $this->get(route('milestones.index'))->assertRedirect(route('login'));
});

test('milestones render as a tree grouped by project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Aleppo Wells']);
    $responsible = User::factory()->create();

    $parent = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'name' => 'Baseline assessment',
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'parent_milestone_id' => $parent->id,
        'name' => 'Household survey',
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->assertSee('Aleppo Wells')
        ->assertSee('Baseline assessment')
        ->assertSee('Household survey')
        ->assertSee('data-tree-depth="1"', false);
});

test('milestones can be filtered by project and status', function () {
    $user = User::factory()->create();
    $first = Project::factory()->create();
    $second = Project::factory()->create();

    Milestone::factory()->create(['project_id' => $first->id, 'name' => 'First gate', 'status' => MilestoneStatus::Reached]);
    Milestone::factory()->create(['project_id' => $second->id, 'name' => 'Second gate', 'status' => MilestoneStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->set('project', (string) $first->id)
        ->assertSee('First gate')
        ->assertDontSee('Second gate')
        ->call('clearFilters')
        ->set('status', MilestoneStatus::Pending->value)
        ->assertDontSee('First gate')
        ->assertSee('Second gate');
});

test('a milestone can be created under a parent in the same project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = User::factory()->create();
    $parent = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('name', '  Water quality testing  ')
        ->set('milestoneProjectId', (string) $project->id)
        ->set('responsibleUserId', (string) $responsible->id)
        ->set('parentMilestoneId', (string) $parent->id)
        ->set('milestoneStatus', MilestoneStatus::Pending->value)
        ->set('plannedDate', '2026-06-30')
        ->call('saveMilestone')
        ->assertHasNoErrors();

    $milestone = Milestone::query()->where('name', 'Water quality testing')->sole();

    expect($milestone->parent_milestone_id)->toBe($parent->id)
        ->and($milestone->project_id)->toBe($project->id);
});

test('the parent picker offers no milestone from another project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();

    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Same project gate']);
    Milestone::factory()->create(['project_id' => $otherProject->id, 'name' => 'Other project gate']);

    $component = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openCreateModal')
        ->set('milestoneProjectId', (string) $project->id);

    $names = $component->instance()->parentOptions()->pluck('name')->all();

    expect($names)->toContain('Same project gate')->not->toContain('Other project gate');
});

test('the parent picker excludes the edited milestone and its own descendants', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();

    $root = Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Root gate']);
    $child = Milestone::factory()->create([
        'project_id' => $project->id,
        'parent_milestone_id' => $root->id,
        'name' => 'Child gate',
    ]);
    Milestone::factory()->create(['project_id' => $project->id, 'name' => 'Sibling gate']);

    $component = Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('openEditModal', $root->id);

    $names = $component->instance()->parentOptions()->pluck('name')->all();

    expect($names)->toContain('Sibling gate')
        ->not->toContain('Root gate')
        ->not->toContain('Child gate');
});

test('a leaf milestone with no tasks can be deleted', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->call('deleteMilestone')
        ->assertHasNoErrors();

    expect(DB::table('milestones')->find($milestone->id))->toBeNull();
});

test('a milestone with children is not deleted because the subtree would cascade', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $parent = Milestone::factory()->create(['project_id' => $project->id]);
    $child = Milestone::factory()->create(['project_id' => $project->id, 'parent_milestone_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $parent->id)
        ->call('deleteMilestone');

    expect(DB::table('milestones')->find($parent->id))->not->toBeNull()
        ->and(DB::table('milestones')->find($child->id))->not->toBeNull();
});

test('a milestone carrying tasks is not deleted because the tasks would be silently orphaned', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();
    $task = Task::factory()->create(['milestone_id' => $milestone->id]);

    Livewire::actingAs($user)
        ->test('pages::milestones.index')
        ->call('confirmDelete', $milestone->id)
        ->call('deleteMilestone');

    expect(DB::table('milestones')->find($milestone->id))->not->toBeNull()
        ->and(DB::table('tasks')->find($task->id)->milestone_id)->toBe($milestone->id);
});

test('the milestone directory renders with no milestones', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::milestones.index')->assertSee('No milestones yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/MilestoneDirectoryTest.php`
Expected: FAIL — `Route [milestones.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('milestones', 'pages::milestones.index')
        ->name('milestones.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/milestones/⚡index.blade.php`:

```blade
<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Milestones')] class extends Component {
    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $responsible = '';

    public bool $showMilestoneModal = false;
    public ?int $editingMilestoneId = null;
    public ?int $deletingMilestoneId = null;

    public string $name = '';
    public string $description = '';
    public string $plannedDate = '';
    public string $actualDate = '';
    public string $milestoneStatus = 'pending';
    public string $sequence = '1';
    public string $weightPercentage = '0';
    public string $responsibleUserId = '';
    public string $milestoneProjectId = '';
    public string $parentMilestoneId = '';

    public function clearFilters(): void
    {
        $this->project = '';
        $this->status = '';
        $this->responsible = '';
    }

    /**
     * Projects that have at least one matching milestone, each carrying its
     * milestone roots with the whole subtree loaded.
     *
     * The tree is grouped by project because a milestone has no meaning apart
     * from its project, and because parent nesting is project-local.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectsWithMilestones(): Collection
    {
        $filter = function ($query): void {
            $query
                ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
                ->when($this->responsible !== '', fn ($query) => $query->where('responsible_user_id', (int) $this->responsible));
        };

        return Project::query()
            ->select(['id', 'name', 'city_id'])
            ->when($this->project !== '', fn ($query) => $query->whereKey((int) $this->project))
            ->whereHas('milestones', $filter)
            ->with([
                'city:id,name',
                'milestones' => function ($query) use ($filter): void {
                    $query
                        ->select(['id', 'project_id', 'name', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
                        ->whereNull('parent_milestone_id')
                        ->tap($filter)
                        ->orderBy('sequence')
                        ->with(['childrenRecursive', 'responsibleUser:id,name,email,role']);
                },
            ])
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function userOptions(): Collection
    {
        return User::query()->select(['id', 'name', 'email', 'role'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Milestones this one may nest under.
     *
     * Two exclusions, both load-bearing. Cross-project parents are filtered out
     * because milestones.project_id and parent_milestone_id are independent
     * foreign keys and nothing in the schema stops the combination. The node
     * itself and its descendants are filtered out because MilestoneObserver
     * throws InvalidParentException on a cycle — this picker makes that
     * exception a backstop rather than the primary guard.
     *
     * @return Collection<int, Milestone>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        if ($this->milestoneProjectId === '') {
            return new Collection;
        }

        $excludedIds = [];

        if ($this->editingMilestoneId !== null) {
            $milestone = Milestone::query()
                ->whereKey($this->editingMilestoneId)
                ->with('childrenRecursive')
                ->first();

            if ($milestone !== null) {
                $excludedIds = $milestone->descendants()->pluck('id')->push($milestone->id)->all();
            }
        }

        return Milestone::query()
            ->select(['id', 'name', 'project_id', 'sequence'])
            ->where('project_id', (int) $this->milestoneProjectId)
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('sequence')
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether this milestone may be deleted.
     *
     * Refused when it has children (parent_milestone_id cascades, so the whole
     * subtree would go) or tasks (tasks.milestone_id is nullOnDelete, so the
     * tasks would survive but silently lose their milestone).
     */
    public function canDelete(int $milestoneId): bool
    {
        $milestone = Milestone::query()
            ->select(['id'])
            ->withCount(['children', 'tasks'])
            ->find($milestoneId);

        if ($milestone === null) {
            return false;
        }

        return $milestone->children_count === 0 && $milestone->tasks_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetMilestoneForm();
        $this->showMilestoneModal = true;
    }

    public function openEditModal(int $milestoneId): void
    {
        $milestone = Milestone::query()
            ->select(['id', 'project_id', 'name', 'description', 'planned_date', 'actual_date', 'status', 'sequence', 'weight_percentage', 'responsible_user_id', 'parent_milestone_id'])
            ->findOrFail($milestoneId);

        $this->resetValidation();
        $this->editingMilestoneId = $milestone->id;
        $this->name = $milestone->name;
        $this->description = $milestone->description ?? '';
        $this->plannedDate = $milestone->planned_date?->toDateString() ?? '';
        $this->actualDate = $milestone->actual_date?->toDateString() ?? '';
        $this->milestoneStatus = $milestone->status->value;
        $this->sequence = (string) $milestone->sequence;
        $this->weightPercentage = (string) $milestone->weight_percentage;
        $this->responsibleUserId = (string) $milestone->responsible_user_id;
        $this->milestoneProjectId = (string) $milestone->project_id;
        $this->parentMilestoneId = (string) ($milestone->parent_milestone_id ?? '');
        $this->showMilestoneModal = true;
    }

    public function saveMilestone(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'plannedDate' => ['required', 'date'],
            'actualDate' => ['nullable', 'date'],
            'milestoneStatus' => ['required', new Enum(MilestoneStatus::class)],
            'sequence' => ['required', 'integer', 'min:0'],
            'weightPercentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'responsibleUserId' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'milestoneProjectId' => ['required', 'integer', Rule::exists(Project::class, 'id')],
            'parentMilestoneId' => ['nullable', 'integer', Rule::exists(Milestone::class, 'id')],
        ], [
            'name.required' => __('Enter a milestone name.'),
            'plannedDate.required' => __('Set the planned date.'),
            'responsibleUserId.required' => __('Select the responsible staff member.'),
            'milestoneProjectId.required' => __('Select the project.'),
            'weightPercentage.max' => __('Weight cannot exceed 100 percent.'),
        ]);

        $milestone = $this->editingMilestoneId === null
            ? new Milestone
            : Milestone::query()->findOrFail($this->editingMilestoneId);

        $milestone->fill([
            'project_id' => (int) $validated['milestoneProjectId'],
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'planned_date' => $validated['plannedDate'],
            'actual_date' => $validated['actualDate'] === '' ? null : $validated['actualDate'],
            'status' => $validated['milestoneStatus'],
            'sequence' => (int) $validated['sequence'],
            'weight_percentage' => $validated['weightPercentage'],
            'responsible_user_id' => (int) $validated['responsibleUserId'],
            'parent_milestone_id' => $validated['parentMilestoneId'] === '' || $validated['parentMilestoneId'] === null
                ? null
                : (int) $validated['parentMilestoneId'],
        ])->save();

        $wasRecentlyCreated = $milestone->wasRecentlyCreated;

        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
        unset($this->projectsWithMilestones);

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Milestone created.') : __('Milestone updated.'),
        );
    }

    public function confirmDelete(int $milestoneId): void
    {
        $this->deletingMilestoneId = $milestoneId;
    }

    public function deleteMilestone(): void
    {
        if ($this->deletingMilestoneId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingMilestoneId)) {
            $this->deletingMilestoneId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This milestone still has nested milestones or tasks. Move or remove those first.'),
            );

            return;
        }

        Milestone::query()->findOrFail($this->deletingMilestoneId)->delete();

        $this->deletingMilestoneId = null;
        unset($this->projectsWithMilestones);

        Flux::toast(variant: 'success', text: __('Milestone deleted.'));
    }

    public function closeMilestoneModal(): void
    {
        $this->showMilestoneModal = false;
        $this->resetMilestoneForm();
    }

    protected function resetMilestoneForm(): void
    {
        $this->resetValidation();
        $this->editingMilestoneId = null;
        $this->name = '';
        $this->description = '';
        $this->plannedDate = '';
        $this->actualDate = '';
        $this->milestoneStatus = 'pending';
        $this->sequence = '1';
        $this->weightPercentage = '0';
        $this->responsibleUserId = '';
        $this->milestoneProjectId = '';
        $this->parentMilestoneId = '';
    }
};
?>

@php
    $filtersAreActive = $project !== '' || $status !== '' || $responsible !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="milestone-directory-heading">
    <x-ui.page-header
        heading-id="milestone-directory-heading"
        :title="__('Milestones')"
        :description="__('Delivery gates, nested per project and ordered by sequence.')"
    >
        <x-slot:filters>
            <x-ui.select :label="__('Project')" wire:model.live="project">
                <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'ms-project-filter-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Status')" wire:model.live="status">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (MilestoneStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'ms-status-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Responsible')" wire:model.live="responsible">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'ms-user-'.$userOption->id">{{ $userOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-milestone-button">
                {{ __('Add milestone') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->projectsWithMilestones->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="flag"
                :heading="$filtersAreActive ? __('No matching milestones') : __('No milestones yet')"
                :body="$filtersAreActive ? __('Change the filters and try again.') : __('Add the first milestone to a project.')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('Reset filters') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        @foreach ($this->projectsWithMilestones as $projectRow)
            <x-ui.panel
                :title="$projectRow->name"
                :subtitle="$projectRow->city->name"
                :count="$projectRow->milestones->count()"
                wire:key="ms-project-{{ $projectRow->id }}"
            >
                <x-slot:toolbar>
                    <a href="{{ route('projects.show', $projectRow) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-secondary-soft">
                        {{ __('Open project') }}
                    </a>
                </x-slot:toolbar>

                <x-ui.tree-rows
                    :nodes="$projectRow->milestones"
                    :label="fn (\App\Models\Milestone $milestone): string => $milestone->sequence.'. '.$milestone->name"
                    :badge="fn (\App\Models\Milestone $milestone) => $milestone->status"
                />
            </x-ui.panel>
        @endforeach
    @endif

    <flux:modal wire:model="showMilestoneModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveMilestone" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingMilestoneId === null ? __('Add milestone') : __('Edit milestone') }}</flux:heading>
                <flux:subheading>{{ __('A milestone belongs to one project and may nest under another milestone in that same project.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Milestone name')" type="text" maxlength="255" required autofocus />

            <flux:textarea wire:model="description" :label="__('Description')" rows="2" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model.live="milestoneProjectId" :label="__('Project')" required>
                    <flux:select.option value="">{{ __('Select a project') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'ms-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="parentMilestoneId" :label="__('Nest under')">
                    <flux:select.option value="">{{ __('Top level') }}</flux:select.option>
                    @foreach ($this->parentOptions as $parentOption)
                        <flux:select.option :value="$parentOption->id" :wire:key="'ms-parent-'.$parentOption->id">{{ $parentOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedDate" :label="__('Planned date')" type="date" required />
                <x-ui.input wire:model="actualDate" :label="__('Actual date')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-ui.select wire:model="milestoneStatus" :label="__('Status')" required>
                    @foreach (MilestoneStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value" :wire:key="'ms-status-option-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.input wire:model="sequence" :label="__('Sequence')" type="number" min="0" required />
                <x-ui.input wire:model="weightPercentage" :label="__('Weight %')" type="number" min="0" max="100" step="0.01" required />

                <x-ui.select wire:model="responsibleUserId" :label="__('Responsible')" required>
                    <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'ms-resp-'.$userOption->id">{{ $userOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeMilestoneModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-milestone-button">
                    {{ $editingMilestoneId === null ? __('Create milestone') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingMilestoneId !== null" wire:close="$set('deletingMilestoneId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this milestone?') }}</flux:heading>
            <flux:subheading>
                {{ __('Only a milestone with no nested milestones and no tasks can be deleted. Nested milestones would be removed with it, and tasks would silently lose their milestone.') }}
            </flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingMilestoneId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteMilestone" data-test="confirm-delete-milestone">{{ __('Delete milestone') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/MilestoneDirectoryTest.php`
Expected: PASS, including the three guard tests. If the two "not deleted" tests fail, the guard is wrong — fix `canDelete()`, never the test.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/milestones tests/Feature/MilestoneDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 5: Task directory

**Files:**
- Create: `resources/views/pages/tasks/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TaskDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.user-chip`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `tasks.index`; component `pages::tasks.index`; public properties `$search`, `$status`, `$priority`, `$assignee`, `$milestone` (accepts the literal `'none'` for "No milestone"), `$showTaskModal`, `$editingTaskId`, `$deletingTaskId`, form fields `$title`, `$description`, `$taskStatus`, `$taskPriority`, `$startDate`, `$dueDate`, `$completedDate`, `$progressPercentage`, `$assignedTo`, `$taskProposalId`, `$taskMilestoneId`; methods `openCreateModal()`, `openEditModal(int)`, `saveTask()`, `confirmDelete(int)`, `deleteTask()`, `closeTaskModal()`, `clearFilters()`.

**Two things this page must get right.** `tasks.milestone_id` is nullable, so the row markup never assumes `$task->milestone` exists and the filter offers an explicit "No milestone" option. `created_by` is set from `auth()->id()` on create and never edited — it is an authorship record, not a field.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the task directory', function () {
    $this->get(route('tasks.index'))->assertRedirect(route('login'));
});

test('tasks can be filtered by search status priority and assignee', function () {
    $user = User::factory()->create();
    $alice = User::factory()->create(['name' => 'Alice Nadir']);
    $bob = User::factory()->create(['name' => 'Bob Rahman']);

    Task::factory()->create(['title' => 'Draft distribution plan', 'assigned_to' => $alice->id, 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High]);
    Task::factory()->create(['title' => 'Verify beneficiary list', 'assigned_to' => $bob->id, 'status' => TaskStatus::Done, 'priority' => TaskPriority::Low]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Draft distribution plan')
        ->assertSee('Verify beneficiary list')
        ->set('search', 'Draft')
        ->assertSee('Draft distribution plan')
        ->assertDontSee('Verify beneficiary list')
        ->call('clearFilters')
        ->set('status', TaskStatus::Done->value)
        ->assertDontSee('Draft distribution plan')
        ->assertSee('Verify beneficiary list')
        ->call('clearFilters')
        ->set('priority', TaskPriority::High->value)
        ->assertSee('Draft distribution plan')
        ->assertDontSee('Verify beneficiary list')
        ->call('clearFilters')
        ->set('assignee', (string) $bob->id)
        ->assertDontSee('Draft distribution plan')
        ->assertSee('Verify beneficiary list');
});

test('tasks with no milestone are listed and filterable', function () {
    $user = User::factory()->create();
    $milestone = Milestone::factory()->create();

    Task::factory()->create(['title' => 'Attached task', 'milestone_id' => $milestone->id]);
    Task::factory()->create(['title' => 'Loose task', 'milestone_id' => null]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->assertSee('Attached task')
        ->assertSee('Loose task')
        ->set('milestone', 'none')
        ->assertDontSee('Attached task')
        ->assertSee('Loose task');
});

test('a task records its creator from the authenticated user', function () {
    $creator = User::factory()->create();
    $assignee = User::factory()->create();
    $proposal = Proposal::factory()->create();

    Livewire::actingAs($creator)
        ->test('pages::tasks.index')
        ->call('openCreateModal')
        ->set('title', '  Prepare the sitrep  ')
        ->set('taskProposalId', (string) $proposal->id)
        ->set('assignedTo', (string) $assignee->id)
        ->set('taskStatus', TaskStatus::Todo->value)
        ->set('taskPriority', TaskPriority::Medium->value)
        ->set('startDate', '2026-03-01')
        ->set('dueDate', '2026-03-15')
        ->call('saveTask')
        ->assertHasNoErrors();

    $task = Task::query()->where('title', 'Prepare the sitrep')->sole();

    expect($task->created_by)->toBe($creator->id)
        ->and($task->assigned_to)->toBe($assignee->id)
        ->and($task->milestone_id)->toBeNull();
});

test('creating a task requires a title a proposal and an assignee', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('openCreateModal')
        ->set('title', '')
        ->set('taskProposalId', '')
        ->set('assignedTo', '')
        ->call('saveTask')
        ->assertHasErrors(['title', 'taskProposalId', 'assignedTo']);
});

test('editing a task does not change its recorded creator', function () {
    $creator = User::factory()->create();
    $editor = User::factory()->create();
    $task = Task::factory()->create(['created_by' => $creator->id, 'title' => 'Original']);

    Livewire::actingAs($editor)
        ->test('pages::tasks.index')
        ->call('openEditModal', $task->id)
        ->set('title', 'Revised')
        ->call('saveTask')
        ->assertHasNoErrors();

    $task->refresh();

    expect($task->title)->toBe('Revised')->and($task->created_by)->toBe($creator->id);
});

test('a task can be deleted and takes its own updates and attachments with it', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $update = \App\Models\TaskUpdate::factory()->create(['task_id' => $task->id]);
    $attachment = \App\Models\TaskAttachment::factory()->create(['task_id' => $task->id]);

    Livewire::actingAs($user)
        ->test('pages::tasks.index')
        ->call('confirmDelete', $task->id)
        ->call('deleteTask')
        ->assertHasNoErrors();

    expect(DB::table('tasks')->find($task->id))->toBeNull()
        ->and(DB::table('task_updates')->find($update->id))->toBeNull()
        ->and(DB::table('task_attachments')->find($attachment->id))->toBeNull();
});

test('the task directory renders with no tasks', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::tasks.index')->assertSee('No tasks yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/TaskDirectoryTest.php`
Expected: FAIL — `Route [tasks.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('tasks', 'pages::tasks.index')
        ->name('tasks.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/tasks/⚡index.blade.php`:

```blade
<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tasks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $assignee = '';

    /** Accepts a milestone id, or the literal 'none' for tasks with no milestone. */
    #[Url(except: '')]
    public string $milestone = '';

    public bool $showTaskModal = false;
    public ?int $editingTaskId = null;
    public ?int $deletingTaskId = null;

    public string $title = '';
    public string $description = '';
    public string $taskStatus = 'todo';
    public string $taskPriority = 'medium';
    public string $startDate = '';
    public string $dueDate = '';
    public string $completedDate = '';
    public string $progressPercentage = '0';
    public string $assignedTo = '';
    public string $taskProposalId = '';
    public string $taskMilestoneId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedAssignee(): void
    {
        $this->resetPage();
    }

    public function updatedMilestone(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->priority = '';
        $this->assignee = '';
        $this->milestone = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Task> */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'assigned_to', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by'])
            ->with([
                // milestone is nullable, so this relation may resolve to null.
                'milestone:id,name,project_id',
                'milestone.project:id,name',
                'proposal:id,name,status',
                'assignee:id,name,email,role',
                'creator:id,name,email,role',
            ])
            ->withCount(['updates', 'attachments'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%')
                        ->orWhereHas('assignee', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->priority !== '', fn ($query) => $query->where('priority', $this->priority))
            ->when($this->assignee !== '', fn ($query) => $query->where('assigned_to', (int) $this->assignee))
            ->when($this->milestone === 'none', fn ($query) => $query->whereNull('milestone_id'))
            ->when(
                $this->milestone !== '' && $this->milestone !== 'none',
                fn ($query) => $query->where('milestone_id', (int) $this->milestone)
            )
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function userOptions(): Collection
    {
        return User::query()->select(['id', 'name', 'email', 'role'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Proposal> */
    #[Computed]
    public function proposalOptions(): Collection
    {
        return Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Milestone> */
    #[Computed]
    public function milestoneOptions(): Collection
    {
        return Milestone::query()
            ->select(['id', 'name', 'project_id'])
            ->with('project:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetTaskForm();
        $this->showTaskModal = true;
    }

    public function openEditModal(int $taskId): void
    {
        $task = Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'description', 'assigned_to', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage'])
            ->findOrFail($taskId);

        $this->resetValidation();
        $this->editingTaskId = $task->id;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->taskStatus = $task->status->value;
        $this->taskPriority = $task->priority->value;
        $this->startDate = $task->start_date?->toDateString() ?? '';
        $this->dueDate = $task->due_date?->toDateString() ?? '';
        $this->completedDate = $task->completed_date?->toDateString() ?? '';
        $this->progressPercentage = (string) $task->progress_percentage;
        $this->assignedTo = (string) $task->assigned_to;
        $this->taskProposalId = (string) $task->proposal_id;
        $this->taskMilestoneId = (string) ($task->milestone_id ?? '');
        $this->showTaskModal = true;
    }

    public function saveTask(): void
    {
        $this->title = trim($this->title);
        $this->description = trim($this->description);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'taskStatus' => ['required', new Enum(TaskStatus::class)],
            'taskPriority' => ['required', new Enum(TaskPriority::class)],
            'startDate' => ['nullable', 'date'],
            'dueDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'completedDate' => ['nullable', 'date'],
            'progressPercentage' => ['required', 'integer', 'min:0', 'max:100'],
            'assignedTo' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'taskProposalId' => ['required', 'integer', Rule::exists(Proposal::class, 'id')],
            'taskMilestoneId' => ['nullable', 'integer', Rule::exists(Milestone::class, 'id')],
        ], [
            'title.required' => __('Enter a task title.'),
            'assignedTo.required' => __('Assign this task to someone.'),
            'taskProposalId.required' => __('Select the proposal this task delivers against.'),
            'dueDate.after_or_equal' => __('The due date cannot fall before the start date.'),
            'progressPercentage.max' => __('Progress cannot exceed 100 percent.'),
        ]);

        $isCreate = $this->editingTaskId === null;

        $task = $isCreate
            ? new Task
            : Task::query()->findOrFail($this->editingTaskId);

        $attributes = [
            'title' => $validated['title'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => $validated['taskStatus'],
            'priority' => $validated['taskPriority'],
            'start_date' => $validated['startDate'] === '' ? null : $validated['startDate'],
            'due_date' => $validated['dueDate'] === '' ? null : $validated['dueDate'],
            'completed_date' => $validated['completedDate'] === '' ? null : $validated['completedDate'],
            'progress_percentage' => (int) $validated['progressPercentage'],
            'assigned_to' => (int) $validated['assignedTo'],
            'proposal_id' => (int) $validated['taskProposalId'],
            'milestone_id' => $validated['taskMilestoneId'] === '' || $validated['taskMilestoneId'] === null
                ? null
                : (int) $validated['taskMilestoneId'],
        ];

        // created_by records authorship. It is set once, on create, and is
        // never editable — an edit by someone else must not rewrite history.
        if ($isCreate) {
            $attributes['created_by'] = (int) auth()->id();
        }

        $task->fill($attributes)->save();

        $this->showTaskModal = false;
        $this->resetTaskForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('Task created.') : __('Task updated.'),
        );
    }

    public function confirmDelete(int $taskId): void
    {
        $this->deletingTaskId = $taskId;
    }

    /**
     * Tasks delete freely.
     *
     * task_updates and task_attachments both cascade from tasks, and both
     * belong to the task alone — nothing else references them. Deleting a task
     * therefore cannot reach any row that is not the task's own.
     */
    public function deleteTask(): void
    {
        if ($this->deletingTaskId === null) {
            return;
        }

        Task::query()->findOrFail($this->deletingTaskId)->delete();

        $this->deletingTaskId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Task deleted.'));
    }

    public function closeTaskModal(): void
    {
        $this->showTaskModal = false;
        $this->resetTaskForm();
    }

    protected function resetTaskForm(): void
    {
        $this->resetValidation();
        $this->editingTaskId = null;
        $this->title = '';
        $this->description = '';
        $this->taskStatus = 'todo';
        $this->taskPriority = 'medium';
        $this->startDate = '';
        $this->dueDate = '';
        $this->completedDate = '';
        $this->progressPercentage = '0';
        $this->assignedTo = '';
        $this->taskProposalId = '';
        $this->taskMilestoneId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $priority !== '' || $assignee !== '' || $milestone !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="task-directory-heading">
    <x-ui.page-header
        heading-id="task-directory-heading"
        :title="__('Tasks')"
        :count="$this->tasks->total()"
        :description="__('Work items across every proposal, soonest due first.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search tasks')" :placeholder="__('Title, description, or assignee')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Status')" wire:model.live="status">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (TaskStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'task-status-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Priority')" wire:model.live="priority">
                <flux:select.option value="">{{ __('All priorities') }}</flux:select.option>
                @foreach (TaskPriority::cases() as $priorityCase)
                    <flux:select.option :value="$priorityCase->value" :wire:key="'task-priority-'.$priorityCase->value">{{ Str::title($priorityCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Assignee')" wire:model.live="assignee">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'task-assignee-'.$userOption->id">{{ $userOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Milestone')" wire:model.live="milestone">
                <flux:select.option value="">{{ __('Any milestone') }}</flux:select.option>
                <flux:select.option value="none">{{ __('No milestone') }}</flux:select.option>
                @foreach ($this->milestoneOptions as $milestoneOption)
                    <flux:select.option :value="$milestoneOption->id" :wire:key="'task-milestone-'.$milestoneOption->id">
                        {{ $milestoneOption->name }} · {{ $milestoneOption->project->name }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-task-button">
                {{ __('Add task') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching task|[2,*] :count matching tasks', $this->tasks->total(), ['count' => $this->tasks->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->tasks->isEmpty())
            <x-ui.empty-state
                icon="clipboard-document-check"
                :heading="$filtersAreActive ? __('No matching tasks') : __('No tasks yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Add the first task against a proposal.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->tasks as $task)
                    <article class="flex flex-col gap-3 p-3.5 @4xl/directory:flex-row @4xl/directory:items-center" wire:key="task-{{ $task->id }}">
                        <div class="min-w-0 @4xl/directory:me-auto">
                            <a href="{{ route('tasks.show', $task) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $task->title }}</a>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($task->milestone === null)
                                    <span class="italic">{{ __('No milestone') }}</span>
                                @else
                                    {{ $task->milestone->name }} · {{ $task->milestone->project->name }}
                                @endif
                                · {{ $task->proposal->name }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.enum-badge :case="$task->status" />
                            <x-ui.enum-badge :case="$task->priority" />
                            <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $task->progress_percentage }}%</span>
                            @if ($task->due_date !== null)
                                <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ __('Due :date', ['date' => $task->due_date->format('M j')]) }}</span>
                            @endif
                        </div>

                        <x-ui.user-chip :user="$task->assignee" compact class="@4xl/directory:w-44" />

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $task->id }})" :aria-label="__('Edit :title', ['title' => $task->title])" />
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $task->id }})" :aria-label="__('Delete :title', ['title' => $task->title])" />
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->tasks" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#task-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showTaskModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveTask" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTaskId === null ? __('Add task') : __('Edit task') }}</flux:heading>
                <flux:subheading>{{ __('Every task delivers against a proposal. A milestone is optional.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="title" :label="__('Task title')" type="text" maxlength="255" required autofocus />

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="taskProposalId" :label="__('Proposal')" required>
                    <flux:select.option value="">{{ __('Select a proposal') }}</flux:select.option>
                    @foreach ($this->proposalOptions as $proposalOption)
                        <flux:select.option :value="$proposalOption->id" :wire:key="'task-proposal-option-'.$proposalOption->id">{{ $proposalOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="taskMilestoneId" :label="__('Milestone')">
                    <flux:select.option value="">{{ __('No milestone') }}</flux:select.option>
                    @foreach ($this->milestoneOptions as $milestoneOption)
                        <flux:select.option :value="$milestoneOption->id" :wire:key="'task-milestone-option-'.$milestoneOption->id">
                            {{ $milestoneOption->name }} · {{ $milestoneOption->project->name }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-ui.select wire:model="taskStatus" :label="__('Status')" required>
                    @foreach (TaskStatus::cases() as $statusCase)
                        <flux:select.option :value="$statusCase->value" :wire:key="'task-status-option-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="taskPriority" :label="__('Priority')" required>
                    @foreach (TaskPriority::cases() as $priorityCase)
                        <flux:select.option :value="$priorityCase->value" :wire:key="'task-priority-option-'.$priorityCase->value">{{ Str::title($priorityCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.input wire:model="progressPercentage" :label="__('Progress %')" type="number" min="0" max="100" required />

                <x-ui.select wire:model="assignedTo" :label="__('Assignee')" required>
                    <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'task-assignee-option-'.$userOption->id">{{ $userOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="startDate" :label="__('Start date')" type="date" />
                <x-ui.input wire:model="dueDate" :label="__('Due date')" type="date" />
                <x-ui.input wire:model="completedDate" :label="__('Completed date')" type="date" />
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTaskModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-task-button">
                    {{ $editingTaskId === null ? __('Create task') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingTaskId !== null" wire:close="$set('deletingTaskId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this task?') }}</flux:heading>
            <flux:subheading>{{ __('Its comments and attachment records are removed with it. Nothing outside the task is affected.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingTaskId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteTask" data-test="confirm-delete-task">{{ __('Delete task') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/TaskDirectoryTest.php`
Expected: the filter, create, edit and empty-state tests PASS. The row link to `tasks.show` requires Task 6; implement Task 6's route (Step 3) if the run blocks, then return.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/tasks tests/Feature/TaskDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 6: Task detail, update timeline and attachments

**Files:**
- Create: `resources/views/pages/tasks/⚡show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TaskDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.user-chip`, `x-ui.stat-tile`, `x-ui.button`.
- Produces: route `tasks.show`; component `pages::tasks.show`; public properties `public int $taskId`, `public string $comment`; method `addUpdate()`.

**Two constraints.** `task_updates` is an append-only comment log: this page creates them and never edits or deletes them, and `user_id` comes from `auth()->id()`, never from a picker. `task_attachments` is **metadata only** — `storage/app` holds no files, so the panel renders filename, type and date, and emits **no link and no download control**.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\FileType;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from task detail', function () {
    $task = Task::factory()->create();

    $this->get(route('tasks.show', $task))->assertRedirect(route('login'));
});

test('task detail shows the task its proposal assignee and creator', function () {
    $user = User::factory()->create();
    $assignee = User::factory()->create(['name' => 'Alice Nadir']);
    $creator = User::factory()->create(['name' => 'Bob Rahman']);
    $task = Task::factory()->create([
        'title' => 'Draft distribution plan',
        'assigned_to' => $assignee->id,
        'created_by' => $creator->id,
    ]);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Draft distribution plan')
        ->assertSeeText('Alice Nadir')
        ->assertSeeText('Bob Rahman');
});

test('task detail shows only its own updates newest first', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $other = Task::factory()->create();

    TaskUpdate::factory()->create(['task_id' => $task->id, 'comment' => 'Mine first', 'date' => now()->subDay()]);
    TaskUpdate::factory()->create(['task_id' => $task->id, 'comment' => 'Mine second', 'date' => now()]);
    TaskUpdate::factory()->create(['task_id' => $other->id, 'comment' => 'Not mine']);

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('Mine second')
        ->assertSeeText('Mine first')
        ->assertDontSeeText('Not mine')
        ->assertSeeTextInOrder(['Mine second', 'Mine first']);
});

test('an update records the authenticated user as its author', function () {
    $author = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($author)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('comment', '  Distribution list verified with the field team.  ')
        ->call('addUpdate')
        ->assertHasNoErrors()
        ->assertSet('comment', '');

    $update = TaskUpdate::query()->where('task_id', $task->id)->sole();

    expect($update->user_id)->toBe($author->id)
        ->and($update->comment)->toBe('Distribution list verified with the field team.');
});

test('an empty update is rejected', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->set('comment', '   ')
        ->call('addUpdate')
        ->assertHasErrors(['comment']);

    expect(TaskUpdate::query()->where('task_id', $task->id)->count())->toBe(0);
});

test('attachments are listed as metadata with no download link', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();
    $attachment = TaskAttachment::factory()->create([
        'task_id' => $task->id,
        'type' => FileType::Pdf,
    ]);

    $response = $this->actingAs($user)->get(route('tasks.show', $task))->assertOk();

    $response->assertSeeText('PDF');
    $response->assertDontSee('href="'.$attachment->path, false);
    $response->assertDontSee('download', false);
});

test('task detail renders a task with no updates and no attachments', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create();

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertOk()
        ->assertSeeText('No updates yet')
        ->assertSeeText('No files recorded');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/TaskDetailTest.php`
Expected: FAIL — `Route [tasks.show] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('tasks/{task}', 'pages::tasks.show')
        ->name('tasks.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/tasks/⚡show.blade.php`:

```blade
<?php

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Task')] class extends Component {
    public int $taskId;

    public string $comment = '';

    public function mount(Task $task): void
    {
        $this->taskId = $task->id;
    }

    #[Computed]
    public function task(): Task
    {
        return Task::query()
            ->select(['id', 'milestone_id', 'proposal_id', 'title', 'description', 'assigned_to', 'status', 'priority', 'start_date', 'due_date', 'completed_date', 'progress_percentage', 'created_by', 'updated_at'])
            ->with([
                // Nullable: a task may carry no milestone.
                'milestone:id,name,project_id',
                'milestone.project:id,name',
                'proposal:id,name,status',
                'assignee:id,name,email,role',
                'creator:id,name,email,role',
            ])
            ->findOrFail($this->taskId);
    }

    /** @return Collection<int, TaskUpdate> */
    #[Computed]
    public function updates(): Collection
    {
        return TaskUpdate::query()
            ->select(['id', 'task_id', 'user_id', 'comment', 'date'])
            ->where('task_id', $this->taskId)
            ->with('user:id,name,email,role')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, TaskAttachment> */
    #[Computed]
    public function attachments(): Collection
    {
        return TaskAttachment::query()
            ->select(['id', 'task_id', 'path', 'type', 'date'])
            ->where('task_id', $this->taskId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Append a comment to the task's log.
     *
     * task_updates is append-only: there is no edit and no delete. The author
     * is the authenticated user, never a picked value.
     */
    public function addUpdate(): void
    {
        $this->comment = trim($this->comment);

        $validated = $this->validate([
            'comment' => ['required', 'string'],
        ], [
            'comment.required' => __('Write an update before posting it.'),
        ]);

        TaskUpdate::query()->create([
            'task_id' => $this->taskId,
            'user_id' => (int) auth()->id(),
            'comment' => $validated['comment'],
            'date' => now(),
        ]);

        $this->comment = '';
        unset($this->updates);

        Flux::toast(variant: 'success', text: __('Update posted.'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Tasks'), 'url' => route('tasks.index')],
            ['label' => $this->task->title],
        ]"
        :title="$this->task->title"
        :subtitle="$this->task->proposal->name"
    >
        <x-slot:meta>
            @if ($this->task->milestone === null)
                <span class="italic">{{ __('No milestone') }}</span>
            @else
                <a href="{{ route('projects.show', $this->task->milestone->project) }}" wire:navigate class="font-medium hover:underline">
                    {{ $this->task->milestone->name }} · {{ $this->task->milestone->project->name }}
                </a>
            @endif
            <span>{{ __('Created by :name', ['name' => $this->task->creator->name]) }}</span>
        </x-slot:meta>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.enum-badge :case="$this->task->status" />
                <x-ui.enum-badge :case="$this->task->priority" />
            </div>
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('Progress')" :value="$this->task->progress_percentage.'%'" />
        <x-ui.stat-tile :label="__('Start')" :value="$this->task->start_date?->format('M j, Y') ?? '—'" />
        <x-ui.stat-tile :label="__('Due')" :value="$this->task->due_date?->format('M j, Y') ?? '—'" />
        <x-ui.stat-tile :label="__('Completed')" :value="$this->task->completed_date?->format('M j, Y') ?? '—'" />
    </div>

    @if ($this->task->description !== null)
        <x-ui.panel :title="__('Description')">
            <p class="p-3.5 text-sm text-secondary-medium dark:text-secondary-soft">{{ $this->task->description }}</p>
        </x-ui.panel>
    @endif

    <div class="grid gap-4 @4xl/detail:grid-cols-[1.6fr_1fr]">
        <x-ui.panel :title="__('Updates')" :count="$this->updates->count()" :subtitle="__('An append-only log. Posted updates cannot be edited or removed.')">
            <div class="border-b border-secondary-soft p-3.5 dark:border-secondary-medium">
                <form wire:submit="addUpdate" class="flex flex-col gap-2">
                    <flux:textarea
                        wire:model="comment"
                        :label="__('Post an update')"
                        rows="3"
                        :placeholder="__('What changed?')"
                        class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!"
                    />
                    <div class="flex justify-end">
                        <x-ui.button type="submit" tone="primary" size="sm" icon="paper-airplane" data-test="post-update-button">{{ __('Post update') }}</x-ui.button>
                    </div>
                </form>
            </div>

            @if ($this->updates->isEmpty())
                <x-ui.empty-state icon="chat-bubble-left-right" :heading="__('No updates yet')" :body="__('Post the first update to start this task\'s log.')" />
            @else
                <ol class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->updates as $update)
                        <li class="flex flex-col gap-2 p-3.5" wire:key="task-update-{{ $update->id }}">
                            <div class="flex items-center justify-between gap-3">
                                <x-ui.user-chip :user="$update->user" compact />
                                <time datetime="{{ $update->date?->toIso8601String() }}" class="shrink-0 text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                    {{ $update->date?->format('M j, Y · H:i') }}
                                </time>
                            </div>
                            <p class="text-sm text-secondary-dark dark:text-white">{{ $update->comment }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.panel>

        <x-ui.panel :title="__('Attachments')" :count="$this->attachments->count()" :subtitle="__('File records only. The files themselves are not stored in this system yet.')">
            @if ($this->attachments->isEmpty())
                <x-ui.empty-state icon="paper-clip" :heading="__('No files recorded')" :body="__('Attachment records for this task will appear here.')" />
            @else
                <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                    @foreach ($this->attachments as $attachment)
                        {{-- Deliberately not a link: storage/app holds no files, so a
                             download control would always 404. --}}
                        <li class="flex items-center gap-3 p-3.5" wire:key="task-attachment-{{ $attachment->id }}">
                            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">
                                {{ $attachment->type->value }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ basename($attachment->path) }}</p>
                                <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $attachment->date?->format('M j, Y') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    </div>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/TaskDetailTest.php tests/Feature/TaskDirectoryTest.php`
Expected: PASS — both, including Task 5's row-link test.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/tasks tests/Feature/TaskDetailTest.php
```

Stop and report. Do not commit.

---

### Task 7: Delivery sidebar group

**Files:**
- Modify: `resources/views/components/navigation/sidebar.blade.php`
- Modify: `tests/Feature/NavigationTest.php`

**Interfaces:**
- Consumes: routes `projects.index`, `milestones.index`, `tasks.index`, `risks.index`.
- Produces: no new interface.

`.ai/rules/app.md`: items are added only once their routes exist. All four now do.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/NavigationTest.php`:

```php
test('the sidebar carries a delivery group once its routes exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeTextInOrder(['Overview', 'Strategy', 'Delivery'])
        ->assertSeeText('Projects')
        ->assertSeeText('Milestones')
        ->assertSeeText('Tasks')
        ->assertSeeText('Risks');
});

test('a project detail page highlights the projects item', function () {
    $user = User::factory()->create();
    $project = \App\Models\Project::factory()->create();

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('data-current', false);
});
```

Remove the now-obsolete assertion from the existing test `the sidebar does not render a group for routes that do not exist yet` — it must still assert `Results & Learning` and `Funding` are absent, which remains true.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: FAIL — "Delivery" is not present.

- [ ] **Step 3: Add the group**

In `resources/views/components/navigation/sidebar.blade.php`, after the `Strategy` group and before `Administration`:

```blade
        <flux:sidebar.group
            :heading="__('Delivery')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="rectangle-stack"
                :href="route('projects.index')"
                :current="request()->routeIs('projects.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Projects') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="flag"
                :href="route('milestones.index')"
                :current="request()->routeIs('milestones.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Milestones') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="clipboard-document-check"
                :href="route('tasks.index')"
                :current="request()->routeIs('tasks.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Tasks') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="exclamation-triangle"
                :href="route('risks.index')"
                :current="request()->routeIs('risks.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Risks') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. Every pre-existing test stays green — this spine adds pages and changes no model, migration, observer or factory.

- [ ] **Step 6: Format, type-check and stage**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
git add resources/views/components/navigation/sidebar.blade.php tests/Feature/NavigationTest.php
```

Stop and report. Do not commit.

---

## Self-Review

**Spec coverage.** This plan implements six rows of the spec's sections table: Projects (index + detail, create/edit, no delete), Milestones (index + Project panel, guarded delete), Tasks (index + detail, full CRUD), Task updates (create only), Task attachments (metadata only), Risks (full CRUD). It also delivers the `project_proposal` pivot UI the spec assigns to Project detail. The Funding and M&E rows are out of scope for this file.

**Spec edge cases covered by a test.** Nullable `tasks.milestone_id` — "tasks with no milestone are listed and filterable" and the "No milestone" filter option. Nullable `plan_indicators.project_id` — covered on the Annual Plan detail page in the Foundation plan, and this plan's Project detail shows only targets that *do* name the project. Attachment metadata with no download — "attachments are listed as metadata with no download link" asserts the absence explicitly.

**Placeholder scan.** No TBD, no "handle edge cases", no "similar to Task N". Every code step carries literal file content. Two forward references exist and both are stated at the step that creates them: Task 1's row link needs Task 2's route, and Task 5's row link needs Task 6's route. Each pair is adjacent and the note says so.

**Type consistency.** Filter properties are `$search`, `$project`, `$status`, `$priority`, `$assignee`, `$milestone`. Form properties that would collide with a filter are prefixed with the model name — `$riskProjectId`, `$milestoneProjectId`, `$taskProposalId`, `$taskMilestoneId`, `$taskStatus`, `$taskPriority`, `$milestoneStatus` — so no form field is ever bound to a filter's value. Delete flow is `confirmDelete(int)` → `$deleting<Model>Id` → `delete<Model>()` on all three deletable tables. `canDelete(int): bool` exists only on Milestones, which is the only guarded case in this spine.

**Known risk.** `Milestone::descendants()` in `parentOptions()` costs one query per subtree level. Milestone trees are two levels deep in practice, and it runs only while the edit modal is open, so it is not hoisted into the paginated query. If a deep tree ever appears, that is the line to revisit.
