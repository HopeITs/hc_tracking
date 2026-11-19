<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = mealManager();

    $program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();

    $cityId = insertHrCity('Idlib', 'إدلب');
    $this->sarmada = Project::factory()->create(['program_id' => $program->id, 'city_id' => $cityId, 'center_id' => insertHrCenter($cityId, 'Hope Center Sarmada', 'مركز الأمل سرمدا')]);
    $this->water = Project::factory()->create(['program_id' => $program->id, 'name' => 'Unmapped Water Project']);
    DB::table('project_proposal')->insert([
        ['project_id' => $this->sarmada->id, 'proposal_id' => $this->proposal->id],
        ['project_id' => $this->water->id, 'proposal_id' => $this->proposal->id],
    ]);

    $output = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $program->id, 'proposal_id' => $this->proposal->id]);
    $entry = LibraryIndicator::factory()->create(['measure' => IndicatorMeasure::Numeric, 'program_id' => $program->id]);
    $this->indicator = Indicator::factory()->create(['result_id' => $output->id, 'library_indicator_id' => $entry->id, 'code' => $entry->code, 'measure' => IndicatorMeasure::Numeric, 'target' => 100, 'frequency' => Period::Quarterly]);
});

test('an indicator not linked to the library says so instead of offering numbers', function () {
    $this->indicator->update(['library_indicator_id' => null, 'code' => null]);

    centreTargetsBox($this->meal)->call('openCentreTargets', $this->indicator->id)
        ->assertSeeHtml('data-test="centre-targets-not-linked"')
        ->assertDontSeeHtml('data-test="centre-targets-save"')
        ->assertDontSeeHtml('data-test="centre-target-'.$this->sarmada->id.'"');
});

function centreTargetsBox(User $user): Testable
{
    return Livewire::actingAs($user)->test('proposals.logframe', ['proposalId' => test()->proposal->id]);
}

test('the dialog lists each funded project by its centre, else by name, under one period', function () {
    $box = centreTargetsBox($this->meal)->call('openCentreTargets', $this->indicator->id)
        ->assertSet('showCentreTargetsModal', true)
        ->assertSet('centreTargetsPeriod', 'quarterly')
        ->assertSee('Hope Center Sarmada')
        ->assertSee('Unmapped Water Project')
        ->assertSee(__('proposals.framework.not_committed'));

    expect(array_column($box->instance()->centreTargetRows, 'projectId'))->toEqualCanonicalizing([$this->sarmada->id, $this->water->id]);
});

test('saving writes one target per centre with the chosen period and the framework shows them', function () {
    centreTargetsBox($this->meal)->call('openCentreTargets', $this->indicator->id)
        ->set('centreTargetsPeriod', 'monthly')
        ->set("centreTargets.{$this->sarmada->id}", '60')
        ->set("centreTargets.{$this->water->id}", '40')
        ->call('saveCentreTargets')
        ->assertHasNoErrors()
        ->assertSet('showCentreTargetsModal', false);

    $saved = PlanIndicator::query()->where('source_type', 'proposal')->where('source_id', $this->proposal->id)->where('indicator_id', $this->indicator->id)->get()->keyBy('project_id');

    expect((float) $saved[$this->sarmada->id]->target)->toBe(60.0)
        ->and($saved[$this->sarmada->id]->period)->toBe(Period::Monthly)
        ->and((float) $saved[$this->water->id]->target)->toBe(40.0);
});

test('clearing a number removes that centre target and leaves the others', function () {
    centreTargetsBox($this->meal)->call('openCentreTargets', $this->indicator->id)
        ->set("centreTargets.{$this->sarmada->id}", '60')
        ->set("centreTargets.{$this->water->id}", '40')
        ->call('saveCentreTargets');

    centreTargetsBox($this->meal)->call('openCentreTargets', $this->indicator->id)
        ->assertSet("centreTargets.{$this->sarmada->id}", '60')
        ->set("centreTargets.{$this->water->id}", '')
        ->call('saveCentreTargets')
        ->assertHasNoErrors();

    expect(PlanIndicator::query()->where('source_id', $this->proposal->id)->where('indicator_id', $this->indicator->id)->pluck('project_id')->all())->toBe([$this->sarmada->id]);
});

test('a percent target above 100 is refused on its own centre', function () {
    $this->indicator->update(['measure' => IndicatorMeasure::Percentage]);
    $this->indicator->libraryIndicator?->update(['measure' => IndicatorMeasure::Percentage]);

    centreTargetsBox($this->meal)->call('openCentreTargets', $this->indicator->id)
        ->assertSee(__('proposals.framework.centre_sum_percent'))
        ->set("centreTargets.{$this->sarmada->id}", '150')
        ->call('saveCentreTargets')
        ->assertHasErrors("centreTargets.{$this->sarmada->id}");

    expect(PlanIndicator::query()->where('source_id', $this->proposal->id)->exists())->toBeFalse();
});

test('someone without targets.update gets no link and is refused by the server', function () {
    $viewer = User::factory()->role('Public Relations Manager')->create();

    centreTargetsBox($viewer)
        ->set('layers.targets', true)
        ->assertDontSeeHtml('data-test="edit-centre-targets-'.$this->indicator->id.'"');

    centreTargetsBox($viewer)->call('openCentreTargets', $this->indicator->id)->assertForbidden();

    centreTargetsBox($this->meal)
        ->set('layers.targets', true)
        ->call('openAll')
        ->assertSeeHtml('data-test="edit-centre-targets-'.$this->indicator->id.'"');
});
