<?php

use App\Actions\Proposals\SyncLogframeTask;
use App\Actions\Proposals\TransitionProposal;
use App\Actions\Results\SaveActivityTiming;
use App\Enums\AssumptionStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Enums\TaskStatus;
use App\Models\Assumption;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\User;
use App\Support\FrameworkChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-11-18');
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft]);
    $projectId = Project::factory()->create(['program_id' => $this->program->id])->id;
    DB::table('project_proposal')->insert(['project_id' => $projectId, 'proposal_id' => $this->proposal->id]);
    $this->meal = mealManager();
});

function frameworkBox(User $user): Testable
{
    return Livewire::actingAs($user)->test('proposals.logframe', ['proposalId' => test()->proposal->id]);
}

function frameworkResult(ResultType $type, ?Result $parent = null): Result
{
    return Result::factory()->type($type)->create([
        'proposal_id' => test()->proposal->id,
        'parent_id' => $parent?->id,
        'program_id' => $parent?->program_id ?? test()->program->id,
    ]);
}

test('the framework task opens when the proposal reaches Proposal draft, due seven days later', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::ConceptNoteSubmitted]);
    $this->actingAs($this->meal);

    expect(app(SyncLogframeTask::class)->taskOf($proposal))->toBeNull();

    app(TransitionProposal::class)->handle($proposal, ProposalStatus::ProposalDraft, ProposalStatus::ConceptNoteSubmitted, ['effective_date' => '2026-11-18']);

    $task = app(SyncLogframeTask::class)->taskOf($proposal);
    expect($task)->not->toBeNull()
        ->and($task->due_date->toDateString())->toBe('2026-11-25');
});

test('marking the framework as done with checks open needs a reason, and keeps it', function () {
    frameworkResult(ResultType::Outcome);
    $this->actingAs($this->meal);
    app(SyncLogframeTask::class)->ensure($this->proposal);

    frameworkBox($this->meal)->call('openDoneModal')->call('markDone')->assertHasErrors('doneNote');

    frameworkBox($this->meal)
        ->set('doneNote', 'Targets follow the donor call')
        ->call('markDone')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="framework-task-done"');

    $task = app(SyncLogframeTask::class)->taskOf($this->proposal);
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completion_note)->toBe('Targets follow the donor call')
        ->and($task->completed_by)->toBe($this->meal->id);
    $this->assertDatabaseHas('activities', ['subject_type' => 'proposal', 'subject_id' => $this->proposal->id, 'event' => 'proposal.framework_done']);
});

test('a PR Manager may reopen the done framework task; a team member may not', function () {
    frameworkResult(ResultType::Impact);
    $this->actingAs($this->meal);
    app(SyncLogframeTask::class)->ensure($this->proposal);
    app(SyncLogframeTask::class)->confirm($this->proposal, 'Done for now');

    $teamMember = User::factory()->role('Team Member')->create();
    frameworkBox($teamMember)->assertDontSeeHtml('data-test="framework-reopen"')->call('reopenTask')->assertForbidden();

    frameworkBox(User::factory()->role('Public Relations Manager')->create())
        ->assertSeeHtml('data-test="framework-reopen"')
        ->call('reopenTask')
        ->assertSeeHtml('data-test="framework-task"');

    expect(app(SyncLogframeTask::class)->taskOf($this->proposal)->status)->toBe(TaskStatus::Todo);
});

test('the four checks read the framework', function () {
    $outcome = frameworkResult(ResultType::Outcome);
    $output = frameworkResult(ResultType::Output, $outcome);
    Indicator::factory()->create(['result_id' => $outcome->id, 'target' => null]);
    frameworkResult(ResultType::Activity);

    $checks = collect(FrameworkChecks::for($this->proposal->id))->keyBy('key');

    expect($checks['ind']['ok'])->toBeFalse()->and($checks['ind']['count'])->toBe(1)
        ->and($checks['tgt']['count'])->toBe(1)
        ->and($checks['act']['count'])->toBe(1)
        ->and($checks['ra']['ok'])->toBeFalse();

    Risk::factory()->create(['proposal_id' => $this->proposal->id, 'result_id' => $output->id]);
    expect(collect(FrameworkChecks::for($this->proposal->id))->firstWhere('key', 'ra')['ok'])->toBeTrue();
});

test('an assumption is added to a result, changed and deleted', function () {
    $outcome = frameworkResult(ResultType::Outcome);

    $box = frameworkBox($this->meal)
        ->call('openAssumption', null, $outcome->id)
        ->set('assumptionStatement', 'Markets stay open')
        ->call('saveAssumption')
        ->assertHasNoErrors();

    $assumption = Assumption::query()->sole();
    expect($assumption->result_id)->toBe($outcome->id)->and($assumption->status)->toBe(AssumptionStatus::Holds);

    $box->call('setAssumptionStatus', $assumption->id, 'at_risk');
    expect($assumption->fresh()->status)->toBe(AssumptionStatus::AtRisk);

    $box->call('openAssumption', $assumption->id)->call('deleteAssumption');
    expect(Assumption::query()->count())->toBe(0);
});

test('an assumption needs text and a result of this proposal', function () {
    $foreign = Result::factory()->type(ResultType::Outcome)->create(['proposal_id' => Proposal::factory()->create()->id]);

    frameworkBox($this->meal)
        ->call('openAssumption')
        ->set('assumptionStatement', '')
        ->set('assumptionResultId', (string) $foreign->id)
        ->call('saveAssumption')
        ->assertHasErrors(['assumptionStatement', 'assumptionResultId']);

    expect(Assumption::query()->count())->toBe(0);
});

