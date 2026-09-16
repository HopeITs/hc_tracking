<?php

use App\Enums\Period;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\User;
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
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
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
        ->set('sourceType', 'annual_plan')
        ->assertSee('Committed in a plan')
        ->assertDontSee('Committed in a proposal')
        ->call('clearFilters')
        ->set('sourceType', 'proposal')
        ->assertDontSee('Committed in a plan')
        ->assertSee('Committed in a proposal');
});

test('a target stores the morph map key and never a class name', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetValue', '12000.00')
        ->set('targetPeriod', Period::Quarterly->value)
        ->call('saveTarget')
        ->assertHasNoErrors();

    $row = DB::table('plan_indicators')->where('indicator_id', $indicator->id)->sole();

    expect($row->source_type)->toBe('annual_plan')
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
    $plan = AnnualPlan::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetSourceType', 'proposal')
        ->assertSet('targetSourceId', '');
});

test('a target whose source was removed renders instead of throwing', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'Orphaned target indicator']);

    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => $plan->id,
        'indicator_id' => $indicator->id,
    ]);

    // A query-builder delete fires no model events, so
    // PlanIndicatorSourceObserver never runs and the target is orphaned. No
    // foreign key can stop this; the UI must survive it.
    DB::table('annual_plans')->where('id', $plan->id)->delete();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->assertSee('Orphaned target indicator')
        ->assertSee('Source no longer available');
});

test('a target with no project reads as organisation wide', function () {
    $user = User::factory()->create();

    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => Indicator::factory()->create()->id,
        'project_id' => null,
    ]);

    Livewire::actingAs($user)->test('pages::targets.index')->assertSee('Organisation-wide');
});

test('an unreported target can be deleted', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
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
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
    ]);
    $report = Report::factory()->create(['plan_indicator_id' => $target->id]);

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('confirmDelete', $target->id)
        ->call('deleteTarget');

    expect(DB::table('plan_indicators')->find($target->id))->not->toBeNull()
        ->and(DB::table('reports')->find($report->id))->not->toBeNull();
});

test('a target value that would overflow the decimal column is rejected', function () {
    $user = User::factory()->create();
    $plan = AnnualPlan::factory()->create();
    $indicator = Indicator::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->set('targetSourceType', 'annual_plan')
        ->set('targetSourceId', (string) $plan->id)
        ->set('targetIndicatorId', (string) $indicator->id)
        ->set('targetValue', '10000000000')
        ->call('saveTarget')
        ->assertHasErrors(['targetValue' => 'max']);
});

test('the target directory renders with no targets', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::targets.index')->assertSee('No targets yet');
});
