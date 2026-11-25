<?php

use App\Actions\Results\CopyResultTree;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft]);
    $projectId = Project::factory()->create(['program_id' => $this->program->id])->id;
    DB::table('project_proposal')->insert(['project_id' => $projectId, 'proposal_id' => $this->proposal->id]);
    $this->meal = mealManager();

    $this->outcome = Result::factory()->type(ResultType::Outcome)->create(['proposal_id' => $this->proposal->id, 'program_id' => $this->program->id]);
    $this->output = Result::factory()->type(ResultType::Output)->create(['proposal_id' => $this->proposal->id, 'parent_id' => $this->outcome->id, 'program_id' => $this->program->id]);
    $this->activity = Result::factory()->type(ResultType::Activity)->create(['proposal_id' => $this->proposal->id, 'parent_id' => null, 'program_id' => $this->program->id, 'statement' => 'Registration']);
});

function activityBoard(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? test()->meal)
        ->test('proposals.logframe', ['proposalId' => test()->proposal->id])
        ->call('setLayout', 'board');
}

test('the board lists an unlinked activity and links it to an output through the chooser', function () {
    activityBoard()
        ->assertSeeHtml('data-test="board-loose"')
        ->assertSee('Registration')
        ->call('pickFor', $this->activity->id)
        ->assertSeeHtml('data-test="board-chooser"')
        ->call('linkActivity', $this->activity->id, $this->output->id)
        ->assertSet('pickingId', null)
        ->assertSet('openOutputs', [$this->output->id])
        ->assertSee(__('proposals.board.all_linked'));

    expect($this->activity->fresh()->parent_id)->toBe($this->output->id);
});

test('an activity can be unlinked again', function () {
    $this->activity->forceFill(['parent_id' => $this->output->id])->save();

    activityBoard()->call('linkActivity', $this->activity->id, 0);

    expect($this->activity->fresh()->parent_id)->toBeNull();
});

test('an activity cannot be linked to another proposal\'s output or to an outcome', function () {
    $foreign = Result::factory()->type(ResultType::Output)->create(['proposal_id' => Proposal::factory()->create()->id]);

    activityBoard()->call('linkActivity', $this->activity->id, $foreign->id)->call('linkActivity', $this->activity->id, $this->outcome->id);

    expect($this->activity->fresh()->parent_id)->toBeNull();
});

test('someone without results permissions cannot link an activity', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('proposals.logframe', ['proposalId' => $this->proposal->id])
        ->call('linkActivity', $this->activity->id, $this->output->id)
        ->assertForbidden();

    expect($this->activity->fresh()->parent_id)->toBeNull();
});

test('the editor renames, needs a name, and switches schedule', function () {
    $board = activityBoard()->call('editActivity', $this->activity->id)->assertSeeHtml('data-test="activity-editor"');

    $board->set('actName', '  ')->call('saveActivityName')->assertHasErrors('actName');
    $board->set('actName', 'Registration of households')->call('saveActivityName')->assertHasNoErrors();
    expect($this->activity->fresh()->statement)->toBe('Registration of households');

    $board->call('setActivityMode', 'dates')->set('actStart', '2026-05-01')->set('actEnd', '2026-04-01')->call('saveActivityDates')->assertHasErrors('actEnd');
    $board->set('actEnd', '2026-06-30')->call('saveActivityDates')->assertHasNoErrors();
    expect($this->activity->fresh()->ends_on->toDateString())->toBe('2026-06-30');

    $board->call('setActivityMode', 'event');
    expect($this->activity->fresh()->starts_on)->toBeNull()->and($this->activity->fresh()->activity_kind->value)->toBe('event');
});

