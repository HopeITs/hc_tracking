# M&E Spine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `results`, `indicators`, `plan_indicators`, `reports` and `report_files` reachable, readable and writable — and make the polymorphic target source visible as the thing it is.

**Architecture:** Livewire 4 single-file pages built from the `x-ui.*` components. The results logframe is a tree rendered through `HasTree`. Targets (`plan_indicators`) get the only UI in the application that writes a morph, with a two-step source picker that stores the map key. Reports carry three boolean workflow columns, each paired with a date, exposed as three explicit actions rather than checkboxes.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Flux 2, Tailwind 4, Pest 5.

**Depends on:** the Foundation plan (all 8 tasks). Targets link to annual plans and proposals; the proposal link is guarded behind `Route::has('proposals.show')` so this plan does **not** require the Funding plan.

## Global Constraints

- **Strict mode is on.** Lazy loading throws. Eager load every relation the view touches.
- **Palette is fixed.** Badge tone is palette-only: filled primary = terminal-negative, filled dark = active/achieved, outlined = pending/neutral.
- **The morph map holds exactly two entries** — `'yearly_plan' => YearlyPlan::class`, `'proposal' => Proposal::class`. `tests/Feature/Models/MorphMapTest.php` asserts that. **Add no new polymorphic relation in this plan.** `Relation::enforceMorphMap()` is global, so any unmapped model on either side of a morph throws `ClassMorphViolationException`.
- **`source_type` stores the map key, never a class name.** `'yearly_plan'`, not `App\Models\YearlyPlan`. The column is VARCHAR(50) and would not hold much else.
- **A target can be orphaned.** `plan_indicators.source_id` has no foreign key and cannot have one. A raw query-builder delete of a source leaves the target behind with a dangling id — `.ai/rules/database-migrations.md` records this and a test documents it. Every view that renders a source must handle `$target->source === null`.
- **`Period` is shared** by `results.checkout_period` and `plan_indicators.period`. One vocabulary: monthly, quarterly, semi-annual, annual.
- **`FileType` is the file extension**, not a display category. `report_files.path` is built from it. Do not turn it into pdf/image/word categories.
- **No file storage.** `storage/app` holds no files. Report files are metadata only: no link, no download, no upload.
- **No schema changes.** No migration, model, observer, factory or seeder is modified.
- **Tests** live at `tests/Feature/<Feature>Test.php`.
- **Pint** before any task is done. **Never commit** — stage and stop.

## Delete rules governing this spine

| Column | Rule | Consequence |
| --- | --- | --- |
| `results.parent_id` | cascade | Deleting a parent result takes its whole subtree |
| `results.program_id` | nullable, nullOnDelete | A result can outlive its program and must still render |
| `indicators.result_id` | cascade | Deleting a result removes its indicators — and then their targets and reports |
| `plan_indicators.indicator_id` | cascade | Deleting an indicator removes its targets |
| `plan_indicators.project_id` | nullable, nullOnDelete | A target may be organisation-wide |
| `reports.plan_indicator_id` | cascade | Deleting a target removes its reports |
| `reports.user_id` | **restrict** | Report authors are protected |
| `report_files.report_id` | cascade | A report's files go with it |

Therefore: **Results delete only when leaf.** **Indicators delete only when untargeted.** **Targets delete only when unreported.** **Reports have no delete** — they are the measurement record. **Report files are read-only.**

---

## File Structure

| File | Responsibility |
| --- | --- |
| `resources/views/pages/results/⚡index.blade.php` | Results logframe tree, CRUD, leaf-only delete |
| `resources/views/pages/indicators/⚡index.blade.php` | Indicators, CRUD, untargeted-only delete |
| `resources/views/pages/targets/⚡index.blade.php` | Targets with the morph source picker, CRUD, unreported-only delete |
| `resources/views/pages/reports/⚡index.blade.php` | Reports, filters, create/edit |
| `resources/views/pages/reports/⚡show.blade.php` | Report detail: workflow actions, file metadata |
| `tests/Feature/ResultsFrameworkTest.php` | Results tree and delete guard |
| `tests/Feature/IndicatorDirectoryTest.php` | Indicators and delete guard |
| `tests/Feature/TargetDirectoryTest.php` | Targets, the morph, orphan handling, delete guard |
| `tests/Feature/ReportDirectoryTest.php` | Reports index |
| `tests/Feature/ReportDetailTest.php` | Report detail and the three workflow actions |

Modified: `routes/web.php`, `resources/views/components/navigation/sidebar.blade.php`, `resources/views/pages/yearly-plans/⚡show.blade.php` (a link to filtered targets).

---

### Task 1: Results framework

**Files:**
- Create: `resources/views/pages/results/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ResultsFrameworkTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.tree-rows`, `x-ui.enum-badge`, `x-ui.select`, `x-ui.input`, `x-ui.button`.
- Produces: route `results.index`; component `pages::results.index`; public properties `$program`, `$type`, `$showResultModal`, `$editingResultId`, `$deletingResultId`, form fields `$statement`, `$resultType`, `$checkoutPeriod`, `$resultProgramId`, `$parentId`; methods `openCreateModal()`, `openEditModal(int)`, `saveResult()`, `confirmDelete(int)`, `deleteResult()`, `closeResultModal()`, `clearFilters()`; computed `parentOptions()`, `canDelete(int): bool`.

**Two things to get right.** `results.program_id` is **nullable** — a result with no program must still appear, under an "Unassigned" group, and `ResultFactory` can produce one. And the parent picker must exclude the node and its descendants, because `ResultObserver` throws `InvalidParentException` on a cycle; unlike milestones there is **no project constraint**, so any other result is a legal parent.

`Result::childrenOrderColumn()` returns `null` — results have no ordering column and keep insertion order. Do not add an `orderBy` to `children()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the results framework', function () {
    $this->get(route('results.index'))->assertRedirect(route('login'));
});

test('results render as a nested logframe grouped by program', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create(['name' => 'Water Security']);

    $impact = Result::factory()->create([
        'program_id' => $program->id,
        'statement' => 'Reduced waterborne disease.',
        'type' => ResultType::Impact,
    ]);
    Result::factory()->create([
        'program_id' => $program->id,
        'parent_id' => $impact->id,
        'statement' => 'Improved access to safe water.',
        'type' => ResultType::Outcome,
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Water Security')
        ->assertSee('Reduced waterborne disease.')
        ->assertSee('Improved access to safe water.')
        ->assertSee('data-tree-depth="1"', false);
});

test('a result with no program still appears under unassigned', function () {
    $user = User::factory()->create();

    Result::factory()->create([
        'program_id' => null,
        'statement' => 'Orphaned statement.',
    ]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->assertSee('Unassigned')
        ->assertSee('Orphaned statement.');
});

test('results can be filtered by program and type', function () {
    $user = User::factory()->create();
    $first = Program::factory()->create();
    $second = Program::factory()->create();

    Result::factory()->create(['program_id' => $first->id, 'statement' => 'First impact.', 'type' => ResultType::Impact]);
    Result::factory()->create(['program_id' => $second->id, 'statement' => 'Second output.', 'type' => ResultType::Output]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->set('program', (string) $first->id)
        ->assertSee('First impact.')
        ->assertDontSee('Second output.')
        ->call('clearFilters')
        ->set('type', ResultType::Output->value)
        ->assertDontSee('First impact.')
        ->assertSee('Second output.');
});

test('a result can be created under a parent', function () {
    $user = User::factory()->create();
    $program = Program::factory()->create();
    $parent = Result::factory()->create(['program_id' => $program->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openCreateModal')
        ->set('statement', '  Latrines constructed and handed over.  ')
        ->set('resultType', ResultType::Output->value)
        ->set('checkoutPeriod', Period::Quarterly->value)
        ->set('resultProgramId', (string) $program->id)
        ->set('parentId', (string) $parent->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    $result = Result::query()->where('statement', 'Latrines constructed and handed over.')->sole();

    expect($result->parent_id)->toBe($parent->id)
        ->and($result->type)->toBe(ResultType::Output);
});

test('the parent picker excludes the edited result and its own descendants', function () {
    $user = User::factory()->create();

    $root = Result::factory()->create(['statement' => 'Root statement.']);
    Result::factory()->create(['parent_id' => $root->id, 'statement' => 'Child statement.']);
    Result::factory()->create(['statement' => 'Unrelated statement.']);

    $component = Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('openEditModal', $root->id);

    $statements = $component->instance()->parentOptions()->pluck('statement')->all();

    expect($statements)->toContain('Unrelated statement.')
        ->not->toContain('Root statement.')
        ->not->toContain('Child statement.');
});

test('a leaf result with no indicators can be deleted', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult')
        ->assertHasNoErrors();

    expect(DB::table('results')->find($result->id))->toBeNull();
});

test('a result with children is not deleted because the subtree would cascade', function () {
    $user = User::factory()->create();
    $parent = Result::factory()->create();
    $child = Result::factory()->create(['parent_id' => $parent->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $parent->id)
        ->call('deleteResult');

    expect(DB::table('results')->find($parent->id))->not->toBeNull()
        ->and(DB::table('results')->find($child->id))->not->toBeNull();
});

test('a result carrying indicators is not deleted because targets and reports would cascade', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();
    $indicator = Indicator::factory()->create(['result_id' => $result->id]);

    Livewire::actingAs($user)
        ->test('pages::results.index')
        ->call('confirmDelete', $result->id)
        ->call('deleteResult');

    expect(DB::table('results')->find($result->id))->not->toBeNull()
        ->and(DB::table('indicators')->find($indicator->id))->not->toBeNull();
});

test('the results framework renders with no results', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::results.index')->assertSee('No results yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ResultsFrameworkTest.php`
Expected: FAIL — `Route [results.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('results', 'pages::results.index')
        ->name('results.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/results/⚡index.blade.php`:

