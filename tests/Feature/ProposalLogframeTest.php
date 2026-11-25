<?php

use App\Actions\Proposals\SyncLogframeTask;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = logframeProposal($this->program);
});

/**
 * A proposal linked to one project in each of the given programs.
 */
function logframeProposal(Program ...$programs): Proposal
{
    $proposal = Proposal::factory()->create();

    foreach ($programs as $program) {
        $projectId = Project::factory()->create(['program_id' => $program->id])->id;
        DB::table('project_proposal')->insert(['project_id' => $projectId, 'proposal_id' => $proposal->id]);
    }

    return $proposal;
}

/**
 * The logframe tab of the given proposal, as the given user.
 */
function logframeTab(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('proposals.logframe', ['proposalId' => $proposal->id]);
}

/**
 * A result in the proposal's framework, stored straight through the factory.
 */
function logframeNode(Proposal $proposal, ResultType $type, ?Result $parent = null, ?int $programId = null): Result
{
    return Result::factory()->type($type)->create([
        'proposal_id' => $proposal->id,
        'parent_id' => $parent?->id,
        'program_id' => $parent?->program_id ?? $programId,
    ]);
}

test('the proposal page shows the logical framework inside the proposal card', function () {
    $user = User::factory()->role('Financial')->create();

    Livewire::actingAs($user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSeeLivewire('proposals.logframe');
});

test('MEAL builds the four levels and links the root to the program framework', function () {
    $meal = mealManager();
    $programImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $this->program->id]);

    $tab = logframeTab($meal, $this->proposal)
        ->call('openCreateModal')
        ->set('statement', 'Families in the district are food secure.')
        ->set('resultType', 'impact')
        ->set('contributesToId', (string) $programImpact->id)
        ->call('saveResult')
        ->assertHasNoErrors();

    $impact = $this->proposal->results()->where('type', 'impact')->sole();

    $tab->call('openCreateModal', $impact->id)
        ->assertSet('resultType', 'outcome')
        ->set('statement', 'Households grow more of their own food.')
        ->call('saveResult')
        ->assertHasNoErrors();

    $outcome = $this->proposal->results()->where('type', 'outcome')->sole();

    $tab->call('openCreateModal', $outcome->id)
        ->assertSet('resultType', 'output')
        ->set('statement', 'Farmers trained in drip irrigation.')
        ->call('saveResult')
        ->assertHasNoErrors();

    $output = $this->proposal->results()->where('type', 'output')->sole();

    $tab->call('openCreateModal', $output->id)
        ->assertSet('resultType', 'activity')
        ->set('statement', 'Run four training sessions.')
        ->call('saveResult')
        ->assertHasNoErrors()
        // Results start folded (proposal page redesign): open them to see the activity.
        ->call('openAll')
        ->assertSee('Run four training sessions.');

    $activity = $this->proposal->results()->where('type', 'activity')->sole();

    expect($impact->contributes_to_result_id)->toBe($programImpact->id)
        ->and($impact->program_id)->toBe($this->program->id)
        ->and($activity->parent_id)->toBe($output->id)
        ->and($activity->program_id)->toBe($this->program->id);

    $tab->assertSeeHtml('data-test="add-child-result-'.$output->id.'"')
        ->assertDontSeeHtml('data-test="add-child-result-'.$activity->id.'"');
});

test('the contributes-to picker offers only program-framework results of the proposal programs', function () {
    $meal = mealManager();
    $ownProgramResult = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id]);
    $otherProgramResult = Result::factory()->type(ResultType::Outcome)->create(['program_id' => Program::factory()->create()->id]);
    $otherProposalRoot = logframeNode(logframeProposal($this->program), ResultType::Impact, programId: $this->program->id);

    $options = logframeTab($meal, $this->proposal)
        ->call('openCreateModal')
        ->set('resultType', 'impact')
        ->instance()
        ->contributesToOptions
        ->modelKeys();

    expect($options)->toBe([$ownProgramResult->id])
        ->not->toContain($otherProgramResult->id)
        ->not->toContain($otherProposalRoot->id);
});