test('someone without results permissions cannot add an assumption', function () {
    $outcome = frameworkResult(ResultType::Outcome);

    frameworkBox(User::factory()->role('Team Member')->create())
        ->call('openAssumption', null, $outcome->id)
        ->assertForbidden();
});

test('an activity on set dates needs its end after its start; Continuous clears the dates', function () {
    $activity = frameworkResult(ResultType::Activity);
    $this->actingAs($this->meal);
    $action = app(SaveActivityTiming::class);

    expect(fn () => $action->handle($this->proposal, $activity, 'dates', '2026-05-01', '2026-04-01'))->toThrow(ValidationException::class);

    $action->handle($this->proposal, $activity, 'dates', '2026-05-01', '2026-06-30');
    expect($activity->fresh()->starts_on->toDateString())->toBe('2026-05-01');

    $action->handle($this->proposal, $activity, 'continuous', null, null);
    expect($activity->fresh()->starts_on)->toBeNull()->and($activity->fresh()->ends_on)->toBeNull();
});

test('the layout, layers and open results are remembered for the person', function () {
    $outcome = frameworkResult(ResultType::Outcome);

    frameworkBox($this->meal)->call('setLayout', 'matrix')->call('toggleLayer', 'targets')->call('toggleOpen', $outcome->id);

    frameworkBox($this->meal)
        ->assertSet('layout', 'matrix')
        ->assertSet('layers.targets', true)
        ->assertSet('openIds', [$outcome->id])
        ->assertSeeHtml('data-test="framework-matrix"');

    frameworkBox(User::factory()->role('Financial')->create())->assertSet('layout', 'chain')->assertSet('openIds', []);
});

test('the framework scrolls with the page under a sticky toolbar, with no fixed-box option', function () {
    frameworkResult(ResultType::Outcome);

    frameworkBox($this->meal)
        ->assertSeeHtmlInOrder(['data-test="framework-toolbar"', 'data-test="framework-search"', 'data-test="framework-body"'])
        ->assertDontSeeHtml('setTall');
});

test('the results chain shows one outcome tab in front, with its outputs folded', function () {
    $first = frameworkResult(ResultType::Outcome);
    $second = frameworkResult(ResultType::Outcome);
    $output = frameworkResult(ResultType::Output, $second);

    // Every panel is in the page so switching is instant; only the one in front shows.
    frameworkBox($this->meal)
        ->assertSet('focusedId', $first->id)
        ->assertSeeHtml('data-test="outcome-panel-'.$first->id.'"')
        ->assertSeeHtmlInOrder(['data-test="outcome-panel-'.$second->id.'"', 'style="display: none"'])
        ->call('focusOn', $second->id)
        ->assertSet('focusedId', $second->id)
        ->assertSeeHtml('data-test="node-toggle-'.$output->id.'"')
        ->assertSeeHtmlInOrder(['data-test="node-body-'.$output->id.'"', 'data-open="false"'])
        ->call('toggleChild', $output->id)
        ->assertSeeHtmlInOrder(['data-test="node-body-'.$output->id.'"', 'data-open="true"']);
});

test('an outcome keeps its indicators and its risks and assumptions folded until asked for', function () {
    $outcome = frameworkResult(ResultType::Outcome);

    frameworkBox($this->meal)
        ->assertSet('drawer', '')
        ->call('toggleDrawer', 'ind')
        ->assertSet('drawer', 'ind')
        ->assertSeeHtml('data-test="drawer-ind-'.$outcome->id.'"')
        ->call('toggleDrawer', 'ra')
        ->assertSet('drawer', 'ra')
        ->call('toggleDrawer', 'ra')
        ->assertSet('drawer', '');
});

test('activities linked to no output show as a count on the Activity board tab', function () {
    frameworkResult(ResultType::Activity);

    frameworkBox($this->meal)
        ->assertSeeHtml('data-test="loose-badge"')
        ->assertSee(trans_choice('proposals.framework.loose_banner', 1, ['count' => 1]))
        ->assertDontSeeHtml('data-test="loose-banner"');
});

test('search and "only results with problems" keep a matching child with its parent', function () {
    $impact = frameworkResult(ResultType::Impact);
    $outcome = frameworkResult(ResultType::Outcome, $impact);
    $outcome->forceFill(['statement' => 'Farmers sell more'])->save();
    $measured = frameworkResult(ResultType::Outcome, $impact);
    Indicator::factory()->create(['result_id' => $measured->id]);

    $box = frameworkBox($this->meal)->set('search', 'farmers');
    expect($box->instance()->visibleIds)->toEqualCanonicalizing([$impact->id, $outcome->id]);

    $box->set('search', '')->set('problemsOnly', true);
    expect($box->instance()->visibleIds)->toEqualCanonicalizing([$impact->id, $outcome->id]);
});

test('only someone who may edit indicators can click one open; a PR officer just reads it', function () {
    $outcome = frameworkResult(ResultType::Outcome);
    $indicator = Indicator::factory()->create(['result_id' => $outcome->id]);
    $opensEditor = "\$dispatch('edit-indicator', { indicatorId: {$indicator->id} })";

    frameworkBox($this->meal)->call('openAll')
        ->assertSeeHtml('data-test="framework-indicator-'.$indicator->id.'"')
        ->assertSeeHtml($opensEditor);

    frameworkBox(User::factory()->role('Public Relations Manager')->create())->call('openAll')
        ->assertSeeHtml('data-test="framework-indicator-'.$indicator->id.'"')
        ->assertDontSeeHtml($opensEditor)
        ->call('setLayout', 'matrix')
        ->assertDontSeeHtml($opensEditor);
});