```blade
<?php

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Result;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Results Framework')] class extends Component {
    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $type = '';

    public bool $showResultModal = false;
    public ?int $editingResultId = null;
    public ?int $deletingResultId = null;

    public string $statement = '';
    public string $resultType = 'outcome';
    public string $checkoutPeriod = 'quarterly';
    public string $resultProgramId = '';
    public string $parentId = '';

    public function clearFilters(): void
    {
        $this->program = '';
        $this->type = '';
    }

    /**
     * Logframe roots, grouped by program name with an explicit Unassigned key.
     *
     * results.program_id is nullable and nullOnDelete, so a result can outlive
     * its program. Those must still be reachable, not silently dropped.
     *
     * @return Collection<string, Collection<int, Result>>
     */
    #[Computed]
    public function groupedRoots(): Collection
    {
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->roots()
            ->when($this->program === 'none', fn ($query) => $query->whereNull('program_id'))
            ->when(
                $this->program !== '' && $this->program !== 'none',
                fn ($query) => $query->where('program_id', (int) $this->program)
            )
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->with(['program:id,name', 'childrenRecursive'])
            ->get()
            ->groupBy(fn (Result $result): string => $result->program?->name ?? __('Unassigned'));
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Results this one may contribute to.
     *
     * Excludes the node and its descendants so ResultObserver's
     * InvalidParentException stays a backstop. Unlike milestones there is no
     * project constraint: any other result is a legal parent.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $excludedIds = [];

        if ($this->editingResultId !== null) {
            $result = Result::query()
                ->whereKey($this->editingResultId)
                ->with('childrenRecursive')
                ->first();

            if ($result !== null) {
                $excludedIds = $result->descendants()->pluck('id')->push($result->id)->all();
            }
        }

        return Result::query()
            ->select(['id', 'statement', 'type', 'program_id'])
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether this result may be deleted.
     *
     * Refused when it has children (parent_id cascades, taking the subtree) or
     * indicators (indicators cascade, and plan_indicators and reports cascade
     * from those — three tables lost behind one click).
     */
    public function canDelete(int $resultId): bool
    {
        $result = Result::query()
            ->select(['id'])
            ->withCount(['children', 'indicators'])
            ->find($resultId);

        return $result !== null && $result->children_count === 0 && $result->indicators_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetResultForm();
        $this->showResultModal = true;
    }

    public function openEditModal(int $resultId): void
    {
        $result = Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->findOrFail($resultId);

        $this->resetValidation();
        $this->editingResultId = $result->id;
        $this->statement = $result->statement;
        $this->resultType = $result->type->value;
        $this->checkoutPeriod = $result->checkout_period->value;
        $this->resultProgramId = (string) ($result->program_id ?? '');
        $this->parentId = (string) ($result->parent_id ?? '');
        $this->showResultModal = true;
    }

    public function saveResult(): void
    {
        $this->statement = trim($this->statement);

        $validated = $this->validate([
            'statement' => ['required', 'string'],
            'resultType' => ['required', new Enum(ResultType::class)],
            'checkoutPeriod' => ['required', new Enum(Period::class)],
            'resultProgramId' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'parentId' => ['nullable', 'integer', Rule::exists(Result::class, 'id')],
        ], [
            'statement.required' => __('Write the result statement.'),
            'resultType.required' => __('Choose impact, outcome or output.'),
            'checkoutPeriod.required' => __('Choose how often this is checked.'),
        ]);

        $result = $this->editingResultId === null
            ? new Result
            : Result::query()->findOrFail($this->editingResultId);

        $result->fill([
            'statement' => $validated['statement'],
            'type' => $validated['resultType'],
            'checkout_period' => $validated['checkoutPeriod'],
            'program_id' => $validated['resultProgramId'] === '' || $validated['resultProgramId'] === null
                ? null
                : (int) $validated['resultProgramId'],
            'parent_id' => $validated['parentId'] === '' || $validated['parentId'] === null
                ? null
                : (int) $validated['parentId'],
        ])->save();

        $wasRecentlyCreated = $result->wasRecentlyCreated;

        $this->showResultModal = false;
        $this->resetResultForm();
        unset($this->groupedRoots);

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Result added.') : __('Result updated.'),
        );
    }

    public function confirmDelete(int $resultId): void
    {
        $this->deletingResultId = $resultId;
    }

    public function deleteResult(): void
    {
        if ($this->deletingResultId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingResultId)) {
            $this->deletingResultId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This result still has nested results or indicators. Remove those first.'),
            );

            return;
        }

        Result::query()->findOrFail($this->deletingResultId)->delete();

        $this->deletingResultId = null;
        unset($this->groupedRoots);

        Flux::toast(variant: 'success', text: __('Result deleted.'));
    }

    public function closeResultModal(): void
    {
        $this->showResultModal = false;
        $this->resetResultForm();
    }

    protected function resetResultForm(): void
    {
        $this->resetValidation();
        $this->editingResultId = null;
        $this->statement = '';
        $this->resultType = 'outcome';
        $this->checkoutPeriod = 'quarterly';
        $this->resultProgramId = '';
        $this->parentId = '';
    }
};
?>

@php
    $filtersAreActive = $program !== '' || $type !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="results-framework-heading">
    <x-ui.page-header
        heading-id="results-framework-heading"
        :title="__('Results Framework')"
        :description="__('Impact, outcome and output statements, nested as recorded.')"
    >
        <x-slot:filters>
            <x-ui.select :label="__('Program')" wire:model.live="program">
                <flux:select.option value="">{{ __('All programs') }}</flux:select.option>
                <flux:select.option value="none">{{ __('Unassigned') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'result-program-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Type')" wire:model.live="type">
                <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                @foreach (ResultType::cases() as $typeCase)
                    <flux:select.option :value="$typeCase->value" :wire:key="'result-type-'.$typeCase->value">{{ Str::title($typeCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-result-button">
                {{ __('Add result') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->groupedRoots->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('No matching results') : __('No results yet')"
                :body="$filtersAreActive ? __('Change the filters and try again.') : __('Add the first impact statement to start the logframe.')"
            />
        </x-ui.panel>
    @else
        @foreach ($this->groupedRoots as $programName => $roots)
            <x-ui.panel :title="$programName" :count="$roots->count()" wire:key="result-group-{{ Str::slug($programName) }}">
                <x-ui.tree-rows
                    :nodes="$roots"
                    :label="fn (\App\Models\Result $result): string => $result->statement"
                    :badge="fn (\App\Models\Result $result) => $result->type"
                />
            </x-ui.panel>
        @endforeach
    @endif

    <flux:modal wire:model="showResultModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveResult" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingResultId === null ? __('Add result') : __('Edit result') }}</flux:heading>
                <flux:subheading>{{ __('An output contributes to an outcome, which contributes to an impact.') }}</flux:subheading>
            </div>

            <flux:textarea wire:model="statement" :label="__('Statement')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="resultType" :label="__('Type')" required>
                    @foreach (ResultType::cases() as $typeCase)
                        <flux:select.option :value="$typeCase->value" :wire:key="'result-type-option-'.$typeCase->value">{{ Str::title($typeCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="checkoutPeriod" :label="__('Checked')" required>
                    @foreach (Period::cases() as $periodCase)
                        <flux:select.option :value="$periodCase->value" :wire:key="'result-period-'.$periodCase->value">{{ Str::title($periodCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="resultProgramId" :label="__('Program')">
                    <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'result-program-option-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="parentId" :label="__('Contributes to')">
                    <flux:select.option value="">{{ __('Top level') }}</flux:select.option>
                    @foreach ($this->parentOptions as $parentOption)
                        <flux:select.option :value="$parentOption->id" :wire:key="'result-parent-'.$parentOption->id">
                            {{ Str::limit($parentOption->statement, 60) }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeResultModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-result-button">
                    {{ $editingResultId === null ? __('Add result') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingResultId !== null" wire:close="$set('deletingResultId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this result?') }}</flux:heading>
            <flux:subheading>{{ __('Only a result with no nested results and no indicators can be deleted. Otherwise its indicators, their targets and every report against them would be removed too.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingResultId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteResult" data-test="confirm-delete-result">{{ __('Delete result') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/ResultsFrameworkTest.php`