test('Managers and MEAL officers read the framework but cannot write it', function (string $role) {
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $viewer = userWithRole($role);
    $viewer->givePermissionTo('proposals.view');
    $tab = fn () => logframeTab($viewer, $this->proposal);

    $tab()
        ->assertSee($impact->statement)
        ->assertDontSeeHtml('data-test="add-child-result-'.$impact->id.'"')
        ->assertDontSeeHtml('data-test="delete-result-');

    $tab()->call('openCreateModal', $impact->id)->assertForbidden();
    $tab()->call('openEditModal', $impact->id)->assertForbidden();
    $tab()->call('confirmDelete', $impact->id)->assertForbidden();
})->with(['Managers', 'MEAL']);

test('a proposal editor without results permissions cannot write the framework', function () {
    $financial = User::factory()->role('Financial')->create();
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $tab = fn () => logframeTab($financial, $this->proposal);

    $tab()->call('openCreateModal')->assertForbidden();
    $tab()->call('openEditModal', $impact->id)->assertForbidden();
    $tab()->call('saveResult')->assertForbidden();
    $tab()->call('confirmDelete', $impact->id)->assertForbidden();
    $tab()->call('deleteResult')->assertForbidden();

    $tab()->assertSee($impact->statement)
        ->assertDontSeeHtml('data-test="add-child-result-')
        ->assertDontSeeHtml('data-test="edit-result-')
        ->assertDontSeeHtml('data-test="create-logframe-result"');

    expect(Result::query()->count())->toBe(1);
});

test('another proposal\'s result cannot be reached from this tab', function () {
    $meal = mealManager();
    $foreignOutcome = logframeNode(logframeProposal($this->program), ResultType::Outcome, programId: $this->program->id);
    $tab = fn () => logframeTab($meal, $this->proposal);

    $tab()->call('openCreateModal', $foreignOutcome->id)->assertNotFound();
    $tab()->call('openEditModal', $foreignOutcome->id)->assertNotFound();
    $tab()->call('confirmDelete', $foreignOutcome->id)->assertNotFound();

    $tab()->call('openCreateModal')
        ->set('statement', 'Farmers trained in drip irrigation.')
        ->set('resultType', 'output')
        ->set('parentId', (string) $foreignOutcome->id)
        ->call('saveResult')
        ->assertHasErrors(['parentId' => __('results_framework.other_framework')]);

    expect($this->proposal->results()->count())->toBe(0);
});

test('the framework is editable at the concept note stage', function () {
    $meal = mealManager();

    expect($this->proposal->refresh()->status->value)->toBe('concept note');

    logframeTab($meal, $this->proposal)
        ->assertSeeHtml('data-test="create-logframe-result"')
        ->assertDontSee(__('results_framework.locked_read_only'))
        ->call('openCreateModal')
        ->set('statement', 'Families are food secure.')
        ->set('resultType', 'impact')
        ->call('saveResult')
        ->assertHasNoErrors();

    expect($this->proposal->results()->count())->toBe(1);
});

test('after submission only results.update holders change the structure', function () {
    DB::table('proposals')->where('id', $this->proposal->id)->update(['status' => 'submitted']);
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $creator = User::factory()->create(['employee_id' => holdMealManagerSlot()]);
    $creator->givePermissionTo(['results.create', 'proposals.view']);

    logframeTab($creator, $this->proposal)
        ->assertSee(__('results_framework.locked_read_only'))
        ->assertDontSeeHtml('data-test="create-logframe-result"')
        ->assertDontSeeHtml('data-test="add-child-result-'.$impact->id.'"')
        ->call('openCreateModal')
        ->set('statement', 'Households grow more of their own food.')
        ->set('resultType', 'outcome')
        ->set('parentId', (string) $impact->id)
        ->call('saveResult')
        ->assertHasErrors(['statement' => __('results_framework.structural_lock')]);

    logframeTab(userWithRole('General Manager'), $this->proposal)
        ->assertDontSee(__('results_framework.locked_read_only'))
        ->call('openCreateModal', $impact->id)
        ->set('statement', 'Households grow more of their own food.')
        ->call('saveResult')
        ->assertHasNoErrors();

    expect($this->proposal->results()->count())->toBe(2);
});

