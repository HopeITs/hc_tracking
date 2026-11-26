<?php

use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Result;
use App\Models\Risk;
use App\Models\RiskLibrary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create(['name_en' => 'Water Security']);
    Result::factory()->create(['program_id' => $this->program->id, 'type' => ResultType::Impact]);
});

test('the program panel lists its risks below the framework', function () {
    Risk::factory()->onProgram($this->program)->rated(3, 2)->create(['name' => 'تأخر التمويل', 'name_en' => 'Funding delay', 'solution' => 'Keep a reserve.']);
    Risk::factory()->onProgram(Program::factory()->create())->create(['name_en' => 'Other program risk']);

    Livewire::actingAs(mealManager())
        ->test('pages::results.index')
        ->assertSeeHtml('data-test="program-risks-'.$this->program->id.'"')
        ->assertSee('Funding delay')
        ->assertSee('Keep a reserve.')
        ->assertSeeHtml('data-level="high"')
        ->assertDontSee('Other program risk')
        ->assertSeeHtml('data-test="add-program-risk-'.$this->program->id.'"');
});

test('MEAL adds, edits and removes a program risk', function () {
    $component = Livewire::actingAs(mealManager())
        ->test('pages::results.index')
        ->call('openRiskForm', $this->program->id)
        ->set('riskForm.name', 'تأخر التمويل')
        ->set('riskForm.name_en', 'Funding delay')
        ->set('riskForm.likelihood', '2')
        ->set('riskForm.severity', '3')
        ->set('riskForm.solution', 'Keep a reserve.')
        ->call('saveRisk')
        ->assertHasNoErrors()
        ->assertSet('showRiskModal', false)
        ->assertSee('Funding delay');

    $risk = Risk::query()->onProgram($this->program->id)->sole();
    expect($risk->likelihood)->toBe(2)->and($risk->severity)->toBe(3);

    $component->call('openRiskForm', $this->program->id, $risk->id)
        ->assertSet('riskForm.name_en', 'Funding delay')
        ->set('riskForm.name_en', 'Late funding')
        ->call('saveRisk')
        ->assertHasNoErrors()
        ->assertSee('Late funding');

    expect($risk->fresh()->name_en)->toBe('Late funding');

    $component->call('deleteRisk', $this->program->id, $risk->id)
        ->assertDontSee('Late funding');

    expect(Risk::query()->whereKey($risk->id)->exists())->toBeFalse();
});

test('a missing title is reported on the form field', function () {
    Livewire::actingAs(mealManager())
        ->test('pages::results.index')
        ->call('openRiskForm', $this->program->id)
        ->set('riskForm.likelihood', '1')
        ->set('riskForm.severity', '1')
        ->call('saveRisk')
        ->assertHasErrors(['riskForm.name', 'riskForm.name_en']);
});

test('a risk of another program cannot be opened or removed through this one', function () {
    $foreign = Risk::factory()->onProgram(Program::factory()->create())->create();

    $meal = mealManager();

    Livewire::actingAs($meal)->test('pages::results.index')->call('openRiskForm', $this->program->id, $foreign->id)->assertNotFound();
    Livewire::actingAs($meal)->test('pages::results.index')->call('deleteRisk', $this->program->id, $foreign->id)->assertNotFound();
    expect($foreign->fresh())->not->toBeNull();
});

test('a viewer without results.update sees no risk actions and a forged save is forbidden', function () {
    $risk = Risk::factory()->onProgram($this->program)->create(['name_en' => 'Funding delay']);
    $viewer = userWithRole('MEAL');

    expect($viewer->can('results.update'))->toBeFalse();

    Livewire::actingAs($viewer)
        ->test('pages::results.index')
        ->assertSee('Funding delay')
        ->assertDontSeeHtml('data-test="add-program-risk-')
        ->assertDontSeeHtml('data-test="edit-program-risk-')
        ->assertDontSeeHtml('data-test="remove-program-risk-')
        ->call('openRiskForm', $this->program->id)
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test('pages::results.index')
        ->set('riskForm.name', 'x')
        ->set('riskForm.name_en', 'x')
        ->set('riskForm.likelihood', '1')
        ->set('riskForm.severity', '1')
        ->call('saveRisk')
        ->assertNotFound();

    Livewire::actingAs($viewer)
        ->test('pages::results.index')
        ->call('deleteRisk', $this->program->id, $risk->id)
        ->assertForbidden();

    expect(Risk::query()->count())->toBe(1);
});

test('the risks block does not add queries per risk', function () {
    $user = mealManager();

    $countQueries = function () use ($user): int {
        Livewire::actingAs($user)->test('pages::results.index');
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test('pages::results.index');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    Risk::factory()->onProgram($this->program)->create();
    $small = $countQueries();

    Risk::factory()->count(5)->onProgram($this->program)->create();

    expect($countQueries())->toBe($small);
});

test('the open risk form offers the library titles as suggestions', function () {
    RiskLibrary::rememberTitles('تأخر التمويل', 'Funding delay');

    Livewire::actingAs(mealManager())
        ->test('pages::results.index')
        ->assertDontSeeHtml('data-test="risk-form"')
        ->call('openRiskForm', $this->program->id)
        ->assertSet('showRiskModal', true)
        ->assertSeeHtml('data-test="risk-form"')
        ->assertSeeHtml('<option value="Funding delay"></option>')
        ->assertSeeHtml('<option value="تأخر التمويل"></option>');
});