Expected: PASS, including all three guard tests.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/results tests/Feature/ResultsFrameworkTest.php
```

Stop and report. Do not commit.

---

### Task 2: Indicator directory

**Files:**
- Create: `resources/views/pages/indicators/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/IndicatorDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `indicators.index`; component `pages::indicators.index`; public properties `$search`, `$result`, `$measure`, `$showIndicatorModal`, `$editingIndicatorId`, `$deletingIndicatorId`, form fields `$name`, `$indicatorResultId`, `$indicatorMeasure`; methods `openCreateModal()`, `openEditModal(int)`, `saveIndicator()`, `confirmDelete(int)`, `deleteIndicator()`, `closeIndicatorModal()`, `clearFilters()`; computed `canDelete(int): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\IndicatorMeasure;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Result;
use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the indicator directory', function () {
    $this->get(route('indicators.index'))->assertRedirect(route('login'));
});

test('indicators can be filtered by search result and measure', function () {
    $user = User::factory()->create();
    $first = Result::factory()->create(['statement' => 'Access improved.']);
    $second = Result::factory()->create(['statement' => 'Hygiene improved.']);

    Indicator::factory()->create(['result_id' => $first->id, 'name' => 'People reached', 'measure' => IndicatorMeasure::Numeric]);
    Indicator::factory()->create(['result_id' => $second->id, 'name' => 'Share satisfied', 'measure' => IndicatorMeasure::Percentage]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->assertSee('People reached')
        ->assertSee('Share satisfied')
        ->set('search', 'reached')
        ->assertSee('People reached')
        ->assertDontSee('Share satisfied')
        ->call('clearFilters')
        ->set('result', (string) $second->id)
        ->assertDontSee('People reached')
        ->assertSee('Share satisfied')
        ->call('clearFilters')
        ->set('measure', IndicatorMeasure::Numeric->value)
        ->assertSee('People reached')
        ->assertDontSee('Share satisfied');
});

test('an indicator can be created against a result', function () {
    $user = User::factory()->create();
    $result = Result::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', '  Litres per person per day  ')
        ->set('indicatorResultId', (string) $result->id)
        ->set('indicatorMeasure', IndicatorMeasure::Numeric->value)
        ->call('saveIndicator')
        ->assertHasNoErrors();

    $indicator = Indicator::query()->where('name', 'Litres per person per day')->sole();

    expect($indicator->result_id)->toBe($result->id)
        ->and($indicator->measure)->toBe(IndicatorMeasure::Numeric);
});

test('creating an indicator requires a name and a result', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('indicatorResultId', '')
        ->call('saveIndicator')
        ->assertHasErrors(['name', 'indicatorResultId']);
});

test('an untargeted indicator can be deleted', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->call('deleteIndicator')
        ->assertHasNoErrors();

    expect(DB::table('indicators')->find($indicator->id))->toBeNull();
});

test('an indicator carrying a target is not deleted because targets and reports would cascade', function () {
    $user = User::factory()->create();
    $indicator = Indicator::factory()->create();
    $target = PlanIndicator::factory()->create([
        'indicator_id' => $indicator->id,
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::indicators.index')
        ->call('confirmDelete', $indicator->id)
        ->call('deleteIndicator');

    expect(DB::table('indicators')->find($indicator->id))->not->toBeNull()
        ->and(DB::table('plan_indicators')->find($target->id))->not->toBeNull();
});

test('the indicator directory renders with no indicators', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::indicators.index')->assertSee('No indicators yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/IndicatorDirectoryTest.php`
Expected: FAIL — `Route [indicators.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('indicators', 'pages::indicators.index')
        ->name('indicators.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/indicators/⚡index.blade.php`:

```blade
<?php

use App\Enums\IndicatorMeasure;
use App\Models\Indicator;
use App\Models\Result;
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

new #[Title('Indicators')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $result = '';

    #[Url(except: '')]
    public string $measure = '';

    public bool $showIndicatorModal = false;
    public ?int $editingIndicatorId = null;
    public ?int $deletingIndicatorId = null;
    public string $name = '';
    public string $indicatorResultId = '';
    public string $indicatorMeasure = 'numeric';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedResult(): void
    {
        $this->resetPage();
    }

    public function updatedMeasure(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->result = '';
        $this->measure = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Indicator> */
    #[Computed]
    public function indicators(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'measure', 'updated_at'])
            ->with([
                'result:id,statement,type,program_id',
                // program_id is nullable on results.
                'result.program:id,name',
            ])
            ->withCount('planIndicators')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('result', fn ($query) => $query->where('statement', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->result !== '', fn ($query) => $query->where('result_id', (int) $this->result))
            ->when($this->measure !== '', fn ($query) => $query->where('measure', $this->measure))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, Result> */
    #[Computed]
    public function resultOptions(): Collection
    {
        return Result::query()->select(['id', 'statement', 'type'])->orderBy('id')->get();
    }

    /**
     * Whether this indicator may be deleted.
     *
     * Refused once any target exists: plan_indicators cascades from indicators,
     * and reports and report_files cascade from those.
     */
    public function canDelete(int $indicatorId): bool
    {
        $indicator = Indicator::query()->select(['id'])->withCount('planIndicators')->find($indicatorId);

        return $indicator !== null && $indicator->plan_indicators_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetIndicatorForm();
        $this->showIndicatorModal = true;
    }

    public function openEditModal(int $indicatorId): void
    {
        $indicator = Indicator::query()->select(['id', 'name', 'result_id', 'measure'])->findOrFail($indicatorId);

        $this->resetValidation();
        $this->editingIndicatorId = $indicator->id;
        $this->name = $indicator->name;
        $this->indicatorResultId = (string) $indicator->result_id;
        $this->indicatorMeasure = $indicator->measure->value;
        $this->showIndicatorModal = true;
    }

    public function saveIndicator(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'indicatorResultId' => ['required', 'integer', Rule::exists(Result::class, 'id')],
            'indicatorMeasure' => ['required', new Enum(IndicatorMeasure::class)],
        ], [
            'name.required' => __('Enter the indicator name.'),
            'indicatorResultId.required' => __('Select the result this indicator measures.'),
            'indicatorMeasure.required' => __('Choose whether this counts or is a percentage.'),
        ]);

        $indicator = $this->editingIndicatorId === null
            ? new Indicator
            : Indicator::query()->findOrFail($this->editingIndicatorId);

        $indicator->fill([
            'name' => $validated['name'],
            'result_id' => (int) $validated['indicatorResultId'],
            'measure' => $validated['indicatorMeasure'],
        ])->save();

        $wasRecentlyCreated = $indicator->wasRecentlyCreated;

        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Indicator created.') : __('Indicator updated.'),
        );
    }

    public function confirmDelete(int $indicatorId): void
    {
        $this->deletingIndicatorId = $indicatorId;
    }

    public function deleteIndicator(): void
    {
        if ($this->deletingIndicatorId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingIndicatorId)) {
            $this->deletingIndicatorId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This indicator carries targets. Remove them first — deleting it would take every report against them.'),
            );

            return;
        }

        Indicator::query()->findOrFail($this->deletingIndicatorId)->delete();

        $this->deletingIndicatorId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Indicator deleted.'));
    }

    public function closeIndicatorModal(): void
    {
        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
    }

    protected function resetIndicatorForm(): void
    {
        $this->resetValidation();
        $this->editingIndicatorId = null;
        $this->name = '';
        $this->indicatorResultId = '';
        $this->indicatorMeasure = 'numeric';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $result !== '' || $measure !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="indicator-directory-heading">
    <x-ui.page-header
        heading-id="indicator-directory-heading"
        :title="__('Indicators')"
        :count="$this->indicators->total()"
        :description="__('What each result is measured by.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search indicators')" :placeholder="__('Indicator or result statement')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Result')" wire:model.live="result">
                <flux:select.option value="">{{ __('All results') }}</flux:select.option>
                @foreach ($this->resultOptions as $resultOption)
                    <flux:select.option :value="$resultOption->id" :wire:key="'ind-result-'.$resultOption->id">{{ Str::limit($resultOption->statement, 50) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Measure')" wire:model.live="measure">
                <flux:select.option value="">{{ __('All measures') }}</flux:select.option>
                @foreach (IndicatorMeasure::cases() as $measureCase)
                    <flux:select.option :value="$measureCase->value" :wire:key="'ind-measure-'.$measureCase->value">{{ Str::title($measureCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-indicator-button">
                {{ __('Add indicator') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching indicator|[2,*] :count matching indicators', $this->indicators->total(), ['count' => $this->indicators->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->indicators->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('No matching indicators') : __('No indicators yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Add the first indicator against a result.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->indicators as $indicator)
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="indicator-{{ $indicator->id }}">
                        <div class="min-w-0 me-auto">
                            <p class="text-sm font-semibold">{{ $indicator->name }}</p>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ Str::limit($indicator->result->statement, 70) }}
                                · {{ $indicator->result->program?->name ?? __('Unassigned') }}
                            </p>
                        </div>

                        <x-ui.enum-badge :case="$indicator->result->type" />
                        <x-ui.enum-badge :case="$indicator->measure" />

                        <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft" title="{{ __('Targets') }}">
                            {{ $indicator->plan_indicators_count }}
                        </span>

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $indicator->id }})" :aria-label="__('Edit :name', ['name' => $indicator->name])" />

                        @if ($indicator->plan_indicators_count === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $indicator->id }})" :aria-label="__('Delete :name', ['name' => $indicator->name])" />
                        @else
                            <flux:tooltip :content="__('Indicators carrying targets cannot be deleted.')">
                                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                    <flux:icon.lock-closed class="size-4" />
                                </span>
                            </flux:tooltip>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->indicators" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#indicator-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showIndicatorModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveIndicator" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingIndicatorId === null ? __('Add indicator') : __('Edit indicator') }}</flux:heading>
                <flux:subheading>{{ __('An indicator measures exactly one result.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Indicator name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="indicatorResultId" :label="__('Result')" required>
                    <flux:select.option value="">{{ __('Select a result') }}</flux:select.option>
                    @foreach ($this->resultOptions as $resultOption)
                        <flux:select.option :value="$resultOption->id" :wire:key="'ind-result-option-'.$resultOption->id">{{ Str::limit($resultOption->statement, 60) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="indicatorMeasure" :label="__('Measure')" required>
                    @foreach (IndicatorMeasure::cases() as $measureCase)
                        <flux:select.option :value="$measureCase->value" :wire:key="'ind-measure-option-'.$measureCase->value">{{ Str::title($measureCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeIndicatorModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-indicator-button">
                    {{ $editingIndicatorId === null ? __('Create indicator') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingIndicatorId !== null" wire:close="$set('deletingIndicatorId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this indicator?') }}</flux:heading>
            <flux:subheading>{{ __('Only an indicator carrying no targets can be deleted.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingIndicatorId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteIndicator" data-test="confirm-delete-indicator">{{ __('Delete indicator') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/IndicatorDirectoryTest.php`