test('cross-cutting results render as their own roots after the chain', function () {
    $viewer = User::factory()->role('Team Member')->create();
    $viewer->givePermissionTo('proposals.view');
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $crossCutting = logframeNode($this->proposal, ResultType::CrossCutting, programId: $this->program->id);

    logframeTab($viewer, $this->proposal)
        ->assertSeeHtml('data-result-type="cross-cutting"')
        ->assertSeeInOrder([$impact->statement, $crossCutting->statement]);
});

test('a proposal spanning programs shows the multi-program badge', function () {
    $viewer = User::factory()->role('Team Member')->create();
    $viewer->givePermissionTo('proposals.view');

    logframeTab($viewer, $this->proposal)->assertDontSee(__('results_framework.multi_program'));
    logframeTab($viewer, logframeProposal($this->program, Program::factory()->create()))
        ->assertSee(__('results_framework.multi_program'));
});

test('the tab holds a flat query count whatever the framework size', function () {
    $user = mealManager();
    $programImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $this->program->id]);

    $seed = function (int $outputs) use ($programImpact): void {
        $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
        $impact->forceFill(['contributes_to_result_id' => $programImpact->id])->save();
        $outcome = logframeNode($this->proposal, ResultType::Outcome, $impact);

        foreach (range(1, $outputs) as $index) {
            $output = logframeNode($this->proposal, ResultType::Output, $outcome);
            logframeNode($this->proposal, ResultType::Activity, $output);
            insertIndicator($output->id);
        }

        Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id]);
    };

    $countQueries = function () use ($user): array {
        // The first render warms the permission cache; count the second.
        logframeTab($user, $this->proposal);
        DB::flushQueryLog();
        DB::enableQueryLog();

        logframeTab($user, $this->proposal);

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    };

    $seed(1);
    $small = $countQueries();

    $seed(14);
    $large = $countQueries();

    expect(count($large))->toBe(count($small))
        ->and(collect($large)->filter(fn (string $sql): bool => str_contains($sql, 'amount'))->all())->toBe([]);
});

test('a project proposal gets one logframe task for the MEAL Manager', function () {
    $employeeId = holdMealManagerSlot();
    $actor = User::factory()->role('Financial')->create();
    $this->actingAs($actor);

    $task = app(SyncLogframeTask::class)->ensure($this->proposal);
    app(SyncLogframeTask::class)->ensure($this->proposal);

    expect($task->kind)->toBe(TaskKind::Logframe)
        ->and($task->taskAssignees()->pluck('employee_id')->all())->toBe([$employeeId])
        ->and(Task::query()->withoutGlobalScopes()->where('kind', 'logframe')->count())->toBe(1);
});

test('confirming the framework needs a result, then closes the task', function () {
    $meal = mealManager();
    $this->actingAs($meal);
    $task = app(SyncLogframeTask::class)->ensure($this->proposal);

    logframeTab($meal, $this->proposal)
        ->call('markDone')
        ->assertHasErrors('doneNote')
        ->assertDontSeeHtml('data-test="framework-task-done"');

    logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);

    // Checks are still open, so the redesign asks for a reason.
    logframeTab($meal, $this->proposal)
        ->set('doneNote', 'Targets follow the donor call next week')
        ->call('markDone')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="framework-task-done"');

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('marking the framework as done tells the program manager (user decision 2026-11-19)', function () {
    $meal = mealManager();
    $managerEmployeeId = insertHrEmployee(firstNameEn: 'Pia', lastNameEn: 'Manager');
    $manager = userWithRole('Managers', $managerEmployeeId);
    $this->program->forceFill(['manager_id' => $managerEmployeeId])->save();
    $creator = User::factory()->role('Financial')->create();
    ProposalStatusNote::query()->create([
        'proposal_id' => $this->proposal->id,
        'status' => ProposalStatus::ConceptNote,
        'context' => 'Recorded',
        'date' => today()->toDateString(),
        'changed_by' => $creator->id,
        'is_correction' => false,
    ]);
    logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);

    logframeTab($meal, $this->proposal)->set('doneNote', 'Targets follow later')->call('markDone');

    $confirmed = fn (User $user): int => $user->fresh()->notifications->filter(fn ($note): bool => str_contains(json_encode($note->data), 'logframe_confirmed'))->count();

    expect($confirmed($manager))->toBe(1)
        ->and($confirmed($creator))->toBe(0)
        ->and($confirmed($meal))->toBe(0);
});

