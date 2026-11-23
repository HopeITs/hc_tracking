<?php

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Enums\TemplatePlacementStatus;
use App\Models\ReportTemplate;
use App\Models\ReportTemplatePlacement;
use App\Models\UserNotification;
use App\Support\ReportTemplatePrefiller;

test('a free place creates its first template and a decided placement once', function () {
    $fixture = placementFixture();
    $prefiller = app(ReportTemplatePrefiller::class);
    $proposal = $fixture['proposals'][0];

    $prefiller->prefillForProposal($proposal);
    $prefiller->prefillForProposal($proposal);

    expect(ReportTemplate::query()->count())->toBe(1)
        ->and(ReportTemplatePlacement::query()->sole()->status)->toBe(TemplatePlacementStatus::Created);
    $this->actingAs(mealManager());
    $template = ReportTemplate::query()->sole();
    app(ConfirmReportTemplate::class)->handle($template, $template->lines()->pluck('id')->all());
    expect($template->monthlyReports()->count())->toBe(12);

});

test('an occupied place waits for a MEAL decision and retries create nothing', function () {
    $fixture = placementFixture();
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);

    expect(ReportTemplate::query()->count())->toBe(1)
        ->and(ReportTemplatePlacement::query()->where('proposal_id', $fixture['proposals'][1]->id)->sole()->status)->toBe(TemplatePlacementStatus::Pending);
});

test('MEAL is notified of a pending choice once and the notification opens its queue', function () {
    $manager = mealManager();
    $fixture = placementFixture();
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);

    $notifications = UserNotification::query()->forUser($manager)->get()
        ->filter(fn ($row): bool => $row->data['key'] === 'notifications.events.report_template.placement_pending');
    expect($notifications)->toHaveCount(1);
});
