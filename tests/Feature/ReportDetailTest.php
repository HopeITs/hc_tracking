<?php

use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        ->assertSeeText('9,500')
        ->assertSeeText('Layla Haddad')
        ->assertSeeText($report->planIndicator->indicator->name);
});

test('report detail renders the five narrative sections and the narrative window', function () {
    $user = User::factory()->create();
    $report = Report::factory()->withNarrative()->create([
        'executive_summary' => 'Summary text goes here.',
        'key_achievements' => 'Achievements text goes here.',
        'challenges' => 'Challenges text goes here.',
        'lessons_learned' => 'Lessons text goes here.',
        'next_period_plans' => 'Plans text goes here.',
    ]);

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertSeeText('Summary text goes here.')
        ->assertSeeText('Achievements text goes here.')
        ->assertSeeText('Challenges text goes here.')
        ->assertSeeText('Lessons text goes here.')
        ->assertSeeText('Plans text goes here.')
        ->assertSeeText($report->period_start->format('M j, Y'))
        ->assertSeeText($report->period_end->format('M j, Y'));
});

test('report detail renders without narrative sections when they are null', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk();
});

test('submitting for review moves a draft report to reviewed and stamps the submit date', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft, 'submit_date' => null]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('submitForReview')
        ->assertHasNoErrors();

    $report->refresh();

    expect($report->status)->toBe(ReportStatus::Reviewed)
        ->and($report->submit_date)->not->toBeNull();
});

test('submitting for review is a no-op when the report is not a draft', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Reviewed, 'submit_date' => '2026-03-01']);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('submitForReview');

    expect($report->refresh()->status)->toBe(ReportStatus::Reviewed);
});

test('approving a reviewed report moves it to approved', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Reviewed]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('approve')
        ->assertHasNoErrors();

    expect($report->refresh()->status)->toBe(ReportStatus::Approved);
});

test('approving is a no-op when the report is not reviewed', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('approve');

    expect($report->refresh()->status)->toBe(ReportStatus::Draft);
});

test('returning to draft is valid from reviewed or approved', function () {
    $user = User::factory()->create();

    $reviewed = Report::factory()->create(['status' => ReportStatus::Reviewed]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $reviewed])
        ->call('returnToDraft')
        ->assertHasNoErrors();

    expect($reviewed->refresh()->status)->toBe(ReportStatus::Draft);

    $approved = Report::factory()->create(['status' => ReportStatus::Approved]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $approved])
        ->call('returnToDraft')
        ->assertHasNoErrors();

    expect($approved->refresh()->status)->toBe(ReportStatus::Draft);
});

test('returning to draft is a no-op when the report is already a draft', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('returnToDraft');

    expect($report->refresh()->status)->toBe(ReportStatus::Draft);
});

test('marking a report seen by meal is a one way acknowledgement', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create(['is_seen_by_meal' => false]);

    $component = Livewire::actingAs($user)->test('pages::reports.show', ['report' => $report]);

    $component->call('markSeenByMeal');

    expect($report->refresh()->is_seen_by_meal)->toBeTrue()
        ->and(method_exists($component->instance(), 'markUnseenByMeal'))->toBeFalse();
});

test('report detail renders a report with no files', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertSeeText('No files recorded');
});

test('file records cannot be added, edited or deleted on a locked report', function () {
    $user = User::factory()->create();
    $report = Report::factory()->approved()->create();
    $file = ReportFile::factory()->create(['report_id' => $report->id, 'path' => 'untouched.pdf']);

    $component = Livewire::actingAs($user)->test('pages::reports.show', ['report' => $report]);

    $component->call('openCreateFileModal')->assertSet('showFileModal', false);
    $component->call('openEditFileModal', $file->id)->assertSet('showFileModal', false);
    $component->call('confirmDeleteFile', $file->id)->assertSet('showDeleteFileModal', false);

    $this->assertDatabaseHas('report_files', ['id' => $file->id, 'path' => 'untouched.pdf']);
});

test('the add file action is hidden once a report is locked', function () {
    $user = User::factory()->create();
    $report = Report::factory()->approved()->create();

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertDontSee('wire:click="openCreateFileModal"', false);
});

test('returning a report to draft clears its submit date', function () {
    $user = User::factory()->create();
    $report = Report::factory()->reviewed()->create(['submit_date' => '2026-04-02']);

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('returnToDraft');

    $row = DB::table('reports')->find($report->id);

    expect($row->status)->toBe(ReportStatus::Draft->value)
        ->and($row->submit_date)->toBeNull();
});

test('a draft can be edited from its own page, and deleting it returns to the directory', function () {
    $user = User::factory()->create();
    $report = Report::factory()
        ->for(PlanIndicator::factory()->forAnnualPlan(AnnualPlan::factory()->create(['year' => 2026])))
        ->create(['value' => 100, 'planned_date' => '2026-06-30']);

    $component = Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->assertSeeHtml('data-test="edit-report-button"')
        ->call('openEditModal', $report->id)
        ->set('value', '150')
        ->call('saveReport')
        ->assertHasNoErrors()
        ->assertSee('150');

    expect((float) DB::table('reports')->find($report->id)->value)->toBe(150.0);

    $component->call('confirmDeleteReport', $report->id)
        ->call('deleteReport')
        ->assertRedirect(route('reports.index'));

    expect(DB::table('reports')->find($report->id))->toBeNull();
});

test('a locked report offers neither edit nor delete', function () {
    $user = User::factory()->create();
    $report = Report::factory()->approved()->create();

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->assertDontSeeHtml('data-test="edit-report-button"')
        ->assertDontSeeHtml('data-test="delete-report-button"');
});

test('the detail page rates the whole target from approved reports, not this one figure', function () {
    $user = User::factory()->create();
    $target = PlanIndicator::factory()->create(['target' => 1000]);
    Report::factory()->approved()->for($target)->create(['value' => 300]);
    $draft = Report::factory()->for($target)->create(['value' => 900]);

    $component = Livewire::actingAs($user)->test('pages::reports.show', ['report' => $draft]);

    expect($component->instance()->achievement())->toBe(30);
    $component->assertSee(__('reports.counts_toward_the_target_once_approved'));
});

test('the detail page links back along the chain to the indicator and its targets', function () {
    $user = User::factory()->create();
    $report = Report::factory()->create();
    $indicatorId = $report->planIndicator->indicator_id;

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertOk()
        ->assertSee(route('indicators.index', ['inspect' => $indicatorId]), false)
        ->assertSee(route('targets.index', ['indicator' => $indicatorId]), false);
});
