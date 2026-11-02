<?php

use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('guests are redirected from the report directory', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('reports can be filtered by status and meal visibility', function () {
    $user = User::factory()->role('Media Team')->create();

    Report::factory()->create(['value' => '111111.00', 'status' => ReportStatus::Approved, 'is_seen_by_meal' => true]);
    Report::factory()->create(['value' => '222222.00', 'status' => ReportStatus::Draft, 'is_seen_by_meal' => false]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->assertSee('111,111')
        ->assertSee('222,222')
        ->set('status', 'approved')
        ->assertSee('111,111')
        ->assertDontSee('222,222')
        ->call('clearFilters')
        ->set('status', 'draft')
        ->assertDontSee('111,111')
        ->assertSee('222,222')
        ->call('clearFilters')
        ->set('seenByMeal', 'unseen')
        ->assertDontSee('111,111')
        ->assertSee('222,222');
});

test('a report records its author from the authenticated user', function () {
    $author = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
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
    $author = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
    ]);

    Livewire::actingAs($author)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('plannedDate', '2026-03-31')
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-03-31')
        ->set('value', '9500.00')
        ->set('keyAchievements', 'Reached every district.')
        ->set('challenges', 'Late disbursement.')
        ->set('lessonsLearned', 'Plan disbursement earlier.')
        ->set('nextPeriodPlans', 'Expand to two more districts.')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = Report::query()->where('plan_indicator_id', $target->id)->sole();

    expect($report->period_start->toDateString())->toBe('2026-01-01')
        ->and($report->period_end->toDateString())->toBe('2026-03-31')
        ->and($report->key_achievements)->toBe('Reached every district.')
        ->and($report->challenges)->toBe('Late disbursement.')
        ->and($report->lessons_learned)->toBe('Plan disbursement earlier.')
        ->and($report->next_period_plans)->toBe('Expand to two more districts.');
});

test('creating a report requires a target a planned date and a value', function () {
    $user = User::factory()->role('Media Team')->create();

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
    $author = User::factory()->role('Media Team')->create();
    $editor = User::factory()->role('Media Team')->create();
    $report = Report::factory()
        ->for(PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026])))
        ->create(['user_id' => $author->id, 'value' => '100.00', 'planned_date' => '2026-06-30']);

    // Only the author edits a draft; anyone else is refused outright.
    Livewire::actingAs($editor)
        ->test('pages::reports.index')
        ->call('openEditModal', $report->id)
        ->assertForbidden();

    Livewire::actingAs($author)
        ->test('pages::reports.index')
        ->call('openEditModal', $report->id)
        ->set('value', '200.00')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report->refresh();

    expect((float) $report->value)->toBe(200.0)->and($report->user_id)->toBe($author->id);
});

test('editing an approved report is refused', function () {
    $user = User::factory()->role('Media Team')->create();
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
    $user = User::factory()->role('Media Team')->create();
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
    $user = User::factory()->role('Media Team')->create();
    $report = Report::factory()->create(['value' => '100.00', 'user_id' => $user->id]);

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
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
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
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
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
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create(['year' => 2026])->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal')
        ->set('reportTargetId', (string) $target->id)
        ->set('plannedDate', '2026-03-31')
        ->set('value', '100')
        ->set('challenges', '  Padded challenge.  ')
        ->set('keyAchievements', '   ')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = Report::query()->where('plan_indicator_id', $target->id)->sole();

    expect($report->challenges)->toBe('Padded challenge.')
        ->and($report->key_achievements)->toBeNull();
});

test('only a draft report offers delete', function () {
    $user = User::factory()->role('Media Team')->create();
    $draft = Report::factory()->create(['user_id' => $user->id]);
    $approved = Report::factory()->approved()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->assertSeeHtml('data-test="delete-report-'.$draft->id.'"')
        ->assertDontSeeHtml('data-test="delete-report-'.$approved->id.'"');
});

test('the report directory renders with no reports', function () {
    $user = User::factory()->role('Media Team')->create();

    Livewire::actingAs($user)->test('pages::reports.index')->assertSee('No reports yet');
});

test('the targets page can open the form already tied to a target, and a draft can be opened for editing', function () {
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create();
    $draft = Report::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->withQueryParams(['create' => 1, 'target' => $target->id])
        ->test('pages::reports.index')
        ->assertSet('showReportModal', true)
        ->assertSet('reportTargetId', (string) $target->id)
        ->assertSeeHtml('data-test="target-scope-chip"');

    Livewire::actingAs($user)
        ->withQueryParams(['edit' => $draft->id])
        ->test('pages::reports.index')
        ->assertSet('editingReportId', $draft->id);
});

