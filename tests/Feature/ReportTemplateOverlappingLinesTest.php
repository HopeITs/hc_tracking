<?php

use App\Actions\MonthlyReports\RefreshMonthlyReportLines;
use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Actions\ReportTemplates\JoinReportTemplate;
use App\Actions\ReportTemplates\RetireReportTemplateLine;
use App\Enums\MonthlyReportStatus;
use App\Models\LibraryIndicator;
use App\Models\ReportTemplate;
use App\Support\MonthlyReportLineBuilder;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Auth\Access\AuthorizationException;

test('a shared line survives the end of one proposal and only its expired shares stop', function () {
    $fixture = placementFixture();
    $fixture['proposals'][0]->forceFill(['actual_date_end' => '2026-06-30'])->save();
    $exclusive = donorReportTarget($fixture['proposals'][0], $fixture['project'], LibraryIndicator::factory()->create(['frequency' => 'monthly', 'collected_by' => 'project_team', 'measure' => 'numeric', 'denominator_basis' => null]));
    $this->actingAs(mealManager());
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $template = ReportTemplate::query()->sole();
    app(JoinReportTemplate::class)->handle($fixture['proposals'][1], $template, []);
    app(ConfirmReportTemplate::class)->handle($template, $template->lines()->pluck('id')->all());
    $builder = app(MonthlyReportLineBuilder::class);
    $june = $template->monthlyReports()->where('period', '2026-06-01')->sole();
    $july = $template->monthlyReports()->where('period', '2026-07-01')->sole();
    $builder->build($june);
    $builder->build($july);

    expect($june->lines()->where('library_indicator_id', $fixture['library']->id)->sole()->shares()->count())->toBe(2)
        ->and($june->lines()->count())->toBe(2)
        ->and($july->lines()->count())->toBe(1)
        ->and($july->lines()->sole()->shares()->pluck('plan_indicator_id')->all())->toBe([$fixture['targets'][1]->id]);
    $this->travelTo('2026-11-19');
    $line = $template->lines()->where('plan_indicator_id', $exclusive->id)->sole();
    app(RetireReportTemplateLine::class)->handle($template, $line->id);
    expect($line->fresh()->effective_to->toDateString())->toBe('2026-06-01');
});

test('joining and confirming preserve submitted and validated report snapshots', function (MonthlyReportStatus $status) {
    $fixture = placementFixture();
    $this->actingAs(mealManager());
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $template = ReportTemplate::query()->sole();
    app(ConfirmReportTemplate::class)->handle($template, $template->lines()->pluck('id')->all());
    $report = $template->monthlyReports()->where('period', '2026-01-01')->sole();
    $builder = app(MonthlyReportLineBuilder::class);
    $builder->build($report);
    $report->forceFill(['status' => $status])->save();
    $before = $report->lines()->with('shares')->get()->toArray();
    $prefiller->prefillForProposal($fixture['proposals'][1]);

    app(JoinReportTemplate::class)->handle($fixture['proposals'][1], $template, []);
    app(ConfirmReportTemplate::class)->handle($template, $template->lines()->where('status', 'pending')->pluck('id')->all());
    $builder->build($report);
    expect(fn () => app(RefreshMonthlyReportLines::class)->handle($report, $status))
        ->toThrow(AuthorizationException::class);

    expect($report->lines()->with('shares')->get()->toArray())->toBe($before)
        ->and($report->fresh()->status)->toBe($status);
})->with([MonthlyReportStatus::Submitted, MonthlyReportStatus::Validated]);