test('the single table shows the result levels in framework order', function () {
    $meal = mealManager();
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $outcome = logframeNode($this->proposal, ResultType::Outcome, $impact);
    $output = logframeNode($this->proposal, ResultType::Output, $outcome);

    logframeTab($meal, $this->proposal)
        ->assertSeeHtml('data-test="framework-table"')
        ->assertSeeInOrder([$impact->statement, $outcome->statement, $output->statement]);
});

test('adding a row below preserves the position when reopening the logframe', function () {
    $meal = mealManager();
    $first = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $last = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);

    logframeTab($meal, $this->proposal)
        ->call('addRowBelow', $first->id)
        ->set('statement', 'Inserted logframe row')
        ->call('saveResult')
        ->assertHasNoErrors();

    $inserted = $this->proposal->results()->where('statement', 'Inserted logframe row')->sole();
    logframeTab($meal, $this->proposal)->assertSeeHtmlInOrder([
        'framework-row-'.$first->id.'-0',
        'framework-row-'.$inserted->id.'-0',
        'framework-row-'.$last->id.'-0',
    ]);
});

test('the standalone page and proposal show the same table with localized indicators and targets', function (string $locale, string $name) {
    app()->setLocale($locale);
    $viewer = userWithRole('Team Member');
    $viewer->givePermissionTo('proposals.view');
    $result = logframeNode($this->proposal, ResultType::Outcome, programId: $this->program->id);
    $entry = LibraryIndicator::factory()->create(['name_ar' => 'الوصول إلى مياه آمنة', 'name_en' => 'Safe water access']);
    $indicator = Indicator::factory()->for($result)->for($entry, 'libraryIndicator')->create(['baseline' => 137, 'target' => 386]);

    $this->actingAs($viewer)->get(route('results.index'))->assertRedirect(route('meal.logframe'));

    Livewire::actingAs($viewer)->test('pages::results.logframe')
        ->set('selectedProposal', (string) $this->proposal->id)
        ->assertSeeLivewire('proposals.logframe')
        ->assertSeeHtml('data-test="framework-table"');

    logframeTab($viewer, $this->proposal)
        ->assertSee($name)
        ->assertSee('137')
        ->assertSee('386')
        ->assertDontSeeHtml('data-test="edit-indicator-'.$indicator->id.'"')
        ->assertDontSeeHtml('data-test="add-indicator-for-result-'.$result->id.'"');
})->with([
    'English' => ['en', 'Safe water access'],
    'Arabic' => ['ar', 'الوصول إلى مياه آمنة'],
]);

test('the table refreshes after attaching an entry or saving indicator values', function () {
    $meal = mealManager();
    $result = logframeNode($this->proposal, ResultType::Outcome, programId: $this->program->id);
    $entry = LibraryIndicator::factory()->create(['program_id' => $this->program->id, 'name_en' => 'Households trained']);
    $tab = logframeTab($meal, $this->proposal)->assertDontSee('Households trained');

    $form = Livewire::actingAs($meal)->test('indicators.quick-form', ['proposalId' => $this->proposal->id]);
    $form->call('addForResult', $result->id)->call('pickExistingEntry', $entry->id)
        ->assertHasNoErrors()
        ->assertDispatched('indicator-saved');

    $tab->dispatch('indicator-saved')->assertSee('Households trained');

    $form->call('editIndicator', $result->indicators()->sole()->id)->set('target', '439')
        ->call('saveIndicator')->assertHasNoErrors();

    $tab->dispatch('indicator-saved')->assertSee('439');
});

test('the activity calendar starts closed behind a toggle', function () {
    $meal = mealManager();
    $impact = logframeNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $output = logframeNode($this->proposal, ResultType::Output, logframeNode($this->proposal, ResultType::Outcome, $impact));
    logframeNode($this->proposal, ResultType::Activity, $output);

    Livewire::actingAs($meal)
        ->test('proposals.activity-calendar', ['proposalId' => $this->proposal->id])
        ->assertSeeHtml('calendarOpen: false')
        ->assertSeeHtml('data-test="calendar-toggle"');
});