test('two targets on one indicator can be told apart in the form, and a target whose source is gone is not offered', function () {
    $user = User::factory()->role('Media Team')->create();
    $indicator = Indicator::factory()->create(['name' => 'Latrines built']);
    $plan = PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026]))->create(['indicator_id' => $indicator->id]);
    $proposal = PlanIndicator::factory()->forProposal(Proposal::factory()->create(['name' => 'WASH Response']))->create(['indicator_id' => $indicator->id]);
    $orphanPlan = AnnualPlan::factory()->create();
    $orphan = PlanIndicator::factory()->forAnnualPlan($orphanPlan)->create();
    DB::table('annual_plans')->where('id', $orphanPlan->id)->delete();

    $options = collect(Livewire::actingAs($user)->test('pages::reports.index')->instance()->targetOptions())->keyBy('id');

    expect($options[$plan->id]['label'])->toContain('Latrines built')->toContain('Annual plan 2026')
        ->and($options[$proposal->id]['label'])->toContain('Latrines built')->toContain('WASH Response')
        ->and($options->has($orphan->id))->toBeFalse();
});

test('a figure must fall inside the plan year or proposal period it was committed for', function () {
    $user = User::factory()->role('Media Team')->create();
    $planTarget = PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026]))->create();
    $proposalTarget = PlanIndicator::factory()
        ->forProposal(Proposal::factory()->create(['planned_date_begin' => '2025-01-01', 'planned_date_end' => '2025-12-31']))
        ->create();

    foreach ([[$planTarget, '2025-12-31'], [$proposalTarget, '2026-02-01']] as [$target, $outside]) {
        Livewire::actingAs($user)
            ->test('pages::reports.index')
            ->call('openCreateModal', $target->id)
            ->set('plannedDate', $outside)
            ->set('value', '10')
            ->call('saveReport')
            ->assertHasErrors(['plannedDate']);
    }

    expect(Report::query()->exists())->toBeFalse();
});

test('a percentage report cannot exceed 100', function () {
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()
        ->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026]))
        ->for(Indicator::factory()->state(['measure' => IndicatorMeasure::Percentage]))
        ->create(['target' => 80]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal', $target->id)
        ->set('plannedDate', '2026-06-30')
        ->set('value', '120')
        ->call('saveReport')
        ->assertHasErrors(['value' => 'max']);
});

test('the form never writes a submit date: only submitting for review does', function () {
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026]))->create();

    $component = Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal', $target->id)
        ->set('plannedDate', '2026-06-30')
        ->set('value', '10')
        ->call('saveReport')
        ->assertHasNoErrors();

    expect(property_exists($component->instance(), 'submitDate'))->toBeFalse()
        ->and(DB::table('reports')->sole()->submit_date)->toBeNull();
});

test('a draft report can be deleted with its files, and an approved one cannot', function () {
    Storage::fake('local');
    $user = User::factory()->role('Media Team')->create();
    $draft = Report::factory()->create(['user_id' => $user->id]);
    Storage::disk('local')->put('report-files/'.$draft->id.'/evidence.pdf', 'pdf');
    ReportFile::factory()->for($draft)->create(['path' => 'report-files/'.$draft->id.'/evidence.pdf', 'original_name' => 'evidence.pdf']);
    $approved = Report::factory()->approved()->create();

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('confirmDeleteReport', $draft->id)
        ->assertSet('showDeleteReportModal', true)
        ->call('deleteReport')
        ->call('confirmDeleteReport', $approved->id)
        ->call('deleteReport');

    expect(DB::table('reports')->find($draft->id))->toBeNull()
        ->and(DB::table('report_files')->where('report_id', $draft->id)->exists())->toBeFalse()
        ->and(DB::table('reports')->find($approved->id))->not->toBeNull();
    Storage::disk('local')->assertMissing('report-files/'.$draft->id.'/evidence.pdf');
});

test('a row shows its target achievement from approved reports only', function () {
    $user = User::factory()->role('Media Team')->create();
    $target = PlanIndicator::factory()->create(['target' => 400]);
    Report::factory()->approved()->for($target)->create(['value' => 100]);
    Report::factory()->for($target)->create(['value' => 300]);

    $component = Livewire::actingAs($user)->test('pages::reports.index');

    $component->assertSee('target 25% achieved')->assertDontSee('target 75% achieved');

    expect($component->instance()->insights()['avgAchievement'])->toBe(25);
});