Expected: PASS

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/indicators tests/Feature/IndicatorDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 3: Targets — the morph

**Files:**
- Create: `resources/views/pages/targets/⚡index.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/pages/yearly-plans/⚡show.blade.php`
- Test: `tests/Feature/TargetDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.enum-badge`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `targets.index`; component `pages::targets.index`; public properties `$search`, `$sourceType`, `$project`, `$period`, `$showTargetModal`, `$editingTargetId`, `$deletingTargetId`, form fields `$targetSourceType`, `$targetSourceId`, `$targetIndicatorId`, `$targetValue`, `$targetProjectId`, `$targetPeriod`; methods `openCreateModal()`, `openEditModal(int)`, `saveTarget()`, `confirmDelete(int)`, `deleteTarget()`, `closeTargetModal()`, `clearFilters()`; computed `sourceOptions()`, `canDelete(int): bool`.

**This is the only page in the application that writes a polymorphic relation.** Four rules, each with a test:

1. `source_type` stores `'yearly_plan'` or `'proposal'` — the **morph map keys**. Never a class name.
2. The source-type select offers exactly those two values and nothing else.
3. Changing the source type reloads the source list (`updatedTargetSourceType()` clears `$targetSourceId`).
4. `$target->source` can be **null** — a source removed by a raw query-builder delete leaves a dangling `source_id` and no foreign key can prevent it. The row renders "Source no longer available" rather than throwing.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Period;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\User;
use App\Models\YearlyPlan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from the target directory', function () {
    $this->get(route('targets.index'))->assertRedirect(route('login'));
});

test('targets can be filtered by source type', function () {
    $user = User::factory()->create();
    $planIndicator = Indicator::factory()->create(['name' => 'Committed in a plan']);
    $proposalIndicator = Indicator::factory()->create(['name' => 'Committed in a proposal']);

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
        'indicator_id' => $planIndicator->id,
    ]);
    PlanIndicator::factory()->create([
        'source_type' => 'proposal',
        'source_id' => Proposal::factory()->create()->id,
        'indicator_id' => $proposalIndicator->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSee('Committed in a plan')
        ->assertSee('Committed in a proposal')
        ->set('sourceType', 'yearly_plan')
        ->assertSee('Committed in a plan')
        ->assertDontSee('Committed in a proposal')
        ->call('clearFilters')
        ->set('sourceType', 'proposal')
        ->assertDontSee('Committed in a plan')
        ->assertSee('Committed in a proposal');
});

test('a target stores the morph map key and never a class name', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'yearly_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetValue', '12000.00')
        ->set('targetPeriod', Period::Quarterly->value)
        ->call('saveTarget')
        ->assertHasNoErrors();

    $row = DB::table('plan_indicators')->where('indicator_id', $indicator->id)->sole();

    expect($row->source_type)->toBe('yearly_plan')
        ->and($row->source_type)->not->toContain('App\\Models')
        ->and((int) $row->source_id)->toBe($plan->id);
});

test('the source type select offers exactly the two mapped sources', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->assertSee('Annual plan')
        ->assertSee('Proposal')
        ->assertDontSee('App\\Models', false);
});

test('changing the source type clears the chosen source', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'yearly_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetSourceType', 'proposal')
        ->assertSet('targetSourceId', '');
});

test('a target whose source was removed renders instead of throwing', function () {
    $user = User::factory()->create();
    $plan = YearlyPlan::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'Orphaned target indicator']);

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
    ]);

    // A query-builder delete fires no model events, so
    // PlanIndicatorSourceObserver never runs and the target is orphaned. No
    // foreign key can stop this; the UI must survive it.
    DB::table('yearly_plans')->where('id', $plan->id)->delete();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSee('Orphaned target indicator')
        ->assertSee('Source no longer available');
});

test('a target with no project reads as organisation wide', function () {
    $user = User::factory()->create();

    PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
        'indicator_id' => Indicator::factory()->create()->id,
        'project_id' => null,
    ]);

    Livewire::actingAs($user)->test('pages::targets.index')->assertSee('Organisation-wide');
});

test('an unreported target can be deleted', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('confirmDelete', $target->id)
        ->call('deleteTarget')
        ->assertHasNoErrors();

    expect(DB::table('plan_indicators')->find($target->id))->toBeNull();
});

test('a reported target is not deleted because its reports would cascade', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);
    $report = Report::factory()->create(['plan_indicator_id' => $target->id]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('confirmDelete', $target->id)
        ->call('deleteTarget');

    expect(DB::table('plan_indicators')->find($target->id))->not->toBeNull()
        ->and(DB::table('reports')->find($report->id))->not->toBeNull();
});

