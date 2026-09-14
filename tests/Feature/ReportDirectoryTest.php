<?php

use App\Enums\ReportStatus;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use App\Models\YearlyPlan;
use Livewire\Livewire;

test('guests are redirected from the report directory', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('reports can be filtered by status and meal visibility', function () {
    $user = User::factory()->create();

    Report::factory()->create(['value' => '111111.00', 'status' => ReportStatus::Approved, 'is_seen_by_meal' => true]);
    Report::factory()->create(['value' => '222222.00', 'status' => ReportStatus::Draft, 'is_seen_by_meal' => false]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->assertSee('111,111.00')
        ->assertSee('222,222.00')
        ->set('status', 'approved')
        ->assertSee('111,111.00')
        ->assertDontSee('222,222.00')
        ->call('clearFilters')
        ->set('status', 'draft')
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

test('creating a report persists the narrative sections and period window', function () {
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
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-03-31')
        ->set('value', '9500.00')
        ->set('executiveSummary', 'Summary of the quarter.')
        ->set('keyAchievements', 'Reached every district.')
        ->set('challenges', 'Late disbursement.')
        ->set('lessonsLearned', 'Plan disbursement earlier.')
        ->set('nextPeriodPlans', 'Expand to two more districts.')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = Report::query()->where('plan_indicator_id', $target->id)->sole();

    expect($report->period_start->toDateString())->toBe('2026-01-01')
        ->and($report->period_end->toDateString())->toBe('2026-03-31')
        ->and($report->executive_summary)->toBe('Summary of the quarter.')
        ->and($report->key_achievements)->toBe('Reached every district.')
        ->and($report->challenges)->toBe('Late disbursement.')
        ->and($report->lessons_learned)->toBe('Plan disbursement earlier.')
        ->and($report->next_period_plans)->toBe('Expand to two more districts.');
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

test('editing an approved report is refused', function () {
    $user = User::factory()->create();
    $report = Report::factory()->approved()->create(['value' => '100.00']);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openEditModal', $report->id)
        ->assertSet('editingReportId', null)
        ->assertSet('showReportModal', false);

    $report->refresh();

    expect((float) $report->value)->toBe(100.0)
        ->and($report->status)->toBe(ReportStatus::Approved);
});

test('editing a reviewed report is refused', function () {
    $user = User::factory()->create();
    $report = Report::factory()->reviewed()->create(['value' => '100.00']);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openEditModal', $report->id)
        ->assertSet('editingReportId', null)
        ->assertSet('showReportModal', false);

    $report->refresh();

    expect((float) $report->value)->toBe(100.0)
        ->and($report->status)->toBe(ReportStatus::Reviewed);
});

test('saving an edit to a report that was approved meanwhile is refused', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['value' => '100.00']);

    $component = Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openEditModal', $report->id)
        ->set('value', '999.00');

    $report->fill(['status' => ReportStatus::Approved])->save();

    $component->call('saveReport');

    $report->refresh();

    expect((float) $report->value)->toBe(100.0)
        ->and($report->status)->toBe(ReportStatus::Approved);
});

test('a report value that would overflow the decimal column is rejected', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('plannedDate', '2026-03-31')
        ->set('value', '10000000000')
        ->call('saveReport')
        ->assertHasErrors(['value' => 'max']);
});

test('a narrative window end before its start is rejected', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('plannedDate', '2026-03-31')
        ->set('value', '100')
        ->set('periodStart', '2026-03-01')
        ->set('periodEnd', '2026-02-01')
        ->call('saveReport')
        ->assertHasErrors(['periodEnd' => 'after_or_equal']);
});

test('narrative sections are trimmed and blank narrative sections are stored as null', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'yearly_plan',
        'source_id' => YearlyPlan::factory()->create()->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('plannedDate', '2026-03-31')
        ->set('value', '100')
        ->set('executiveSummary', '  Padded summary.  ')
        ->set('keyAchievements', '   ')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = Report::query()->where('plan_indicator_id', $target->id)->sole();

    expect($report->executive_summary)->toBe('Padded summary.')
        ->and($report->key_achievements)->toBeNull();
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