test('reports can be narrowed to overdue drafts, a project and a single target', function () {
    $user = User::factory()->role('Media Team')->create();
    $project = Project::factory()->create();
    $projectTarget = PlanIndicator::factory()->create(['project_id' => $project->id]);
    $late = Report::factory()->for($projectTarget)->create(['planned_date' => now()->subMonth()->toDateString(), 'value' => 111]);
    $onTime = Report::factory()->create(['planned_date' => now()->addMonth()->toDateString(), 'value' => 222]);

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('toggleOverdue')
        ->assertSee('111')
        ->assertDontSee(route('reports.show', $onTime))
        ->call('clearFilters')
        ->set('project', (string) $project->id)
        ->assertSee(route('reports.show', $late))
        ->assertDontSee(route('reports.show', $onTime))
        ->call('clearFilters')
        ->set('target', (string) $onTime->plan_indicator_id)
        ->assertSee(route('reports.show', $onTime))
        ->assertDontSee(route('reports.show', $late));
});

test('the report form is rendered only while its modal is open', function () {
    $user = User::factory()->role('Media Team')->create();

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->assertDontSeeHtml('wire:model="keyAchievements"')
        ->call('openCreateModal')
        ->assertSeeHtml('wire:model="keyAchievements"');
});

test('a monthly report mirror row links to its monthly report and offers no edit or delete', function () {
    $user = User::factory()->role('Media Team')->create();
    $mirror = Activity::withoutRecording(fn (): Report => Report::factory()->create([
        'monthly_report_id' => MonthlyReport::factory()->create()->id,
        'user_id' => $user->id,
    ]));

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->assertSeeText(__('reports.monthly_report_chip'))
        ->assertSeeHtml(route('monthly-reports.show', $mirror->monthlyReport))
        ->assertDontSeeHtml('wire:click="openEditModal('.$mirror->id.')"')
        ->assertDontSeeHtml('data-test="delete-report-'.$mirror->id.'"');
});

/**
 * A 2026 annual-plan target whose library entry takes numerator and
 * denominator (contracts §11.1).
 */
function ratioTarget(IndicatorMeasure $measure = IndicatorMeasure::Percentage): PlanIndicator
{
    $library = LibraryIndicator::factory()->create(['measure' => $measure, 'numerator_label' => 'Passed the test', 'denominator_label' => 'Took the test']);

    return PlanIndicator::factory()
        ->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026]))
        ->for(Indicator::factory()->state(['measure' => $measure, 'library_indicator_id' => $library->id]))
        ->create(['target' => 80]);
}

test('a ratio indicator takes numerator and denominator, and the server computes the value', function () {
    $user = User::factory()->role('Media Team')->create();
    $target = ratioTarget();

    Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal', $target->id)
        ->assertSeeText('Passed the test')
        ->assertSeeText('Took the test')
        ->assertDontSeeHtml('wire:model="value"')
        ->set('plannedDate', '2026-06-30')
        ->set('numerator', '30')
        ->set('denominator', '40')
        ->set('value', '99')
        ->call('saveReport')
        ->assertHasNoErrors();

    $report = DB::table('reports')->sole();

    expect((float) $report->value)->toBe(75.0)
        ->and((float) $report->numerator)->toBe(30.0)
        ->and((float) $report->denominator)->toBe(40.0);
});

test('an average indicator stores the plain ratio', function () {
    $target = ratioTarget(IndicatorMeasure::Average);

    Livewire::actingAs(User::factory()->role('Media Team')->create())
        ->test('pages::reports.index')
        ->call('openCreateModal', $target->id)
        ->set('plannedDate', '2026-06-30')
        ->set('numerator', '45')
        ->set('denominator', '10')
        ->call('saveReport')
        ->assertHasNoErrors();

    expect((float) DB::table('reports')->sole()->value)->toBe(4.5);
});

test('a ratio report rejects a zero denominator and a numerator above the denominator', function () {
    $user = User::factory()->role('Media Team')->create();
    $target = ratioTarget();
    $form = fn (string $numerator, string $denominator) => Livewire::actingAs($user)
        ->test('pages::reports.index')
        ->call('openCreateModal', $target->id)
        ->set('plannedDate', '2026-06-30')
        ->set('numerator', $numerator)
        ->set('denominator', $denominator)
        ->call('saveReport');

    $form('5', '0')->assertHasErrors(['denominator' => __('monthly_reports.zero_denominator')]);
    $form('50', '40')->assertHasErrors(['numerator' => __('monthly_reports.numerator_above_denominator')]);
    $form('', '40')->assertHasErrors(['numerator' => 'required']);

    expect(Report::query()->exists())->toBeFalse();
});