test('the target directory renders with no targets', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::targets.index')->assertSee('No targets yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/TargetDirectoryTest.php`
Expected: FAIL — `Route [targets.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('targets', 'pages::targets.index')
        ->name('targets.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/targets/⚡index.blade.php`:

```blade
<?php

use App\Enums\Period;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\YearlyPlan;
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

new #[Title('Targets')] class extends Component {
    use WithPagination;

    /**
     * The only two sources a target may be committed in.
     *
     * These are the morph map keys registered in AppServiceProvider, not class
     * names: plan_indicators.source_type is VARCHAR(50) and
     * Relation::enforceMorphMap() is global.
     */
    private const SOURCE_TYPES = ['yearly_plan', 'proposal'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sourceType = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $period = '';

    public bool $showTargetModal = false;
    public ?int $editingTargetId = null;
    public ?int $deletingTargetId = null;

    public string $targetSourceType = 'yearly_plan';
    public string $targetSourceId = '';
    public string $targetIndicatorId = '';
    public string $targetValue = '';
    public string $targetProjectId = '';
    public string $targetPeriod = 'quarterly';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSourceType(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    /**
     * A source chosen under one type is meaningless under the other, so
     * switching type clears it rather than carrying an id across.
     */
    public function updatedTargetSourceType(): void
    {
        $this->targetSourceId = '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->sourceType = '';
        $this->project = '';
        $this->period = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, PlanIndicator> */
    #[Computed]
    public function targets(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period', 'updated_at'])
            ->with([
                // morphTo resolves to YearlyPlan or Proposal — or to null, when
                // the source was removed by a write that fired no model events.
                'source',
                'indicator:id,name,result_id,measure',
                'indicator.result:id,statement,type',
                'project:id,name,city_id',
                'project.city:id,name',
            ])
            ->withCount('reports')
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('indicator', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
            })
            ->when($this->sourceType !== '', fn ($query) => $query->where('source_type', $this->sourceType))
            ->when($this->project === 'none', fn ($query) => $query->whereNull('project_id'))
            ->when(
                $this->project !== '' && $this->project !== 'none',
                fn ($query) => $query->where('project_id', (int) $this->project)
            )
            ->when($this->period !== '', fn ($query) => $query->where('period', $this->period))
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * The sources selectable under the currently chosen source type.
     *
     * @return array<int, array{id: int, label: string}>
     */
    #[Computed]
    public function sourceOptions(): array
    {
        if ($this->targetSourceType === 'proposal') {
            return Proposal::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'label' => $proposal->name])
                ->all();
        }

        return YearlyPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->with('program:id,name')
            ->orderByDesc('year')
            ->get()
            ->map(fn (YearlyPlan $plan): array => [
                'id' => $plan->id,
                'label' => __('FY :year · :program', ['year' => $plan->year, 'program' => $plan->program->name]),
            ])
            ->all();
    }

    /** @return Collection<int, Indicator> */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return Indicator::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether this target may be deleted.
     *
     * Refused once reported: reports cascade from plan_indicators, and
     * report_files cascade from reports. A measurement is not discarded to tidy
     * up a target.
     */
    public function canDelete(int $targetId): bool
    {
        $target = PlanIndicator::query()->select(['id'])->withCount('reports')->find($targetId);

        return $target !== null && $target->reports_count === 0;
    }

    /**
     * A human label for a target's source.
     *
     * Returns null when the source no longer exists. source_id carries no
     * foreign key, so a raw delete of a plan or proposal leaves this dangling
     * and the caller must render an explicit fallback.
     */
    public function sourceLabel(PlanIndicator $target): ?string
    {
        $source = $target->source;

        if ($source === null) {
            return null;
        }

        if ($source instanceof YearlyPlan) {
            return __('FY :year', ['year' => $source->year]);
        }

        if ($source instanceof Proposal) {
            return $source->name;
        }

        return null;
    }

    public function openCreateModal(): void
    {
        $this->resetTargetForm();
        $this->showTargetModal = true;
    }

    public function openEditModal(int $targetId): void
    {
        $target = PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->findOrFail($targetId);

        $this->resetValidation();
        $this->editingTargetId = $target->id;
        $this->targetSourceType = $target->source_type;
        $this->targetSourceId = (string) $target->source_id;
        $this->targetIndicatorId = (string) $target->indicator_id;
        $this->targetValue = (string) $target->target;
        $this->targetProjectId = (string) ($target->project_id ?? '');
        $this->targetPeriod = $target->period->value;
        $this->showTargetModal = true;
    }

    public function saveTarget(): void
    {
        $validated = $this->validate([
            'targetSourceType' => ['required', Rule::in(self::SOURCE_TYPES)],
            'targetSourceId' => ['required', 'integer'],
            'targetIndicatorId' => ['required', 'integer', Rule::exists(Indicator::class, 'id')],
            'targetValue' => ['required', 'numeric', 'min:0'],
            'targetProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
            'targetPeriod' => ['required', new Enum(Period::class)],
        ], [
            'targetSourceType.required' => __('Choose where this target was committed.'),
            'targetSourceType.in' => __('A target can only be committed in an annual plan or a proposal.'),
            'targetSourceId.required' => __('Select the annual plan or proposal.'),
            'targetIndicatorId.required' => __('Select the indicator being targeted.'),
            'targetValue.required' => __('Enter the target value.'),
            'targetValue.min' => __('A target cannot be negative.'),
        ]);

        // The chosen source must actually exist under the chosen type. A single
        // exists rule cannot express this, because the table depends on the type.
        $sourceExists = $validated['targetSourceType'] === 'proposal'
            ? Proposal::query()->whereKey((int) $validated['targetSourceId'])->exists()
            : YearlyPlan::query()->whereKey((int) $validated['targetSourceId'])->exists();

        if (! $sourceExists) {
            $this->addError('targetSourceId', __('The selected source is no longer available.'));

            return;
        }

        $target = $this->editingTargetId === null
            ? new PlanIndicator
            : PlanIndicator::query()->findOrFail($this->editingTargetId);

        $target->fill([
            // The map key, never the class name.
            'source_type' => $validated['targetSourceType'],
            'source_id' => (int) $validated['targetSourceId'],
            'indicator_id' => (int) $validated['targetIndicatorId'],
            'target' => $validated['targetValue'],
            'project_id' => $validated['targetProjectId'] === '' || $validated['targetProjectId'] === null
                ? null
                : (int) $validated['targetProjectId'],
            'period' => $validated['targetPeriod'],
        ])->save();

        $wasRecentlyCreated = $target->wasRecentlyCreated;

        $this->showTargetModal = false;
        $this->resetTargetForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Target committed.') : __('Target updated.'),
        );
    }

    public function confirmDelete(int $targetId): void
    {
        $this->deletingTargetId = $targetId;
    }

    public function deleteTarget(): void
    {
        if ($this->deletingTargetId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingTargetId)) {
            $this->deletingTargetId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This target has been reported against. Deleting it would remove those measurements.'),
            );

            return;
        }

        PlanIndicator::query()->findOrFail($this->deletingTargetId)->delete();

        $this->deletingTargetId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Target deleted.'));
    }

    public function closeTargetModal(): void
    {
        $this->showTargetModal = false;
        $this->resetTargetForm();
    }

    protected function resetTargetForm(): void
    {
        $this->resetValidation();
        $this->editingTargetId = null;
        $this->targetSourceType = 'yearly_plan';
        $this->targetSourceId = '';
        $this->targetIndicatorId = '';
        $this->targetValue = '';
        $this->targetProjectId = '';
        $this->targetPeriod = 'quarterly';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $sourceType !== '' || $project !== '' || $period !== '';
    $sourceTypeLabels = ['yearly_plan' => __('Annual plan'), 'proposal' => __('Proposal')];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="target-directory-heading">
    <x-ui.page-header
        heading-id="target-directory-heading"
        :title="__('Targets')"
        :count="$this->targets->total()"
        :description="__('What was committed, where it was committed, and against which project.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search targets')" :placeholder="__('Indicator name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Committed in')" wire:model.live="sourceType">
                <flux:select.option value="">{{ __('Any source') }}</flux:select.option>
                @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                    <flux:select.option :value="$sourceKey" :wire:key="'tgt-source-'.$sourceKey">{{ $sourceLabel }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Project')" wire:model.live="project">
                <flux:select.option value="">{{ __('Any project') }}</flux:select.option>
                <flux:select.option value="none">{{ __('Organisation-wide') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'tgt-project-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Period')" wire:model.live="period">
                <flux:select.option value="">{{ __('All periods') }}</flux:select.option>
                @foreach (Period::cases() as $periodCase)
                    <flux:select.option :value="$periodCase->value" :wire:key="'tgt-period-'.$periodCase->value">{{ Str::title($periodCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-target-button">
                {{ __('Commit target') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching target|[2,*] :count matching targets', $this->targets->total(), ['count' => $this->targets->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->targets->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('No matching targets') : __('No targets yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Commit the first target in an annual plan or a proposal.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->targets as $target)
                    @php $sourceLabel = $this->sourceLabel($target); @endphp
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="target-{{ $target->id }}">
                        <div class="min-w-0 me-auto">
                            <p class="text-sm font-semibold">{{ $target->indicator->name }}</p>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ Str::limit($target->indicator->result->statement, 60) }}
                                ·
                                @if ($target->project === null)
                                    {{ __('Organisation-wide') }}
                                @else
                                    {{ $target->project->name }} · {{ $target->project->city->name }}
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-1.5">
                            {{-- Rendered from the morph map key, never a class name. --}}
                            <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                {{ $sourceTypeLabels[$target->source_type] ?? $target->source_type }}
                            </span>

                            @if ($sourceLabel === null)
                                {{-- source_id has no foreign key: the plan or proposal can be
                                     removed out from under this row. --}}
                                <span class="rounded-md border border-primary px-2 py-0.5 text-[11px] font-semibold text-primary">
                                    {{ __('Source no longer available') }}
                                </span>
                            @else
                                <span class="text-xs font-medium">{{ $sourceLabel }}</span>
                            @endif
                        </div>

                        <x-ui.enum-badge :case="$target->period" />
                        <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>

                        <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft" title="{{ __('Reports') }}">
                            {{ $target->reports_count }}
                        </span>

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $target->id }})" :aria-label="__('Edit target')" />

                        @if ($target->reports_count === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $target->id }})" :aria-label="__('Delete target')" />
                        @else
                            <flux:tooltip :content="__('Targets that have been reported against cannot be deleted.')">
                                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                    <flux:icon.lock-closed class="size-4" />
                                </span>
                            </flux:tooltip>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->targets" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#target-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showTargetModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveTarget" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTargetId === null ? __('Commit target') : __('Edit target') }}</flux:heading>
                <flux:subheading>{{ __('A target is committed either in an annual operational plan or in a donor proposal.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model.live="targetSourceType" :label="__('Committed in')" required>
                    @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                        <flux:select.option :value="$sourceKey" :wire:key="'tgt-source-option-'.$sourceKey">{{ $sourceLabel }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="targetSourceId" :label="$targetSourceType === 'proposal' ? __('Proposal') : __('Annual plan')" required>
                    <flux:select.option value="">{{ __('Select a source') }}</flux:select.option>
                    @foreach ($this->sourceOptions as $sourceOption)
                        <flux:select.option :value="$sourceOption['id']" :wire:key="'tgt-src-'.$targetSourceType.'-'.$sourceOption['id']">
                            {{ $sourceOption['label'] }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.select wire:model="targetIndicatorId" :label="__('Indicator')" required>
                <flux:select.option value="">{{ __('Select an indicator') }}</flux:select.option>
                @foreach ($this->indicatorOptions as $indicatorOption)
                    <flux:select.option :value="$indicatorOption->id" :wire:key="'tgt-indicator-'.$indicatorOption->id">{{ $indicatorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="targetValue" :label="__('Target value')" type="number" step="0.01" min="0" required />

                <x-ui.select wire:model="targetPeriod" :label="__('Reporting period')" required>
                    @foreach (Period::cases() as $periodCase)
                        <flux:select.option :value="$periodCase->value" :wire:key="'tgt-period-option-'.$periodCase->value">{{ Str::title($periodCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="targetProjectId" :label="__('Project')">
                    <flux:select.option value="">{{ __('Organisation-wide') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'tgt-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTargetModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-target-button">
                    {{ $editingTargetId === null ? __('Commit target') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingTargetId !== null" wire:close="$set('deletingTargetId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this target?') }}</flux:heading>
            <flux:subheading>{{ __('Only a target with no reports can be deleted. Otherwise every measurement recorded against it would go too.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingTargetId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteTarget" data-test="confirm-delete-target">{{ __('Delete target') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
```

- [ ] **Step 5: Link the annual plan detail page to its targets**

In `resources/views/pages/yearly-plans/⚡show.blade.php`, add a toolbar link on the "Committed targets" panel so the filtered index is one click away. Add to the `x-ui.panel` for targets:

```blade
        <x-slot:toolbar>
            <a href="{{ route('targets.index', ['sourceType' => 'yearly_plan']) }}" wire:navigate class="text-xs font-semibold text-secondary-medium hover:underline dark:text-secondary-soft">
                {{ __('All annual plan targets') }}
            </a>
        </x-slot:toolbar>
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/TargetDirectoryTest.php tests/Feature/YearlyPlanDetailTest.php tests/Feature/Models/MorphMapTest.php`
Expected: PASS — all three. **`MorphMapTest` must still assert the map holds exactly two entries.** If it fails, a morph was added; revert that rather than editing the test.

- [ ] **Step 7: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages tests/Feature/TargetDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 4: Report directory

**Files:**
- Create: `resources/views/pages/reports/⚡index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ReportDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.page-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.user-chip`, `x-ui.input`, `x-ui.select`, `x-ui.button`.
- Produces: route `reports.index`; component `pages::reports.index`; public properties `$search`, `$approval` (`''`, `'approved'`, `'pending'`), `$seenByMeal` (`''`, `'seen'`, `'unseen'`), `$author`, `$showReportModal`, `$editingReportId`, form fields `$reportTargetId`, `$plannedDate`, `$value`, `$narrativeNotes`, `$submitDate`; methods `openCreateModal()`, `openEditModal(int)`, `saveReport()`, `closeReportModal()`, `clearFilters()`.

**Reports have no delete.** A measurement is the record. `user_id` is set from `auth()->id()` on create and never edited — `reports.user_id` RESTRICTs precisely because authorship matters.

The three workflow booleans are **not** editable here. They live on the detail page as explicit actions, so a boolean can never be written without its paired date.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use App\Models\YearlyPlan;
use Livewire\Livewire;

test('guests are redirected from the report directory', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('reports can be filtered by approval and meal visibility', function () {
    $user = User::factory()->create();

    Report::factory()->create(['value' => '111111.00', 'is_approved' => true, 'is_seen_by_meal' => true]);
    Report::factory()->create(['value' => '222222.00', 'is_approved' => false, 'is_seen_by_meal' => false]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->assertSee('111,111.00')
        ->assertSee('222,222.00')
        ->set('approval', 'approved')
        ->assertSee('111,111.00')
        ->assertDontSee('222,222.00')
        ->call('clearFilters')
        ->set('approval', 'pending')
        ->assertDontSee('111,111.00')
        ->assertSee('222,222.00')
        ->call('clearFilters')
        ->set('seenByMeal', 'unseen')
        ->assertDontSee('111,111.00')
        ->assertSee('222,222.00');
});

test('a report records its author from the authenticated user', function () {
    $author = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($author)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('plannedDate', '2026-03-31')
        ->set('value', '9500.00')
        ->set('narrativeNotes', '  Quarterly figure verified against distribution lists.  ')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = Report::query()->where('plan_indicator_id', $target->id)->sole();

    expect($report->user_id)->toBe($author->id)
        ->and($report->narrative_report_notes)->toBe('Quarterly figure verified against distribution lists.');
});

test('creating a report requires a target a planned date and a value', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', '')
        ->set('plannedDate', '')
        ->set('value', '')
        ->call('saveReport')
        ->assertHasErrors(['reportTargetId', 'plannedDate', 'value']);
});

test('editing a report does not change its recorded author', function () {
    $author = User::factory()->create();
    $editor = User::factory()->create();
    $report = Report::factory()->create(['user_id' => $author->id, 'value' => '100.00']);

    Livewire::actingAs($editor)
        ->test('pages::reports.index')
        ->call('openEditModal', $report->id)
        ->set('value', '200.00')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report->refresh();

    expect((float) $report->value)->toBe(200.0)->and($report->user_id)->toBe($author->id);
});

test('reports offer no delete', function () {
    $user = User::factory()->create();
    Report::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::reports.index');

    $component->assertDontSee('wire:click="confirmDelete');

    expect(method_exists($component->instance(), 'deleteReport'))->toBeFalse();
});

test('the report directory renders with no reports', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::reports.index')->assertSee('No reports yet');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ReportDirectoryTest.php`
Expected: FAIL — `Route [reports.index] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('reports', 'pages::reports.index')
        ->name('reports.index');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/reports/⚡index.blade.php`:

```blade
<?php

use App\Models\PlanIndicator;
use App\Models\Report;
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

new #[Title('Reports')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $approval = '';

    #[Url(except: '')]
    public string $seenByMeal = '';

    #[Url(except: '')]
    public string $author = '';

    public bool $showReportModal = false;
    public ?int $editingReportId = null;

    public string $reportTargetId = '';
    public string $plannedDate = '';
    public string $value = '';
    public string $narrativeNotes = '';
    public string $submitDate = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedApproval(): void
    {
        $this->resetPage();
    }

    public function updatedSeenByMeal(): void
    {
        $this->resetPage();
    }

    public function updatedAuthor(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->approval = '';
        $this->seenByMeal = '';
        $this->author = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Report> */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Report::query()
            ->select(['id', 'plan_indicator_id', 'user_id', 'planned_date', 'value', 'submit_date', 'is_approved', 'approved_date', 'is_editable', 'is_seen_by_meal'])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,measure',
                'planIndicator.project:id,name',
                'user:id,name,email,role',
            ])
            ->withCount('files')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('narrative_report_notes', 'like', '%'.$search.'%')
                        ->orWhereHas(
                            'planIndicator.indicator',
                            fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                        );
                });
            })
            ->when($this->approval === 'approved', fn ($query) => $query->where('is_approved', true))
            ->when($this->approval === 'pending', fn ($query) => $query->where('is_approved', false))
            ->when($this->seenByMeal === 'seen', fn ($query) => $query->where('is_seen_by_meal', true))
            ->when($this->seenByMeal === 'unseen', fn ($query) => $query->where('is_seen_by_meal', false))
            ->when($this->author !== '', fn ($query) => $query->where('user_id', (int) $this->author))
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targetOptions(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'indicator_id', 'target', 'period'])
            ->with('indicator:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function authorOptions(): Collection
    {
        return User::query()->select(['id', 'name', 'email', 'role'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetReportForm();
        $this->showReportModal = true;
    }

    public function openEditModal(int $reportId): void
    {
        $report = Report::query()
            ->select(['id', 'plan_indicator_id', 'planned_date', 'value', 'narrative_report_notes', 'submit_date'])
            ->findOrFail($reportId);

        $this->resetValidation();
        $this->editingReportId = $report->id;
        $this->reportTargetId = (string) $report->plan_indicator_id;
        $this->plannedDate = $report->planned_date?->toDateString() ?? '';
        $this->value = (string) $report->value;
        $this->narrativeNotes = $report->narrative_report_notes ?? '';
        $this->submitDate = $report->submit_date?->toDateString() ?? '';
        $this->showReportModal = true;
    }

    public function saveReport(): void
    {
        $this->narrativeNotes = trim($this->narrativeNotes);

        $validated = $this->validate([
            'reportTargetId' => ['required', 'integer', Rule::exists(PlanIndicator::class, 'id')],
            'plannedDate' => ['required', 'date'],
            'value' => ['required', 'numeric'],
            'narrativeNotes' => ['nullable', 'string'],
            'submitDate' => ['nullable', 'date'],
        ], [
            'reportTargetId.required' => __('Select the target this reports against.'),
            'plannedDate.required' => __('Set the period this figure covers.'),
            'value.required' => __('Enter the reported value.'),
        ]);

        $isCreate = $this->editingReportId === null;

        $report = $isCreate
            ? new Report
            : Report::query()->findOrFail($this->editingReportId);

        $attributes = [
            'plan_indicator_id' => (int) $validated['reportTargetId'],
            'planned_date' => $validated['plannedDate'],
            'value' => $validated['value'],
            'narrative_report_notes' => $validated['narrativeNotes'] === '' ? null : $validated['narrativeNotes'],
            'submit_date' => $validated['submitDate'] === '' ? null : $validated['submitDate'],
        ];

        // Authorship is set once. reports.user_id RESTRICTs on delete because
        // who reported a figure is part of the record.
        // The three workflow booleans are deliberately absent: they are written
        // only by the explicit actions on the detail page, each alongside its
        // paired date column.
        if ($isCreate) {
            $attributes['user_id'] = (int) auth()->id();
        }

        $report->fill($attributes)->save();

        $this->showReportModal = false;
        $this->resetReportForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('Report recorded.') : __('Report updated.'),
        );
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    protected function resetReportForm(): void
    {
        $this->resetValidation();
        $this->editingReportId = null;
        $this->reportTargetId = '';
        $this->plannedDate = '';
        $this->value = '';
        $this->narrativeNotes = '';
        $this->submitDate = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $approval !== '' || $seenByMeal !== '' || $author !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="report-directory-heading">
    <x-ui.page-header
        heading-id="report-directory-heading"
        :title="__('Reports')"
        :count="$this->reports->total()"
        :description="__('Reported figures against committed targets, newest period first.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search reports')" :placeholder="__('Indicator or narrative')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Approval')" wire:model.live="approval">
                <flux:select.option value="">{{ __('All reports') }}</flux:select.option>
                <flux:select.option value="approved">{{ __('Approved') }}</flux:select.option>
                <flux:select.option value="pending">{{ __('Awaiting approval') }}</flux:select.option>
            </x-ui.select>

            <x-ui.select :label="__('MEAL review')" wire:model.live="seenByMeal">
                <flux:select.option value="">{{ __('Any') }}</flux:select.option>
                <flux:select.option value="seen">{{ __('Seen by MEAL') }}</flux:select.option>
                <flux:select.option value="unseen">{{ __('Not yet seen') }}</flux:select.option>
            </x-ui.select>

            <x-ui.select :label="__('Reported by')" wire:model.live="author">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                @foreach ($this->authorOptions as $authorOption)
                    <flux:select.option :value="$authorOption->id" :wire:key="'rpt-author-'.$authorOption->id">{{ $authorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-report-button">
                {{ __('Record report') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching report|[2,*] :count matching reports', $this->reports->total(), ['count' => $this->reports->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->reports->isEmpty())
            <x-ui.empty-state
                icon="document-chart-bar"
                :heading="$filtersAreActive ? __('No matching reports') : __('No reports yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Record the first figure against a target.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->reports as $report)
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="report-{{ $report->id }}">
                        <div class="min-w-0 me-auto">
                            <a href="{{ route('reports.show', $report) }}" wire:navigate class="text-sm font-semibold hover:underline">
                                {{ $report->planIndicator->indicator->name }}
                            </a>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $report->planned_date?->format('M j, Y') }}
                                · {{ $report->planIndicator->project?->name ?? __('Organisation-wide') }}
                                · {{ trans_choice(':count file|:count files', $report->files_count, ['count' => $report->files_count]) }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-sm font-semibold tabular-nums">{{ number_format((float) $report->value, 2) }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ __('of :target', ['target' => number_format((float) $report->planIndicator->target, 2)]) }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($report->is_approved)
                                <span class="rounded-md border border-secondary-dark bg-secondary-dark px-2 py-0.5 text-[11px] font-semibold text-white dark:border-secondary-medium dark:bg-secondary-medium">{{ __('Approved') }}</span>
                            @else
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Pending') }}</span>
                            @endif

                            @if ($report->is_seen_by_meal)
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('MEAL') }}</span>
                            @endif
                        </div>

                        <x-ui.user-chip :user="$report->user" compact class="w-40" />

                        {{-- No delete: a measurement is the record. --}}
                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $report->id }})" :aria-label="__('Edit report')" />
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->reports" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#report-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showReportModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveReport" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingReportId === null ? __('Record report') : __('Edit report') }}</flux:heading>
                <flux:subheading>{{ __('Approval and MEAL review are recorded on the report page, not here.') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="reportTargetId" :label="__('Target')" required>
                <flux:select.option value="">{{ __('Select a target') }}</flux:select.option>
                @foreach ($this->targetOptions as $targetOption)
                    <flux:select.option :value="$targetOption->id" :wire:key="'rpt-target-'.$targetOption->id">
                        {{ $targetOption->indicator->name }} — {{ number_format((float) $targetOption->target, 2) }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="plannedDate" :label="__('Period covered')" type="date" required />
                <x-ui.input wire:model="value" :label="__('Reported value')" type="number" step="0.01" required />
                <x-ui.input wire:model="submitDate" :label="__('Submitted on')" type="date" />
            </div>

            <flux:textarea wire:model="narrativeNotes" :label="__('Narrative notes')" rows="4" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeReportModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-report-button">
                    {{ $editingReportId === null ? __('Record report') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/ReportDirectoryTest.php`
Expected: the filter, create, edit and no-delete tests PASS. The row link to `reports.show` needs Task 5; implement Task 5 Step 3 if the run blocks, then return.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/reports tests/Feature/ReportDirectoryTest.php
```

Stop and report. Do not commit.

---

### Task 5: Report detail and the three workflow actions

**Files:**
- Create: `resources/views/pages/reports/⚡show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ReportDetailTest.php`

**Interfaces:**
- Consumes: `x-ui.detail-header`, `x-ui.panel`, `x-ui.empty-state`, `x-ui.stat-tile`, `x-ui.enum-badge`, `x-ui.user-chip`, `x-ui.button`.
- Produces: route `reports.show`; component `pages::reports.show`; public property `public int $reportId`; methods `approve()`, `revokeApproval()`, `markSeenByMeal()`, `lockEditing()`, `unlockEditing()`.

**Why these are actions and not checkboxes.** Each boolean has a paired date column: `is_approved`/`approved_date`, `is_editable`/`edited_date`. A checkbox bound with `wire:model` writes the boolean alone and lets the date drift. Each action writes both together, in one `fill()`. `is_seen_by_meal` has no date column, so it is a one-way acknowledgement — there is no `markUnseenByMeal()`.

**Report files are metadata only.** No link, no download control. Asserted.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\FileType;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from report detail', function () {
    $report = Report::factory()->create();

    $this->get(route('reports.show', $report))->assertRedirect(route('login'));
});

test('report detail shows the figure its target and its author', function () {
    $user = User::factory()->create();
    $author = User::factory()->create(['name' => 'Layla Haddad']);
    $report = Report::factory()->create(['user_id' => $author->id, 'value' => '9500.00']);

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertSeeText('9,500.00')
        ->assertSeeText('Layla Haddad')
        ->assertSeeText($report->planIndicator->indicator->name);
});

test('approving a report writes the flag and the approval date together', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['is_approved' => false, 'approved_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('approve')
        ->assertHasNoErrors();

    $report->refresh();

    expect($report->is_approved)->toBeTrue()->and($report->approved_date)->not->toBeNull();
});

test('revoking approval clears the flag and the approval date together', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['is_approved' => true, 'approved_date' => '2026-03-01']);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('revokeApproval');

    $report->refresh();

    expect($report->is_approved)->toBeFalse()->and($report->approved_date)->toBeNull();
});

test('marking a report seen by meal is a one way acknowledgement', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['is_seen_by_meal' => false]);

    $component = Livewire::actingAs($user)->test('pages::reports.show', ['report' => $report]);

    $component->call('markSeenByMeal');

    expect($report->refresh()->is_seen_by_meal)->toBeTrue()
        ->and(method_exists($component->instance(), 'markUnseenByMeal'))->toBeFalse();
});

test('locking and unlocking editing writes the flag and the edited date together', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['is_editable' => true]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('lockEditing');

    $report->refresh();

    expect($report->is_editable)->toBeFalse()->and($report->edited_date)->not->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('unlockEditing');

    expect($report->refresh()->is_editable)->toBeTrue();
});

test('report files are listed as metadata with no download link', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();
    $file = ReportFile::factory()->create(['report_id' => $report->id, 'type' => FileType::Xlsx]);

    $response = $this->actingAs($user)->get(route('reports.show', $report))->assertOk();

    $response->assertSeeText('XLSX');
    $response->assertDontSee('href="'.$file->path, false);
    $response->assertDontSee('download', false);
});

test('report detail renders a report with no files', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertSeeText('No files recorded');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/ReportDetailTest.php`
Expected: FAIL — `Route [reports.show] not defined.`

- [ ] **Step 3: Add the route**

```php
    Route::livewire('reports/{report}', 'pages::reports.show')
        ->name('reports.show');
```

- [ ] **Step 4: Write the page**

`resources/views/pages/reports/⚡show.blade.php`:

```blade
<?php

use App\Models\Report;
use App\Models\ReportFile;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Report')] class extends Component {
    public int $reportId;

    public function mount(Report $report): void
    {
        $this->reportId = $report->id;
    }

    #[Computed]
    public function report(): Report
    {
        return Report::query()
            ->select([
                'id', 'plan_indicator_id', 'user_id', 'planned_date', 'value', 'narrative_report_notes',
                'submit_date', 'is_approved', 'approved_date', 'is_editable', 'edited_date', 'is_seen_by_meal',
            ])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,result_id,measure',
                'planIndicator.indicator.result:id,statement,type',
                'planIndicator.project:id,name',
                'user:id,name,email,role',
            ])
            ->withCount('files')
            ->findOrFail($this->reportId);
    }

    /** @return Collection<int, ReportFile> */
    #[Computed]
    public function files(): Collection
    {
        return ReportFile::query()
            ->select(['id', 'report_id', 'path', 'date', 'type'])
            ->where('report_id', $this->reportId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The share of the target this figure reaches, capped for display.
     *
     * Returns null when the target is zero, so the tile renders an em dash
     * rather than dividing by nothing.
     */
    #[Computed]
    public function achievement(): ?int
    {
        $target = (float) $this->report->planIndicator->target;

        if ($target <= 0.0) {
            return null;
        }

        return (int) floor(((float) $this->report->value / $target) * 100);
    }

    /**
     * Approve this figure.
     *
     * is_approved and approved_date are written together. A checkbox bound to
     * the boolean alone would let the date drift from it, which is why this is
     * an action.
     */
    public function approve(): void
    {
        Report::query()->findOrFail($this->reportId)->fill([
            'is_approved' => true,
            'approved_date' => now()->toDateString(),
        ])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('Report approved.'));
    }

    public function revokeApproval(): void
    {
        Report::query()->findOrFail($this->reportId)->fill([
            'is_approved' => false,
            'approved_date' => null,
        ])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('Approval revoked.'));
    }

    /**
     * Acknowledge that MEAL has reviewed this figure.
     *
     * One way on purpose: is_seen_by_meal has no paired date column, so there
     * is nothing to record about un-seeing it, and "MEAL never looked at this"
     * is not a state a reviewer can restore.
     */
    public function markSeenByMeal(): void
    {
        Report::query()->findOrFail($this->reportId)->fill(['is_seen_by_meal' => true])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('Marked as seen by MEAL.'));
    }

    public function lockEditing(): void
    {
        Report::query()->findOrFail($this->reportId)->fill([
            'is_editable' => false,
            'edited_date' => now()->toDateString(),
        ])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('Report locked.'));
    }

    public function unlockEditing(): void
    {
        Report::query()->findOrFail($this->reportId)->fill([
            'is_editable' => true,
            'edited_date' => now()->toDateString(),
        ])->save();

        unset($this->report);

        Flux::toast(variant: 'success', text: __('Report unlocked for editing.'));
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Reports'), 'url' => route('reports.index')],
            ['label' => $this->report->planIndicator->indicator->name],
        ]"
        :title="$this->report->planIndicator->indicator->name"
        :subtitle="$this->report->planIndicator->indicator->result->statement"
    >
        <x-slot:meta>
            <span class="tabular-nums">{{ __('Period to :date', ['date' => $this->report->planned_date?->format('M j, Y')]) }}</span>
            <span>{{ $this->report->planIndicator->project?->name ?? __('Organisation-wide') }}</span>
            <span>
                {{ $this->report->planIndicator->source_type === 'yearly_plan' ? __('Committed in an annual plan') : __('Committed in a proposal') }}
            </span>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.user-chip :user="$this->report->user" class="rounded-lg border border-secondary-soft p-2.5 dark:border-secondary-medium" />
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-4">
        <x-ui.stat-tile :label="__('Reported')" :value="number_format((float) $this->report->value, 2)" />
        <x-ui.stat-tile :label="__('Target')" :value="number_format((float) $this->report->planIndicator->target, 2)" />
        <x-ui.stat-tile :label="__('Achievement')" :value="$this->achievement === null ? '—' : $this->achievement.'%'" />
        <x-ui.stat-tile :label="__('Period')" :value="Str::title($this->report->planIndicator->period->value)" />
    </div>

    <x-ui.panel :title="__('Workflow')" :subtitle="__('Each action writes its flag and its date together, so the two can never disagree.')">
        <div class="flex flex-wrap items-center gap-3 p-3.5">
            <div class="flex flex-wrap items-center gap-2 me-auto">
                @if ($this->report->is_approved)
                    <span class="rounded-md border border-secondary-dark bg-secondary-dark px-2 py-0.5 text-xs font-semibold text-white dark:border-secondary-medium dark:bg-secondary-medium">
                        {{ __('Approved :date', ['date' => $this->report->approved_date?->format('M j, Y')]) }}
                    </span>
                @else
                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Awaiting approval') }}</span>
                @endif

                @if ($this->report->is_seen_by_meal)
                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Seen by MEAL') }}</span>
                @endif

                @if (! $this->report->is_editable)
                    <span class="rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('Locked') }}</span>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($this->report->is_approved)
                    <x-ui.button size="sm" icon="arrow-uturn-left" wire:click="revokeApproval" data-test="revoke-approval-button">{{ __('Revoke approval') }}</x-ui.button>
                @else
                    <x-ui.button size="sm" tone="primary" icon="check" wire:click="approve" data-test="approve-button">{{ __('Approve') }}</x-ui.button>
                @endif

                @unless ($this->report->is_seen_by_meal)
                    <x-ui.button size="sm" icon="eye" wire:click="markSeenByMeal" data-test="mark-seen-button">{{ __('Mark seen by MEAL') }}</x-ui.button>
                @endunless

                @if ($this->report->is_editable)
                    <x-ui.button size="sm" icon="lock-closed" wire:click="lockEditing" data-test="lock-button">{{ __('Lock editing') }}</x-ui.button>
                @else
                    <x-ui.button size="sm" icon="lock-open" wire:click="unlockEditing" data-test="unlock-button">{{ __('Unlock editing') }}</x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.panel>

    @if ($this->report->narrative_report_notes !== null)
        <x-ui.panel :title="__('Narrative')">
            <p class="p-3.5 text-sm text-secondary-medium dark:text-secondary-soft">{{ $this->report->narrative_report_notes }}</p>
        </x-ui.panel>
    @endif

    <x-ui.panel :title="__('Supporting files')" :count="$this->files->count()" :subtitle="__('File records only. The files themselves are not stored in this system yet.')">
        @if ($this->files->isEmpty())
            <x-ui.empty-state icon="paper-clip" :heading="__('No files recorded')" :body="__('Supporting file records for this report will appear here.')" />
        @else
            <ul class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->files as $file)
                    {{-- Deliberately not a link: storage/app holds no files. --}}
                    <li class="flex items-center gap-3 p-3.5" wire:key="report-file-{{ $file->id }}">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-secondary-light text-[11px] font-semibold text-secondary-medium uppercase dark:text-secondary-soft">
                            {{ $file->type->value }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ basename($file->path) }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">{{ $file->date?->format('M j, Y') }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.panel>
</section>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/ReportDetailTest.php tests/Feature/ReportDirectoryTest.php`
Expected: PASS — both.

- [ ] **Step 6: Format and stage**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php resources/views/pages/reports tests/Feature/ReportDetailTest.php
```

Stop and report. Do not commit.

---

### Task 6: Results & Learning sidebar group, and the full-suite gate

**Files:**
- Modify: `resources/views/components/navigation/sidebar.blade.php`
- Modify: `tests/Feature/NavigationTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/NavigationTest.php`:

```php
test('the sidebar carries a results and learning group once its routes exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Results & Learning')
        ->assertSeeText('Results Framework')
        ->assertSeeText('Indicators')
        ->assertSeeText('Targets')
        ->assertSeeText('Reports');
});
```

Delete the now-obsolete test `the sidebar does not render a group for routes that do not exist yet` — every group it guarded now exists. Deleting a test needs the user's approval per the project's Pest rules: **ask before removing it**, and if approval is not given, leave it and note the failure.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: FAIL — "Results & Learning" is not present.

- [ ] **Step 3: Add the group**

In `resources/views/components/navigation/sidebar.blade.php`, after the `Delivery` group and before `Funding`:

```blade
        <flux:sidebar.group
            :heading="__('Results & Learning')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="chart-bar"
                :href="route('results.index')"
                :current="request()->routeIs('results.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Results Framework') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="variable"
                :href="route('indicators.index')"
                :current="request()->routeIs('indicators.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Indicators') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="flag"
                :href="route('targets.index')"
                :current="request()->routeIs('targets.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Targets') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="document-chart-bar"
                :href="route('reports.index')"
                :current="request()->routeIs('reports.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Reports') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/pest tests/Feature/NavigationTest.php`
Expected: PASS

- [ ] **Step 5: Run the whole suite**

This is the final gate for all four plans.

Run: `php artisan test --compact`
Expected: PASS, with particular attention to these pre-existing tests, none of which may change:

- `tests/Feature/Models/MorphMapTest.php` — the map still holds exactly two entries
- `tests/Feature/Models/PlanIndicatorCleanupTest.php` and `tests/Feature/PlanIndicatorTest.php` — orphan behaviour unchanged
- `tests/Feature/Models/TreeIntegrityTest.php` — both cycle guards intact
- `tests/Feature/Models/TransactionalDeleteTest.php` and `tests/Feature/DonorProposalTest.php` — the money trail still protected
- `tests/Feature/DatabaseEncodingTest.php` and `tests/Feature/DatabaseSeederTest.php` — no schema drift
- `tests/Feature/YearlyPlanMatrixTest.php` — the matrix still issues exactly 4 queries

- [ ] **Step 6: Format, type-check and stage**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
git add resources/views/components/navigation/sidebar.blade.php tests/Feature/NavigationTest.php
```

Stop and report the full-suite result verbatim, including any failures. Do not commit.

---

## Self-Review

**Spec coverage.** Five rows of the spec's sections table: Results (tree, create/edit, leaf-only delete), Indicators (create/edit, untargeted-only delete), Targets (create/edit with the morph source picker, unreported-only delete), Reports (create/edit plus the three workflow actions, no delete), Report files (metadata only). With the Foundation, Delivery and Funding plans, all nineteen rows of the spec's sections table are now implemented.

**The spec's morph requirements each have a test.** Stores the map key, not a class name: "a target stores the morph map key and never a class name" asserts on the raw `DB::table` row and checks the string does not contain `App\Models`. Offers exactly two sources: "the source type select offers exactly the two mapped sources". Renders a dangling source: "a target whose source was removed renders instead of throwing" reproduces the exact documented route — a query-builder delete that fires no model events. And `MorphMapTest` is re-run in Task 3 Step 6 and again in Task 6 Step 5 to prove no morph was added.

**Placeholder scan.** No TBD, no vague instructions. Every code step carries literal file content. One forward reference — Task 4's row link needs Task 5's route — is stated at Task 4 Step 5.

**One step requires user approval, and says so.** Task 6 Step 1 deletes an obsolete navigation test. The project's Pest rules forbid deleting tests without approval, so the step instructs the implementer to ask first and to leave the test in place if approval is withheld. It does not assume permission.

**Type consistency.** Form fields carry a model prefix where a filter name would collide: `$resultType`, `$resultProgramId`, `$indicatorResultId`, `$indicatorMeasure`, `$targetSourceType`, `$targetSourceId`, `$targetIndicatorId`, `$targetValue`, `$targetProjectId`, `$targetPeriod`, `$reportTargetId`. `canDelete(int): bool` appears on Results, Indicators and Targets — exactly the three guarded tables — and nowhere else. Reports use `approve`/`revokeApproval`/`markSeenByMeal`/`lockEditing`/`unlockEditing`, none of which are named like a delete, because none of them are one.

**Known risk.** `sourceOptions()` loads every annual plan or every proposal with no pagination. At the current data volume (8 plans, 4 proposals) that is right; past a few hundred it wants a searchable picker. The same applies to `indicatorOptions()` and `targetOptions()`. Flagged rather than pre-optimised.