test('what an activity counts is added with an optional target, changed and removed', function () {
    $board = activityBoard()->call('editActivity', $this->activity->id);

    $board->set('newCountName', '')->call('addCount')->assertHasErrors('newCountName');
    $board->set('newCountName', 'number of registered HH')->set('newCountTarget', '50')->call('addCount')->assertHasNoErrors();

    $count = Indicator::query()->where('result_id', $this->activity->id)->sole();
    expect((float) $count->target)->toBe(50.0);

    $board->set("actCounts.{$count->id}.target", '')->call('saveCount', $count->id);
    expect($count->fresh()->target)->toBeNull();

    $board->call('deleteCount', $count->id);
    expect(Indicator::query()->where('result_id', $this->activity->id)->exists())->toBeFalse();
});

test('"Only unfinished" hides finished activities', function () {
    $this->activity->forceFill(['parent_id' => $this->output->id])->save();
    Indicator::factory()->create(['result_id' => $this->activity->id, 'target' => 10]);

    activityBoard()->set('boardUnfinished', true)->call('toggleOutput', $this->output->id)
        ->assertSee(__('proposals.board.hidden_by_filter'));
});

describe('copying from the program framework', function () {
    beforeEach(function () {
        $this->programImpact = Result::factory()->type(ResultType::Impact)->create(['program_id' => $this->program->id, 'proposal_id' => null, 'statement' => 'Resilient households']);
        $this->programOutcome = Result::factory()->type(ResultType::Outcome)->create(['program_id' => $this->program->id, 'proposal_id' => null, 'parent_id' => $this->programImpact->id, 'statement' => 'Projects grow']);
        $this->programIndicator = Indicator::factory()->create(['result_id' => $this->programOutcome->id, 'name' => 'Share of projects still open', 'target' => 80]);
        $this->actingAs($this->meal);
    });

    test('it copies a parent and its child that are both new here', function () {
        $counts = app(CopyResultTree::class)->copySelection($this->proposal, [$this->programImpact->id, $this->programOutcome->id], [], false);

        $impact = Result::query()->where('proposal_id', $this->proposal->id)->where('copied_from_id', $this->programImpact->id)->sole();
        $outcome = Result::query()->where('proposal_id', $this->proposal->id)->where('copied_from_id', $this->programOutcome->id)->sole();

        expect($counts['results'])->toBe(2)
            ->and($outcome->parent_id)->toBe($impact->id);
    });

    test('it copies only what was ticked and never duplicates what is already here', function () {
        $this->outcome->forceFill(['statement' => '  projects GROW ', 'parent_id' => null])->save();

        $counts = app(CopyResultTree::class)->copySelection($this->proposal, [$this->programImpact->id, $this->programOutcome->id], [$this->programIndicator->id], false);

        expect($counts)->toBe(['results' => 1, 'indicators' => 1, 'targets' => 0, 'risks' => 0])
            ->and(Result::query()->where('proposal_id', $this->proposal->id)->where('statement', 'Resilient households')->count())->toBe(1)
            ->and(Indicator::query()->where('result_id', $this->outcome->id)->sole()->target)->toBeNull();

        $again = fn () => app(CopyResultTree::class)->copySelection($this->proposal, [$this->programImpact->id, $this->programOutcome->id], [$this->programIndicator->id], false);
        expect($again)->toThrow(ValidationException::class);
    });

    test('with targets on, an empty target here takes the program target', function () {
        $this->outcome->forceFill(['statement' => 'Projects grow', 'parent_id' => null])->save();
        $here = Indicator::factory()->create(['result_id' => $this->outcome->id, 'name' => 'Share of projects still open', 'target' => null]);

        $counts = app(CopyResultTree::class)->copySelection($this->proposal, [], [$this->programIndicator->id], true);

        expect($counts['targets'])->toBe(1)->and((float) $here->fresh()->target)->toBe(80.0);
    });

    test('a child is not copied without its parent', function () {
        $counts = fn () => app(CopyResultTree::class)->copySelection($this->proposal, [$this->programOutcome->id], [], false);

        expect($counts)->toThrow(ValidationException::class);
    });

    test('the picker shows what is new and what is already here', function () {
        $this->outcome->forceFill(['statement' => 'Projects grow'])->save();

        $tree = activityBoard()->call('openPicker')->assertSeeHtml('data-test="program-picker"')->instance()->pickerTree;
        $rows = collect($tree['results'])->keyBy('id');

        expect($rows[$this->programImpact->id]['have'])->toBeFalse()
            ->and($rows[$this->programOutcome->id]['have'])->toBeTrue();
    });

    test('ticked program risks are copied with their source, unticked ones are not', function () {
        $ticked = Risk::factory()->onProgram($this->program)->rated(2, 3)->create(['name' => 'تأخر الموردين', 'name_en' => 'Supplier delay', 'solution' => 'Two suppliers']);
        $left = Risk::factory()->onProgram($this->program)->create(['name' => 'نقص الكوادر', 'name_en' => 'Staff gaps']);

        $counts = app(CopyResultTree::class)->copySelection($this->proposal, [], [], false, [$ticked->id]);

        $copy = Risk::query()->onLogframeOf($this->proposal->id)->sole();

        expect($counts['risks'])->toBe(1)
            ->and($copy->copied_from_id)->toBe($ticked->id)
            ->and($copy->program_id)->toBeNull()
            ->and([$copy->name, $copy->name_en, $copy->likelihood, $copy->severity, $copy->solution])->toBe(['تأخر الموردين', 'Supplier delay', 2, 3, 'Two suppliers'])
            ->and(Risk::query()->where('copied_from_id', $left->id)->exists())->toBeFalse()
            ->and($ticked->fresh()->program_id)->toBe($this->program->id);
    });

    test('a program risk already copied, or typed here in the same words, is not copied again', function () {
        $copied = Risk::factory()->onProgram($this->program)->create(['name' => 'تأخر الموردين', 'name_en' => 'Supplier delay']);
        $typed = Risk::factory()->onProgram($this->program)->create(['name' => 'نقص الكوادر', 'name_en' => 'Staff gaps']);
        Risk::factory()->onProposalFramework($this->proposal)->create(['name' => 'xx', 'copied_from_id' => $copied->id]);
        Risk::factory()->onProposalFramework($this->proposal)->create(['name' => '  نقص   الكوادر ']);
        $fresh = Risk::factory()->onProgram($this->program)->create(['name' => 'تقلب العملة', 'name_en' => 'Currency swings']);

        $tree = activityBoard()->call('openPicker')->instance()->pickerTree;
        $have = collect($tree['risks'])->pluck('have', 'id');

        expect($have[$copied->id])->toBeTrue()->and($have[$typed->id])->toBeTrue()->and($have[$fresh->id])->toBeFalse();

        $counts = app(CopyResultTree::class)->copySelection($this->proposal, [], [], false, [$copied->id, $typed->id, $fresh->id]);

        expect($counts['risks'])->toBe(1)->and(Risk::query()->onLogframeOf($this->proposal->id)->count())->toBe(3);
    });

    test('a risk of a program that is not on the proposal is ignored', function () {
        $foreign = Risk::factory()->onProgram(Program::factory()->create())->create();

        $copy = fn () => app(CopyResultTree::class)->copySelection($this->proposal, [], [], false, [$foreign->id]);

        expect($copy)->toThrow(ValidationException::class)
            ->and(Risk::query()->onLogframeOf($this->proposal->id)->exists())->toBeFalse();
    });

    test('the picker copies a risks-only selection through the page', function () {
        $risk = Risk::factory()->onProgram($this->program)->create(['name' => 'تأخر الموردين', 'name_en' => 'Supplier delay']);

        activityBoard()->call('openPicker')->assertSeeHtml('data-test="picker-risks"')
            ->call('copySelection', [], [], false, [$risk->id])
            ->assertHasNoErrors()
            ->assertSet('showPicker', false);

        expect(Risk::query()->onLogframeOf($this->proposal->id)->sole()->copied_from_id)->toBe($risk->id);
    });
});
