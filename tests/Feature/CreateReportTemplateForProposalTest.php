<?php

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Actions\ReportTemplates\CreateReportTemplateForProposal;
use App\Models\ReportTemplate;
use App\Support\ReportTemplatePrefiller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('choosing a separate template creates two monthly reports at the same place', function () {
    $fixture = placementFixture();
    $this->actingAs(mealManager());
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $first = ReportTemplate::query()->sole();
    app(ConfirmReportTemplate::class)->handle($first, $first->lines()->pluck('id')->all());
    $prefiller->prefillForProposal($fixture['proposals'][1]);

    $second = app(CreateReportTemplateForProposal::class)->handle($fixture['proposals'][1], $first->place());
    app(ConfirmReportTemplate::class)->handle($second, $second->lines()->pluck('id')->all());

    expect(ReportTemplate::query()->atPlace($first->place())->count())->toBe(2)
        ->and($first->monthlyReports()->where('period', '2026-01-01')->count())->toBe(1)
        ->and($second->monthlyReports()->where('period', '2026-01-01')->count())->toBe(1);
    expect(fn () => app(CreateReportTemplateForProposal::class)->handle($fixture['proposals'][1], $first->place()))
        ->toThrow(ValidationException::class);
});

test('creating a separate report enforces permission and coverage', function (string $role) {
    $fixture = placementFixture();
    $prefiller = app(ReportTemplatePrefiller::class);
    $prefiller->prefillForProposal($fixture['proposals'][0]);
    $prefiller->prefillForProposal($fixture['proposals'][1]);
    $template = ReportTemplate::query()->sole();
    $this->actingAs(userWithRole($role, insertHrEmployee()));

    expect(fn () => app(CreateReportTemplateForProposal::class)->handle($fixture['proposals'][1], $template->place()))
        ->toThrow(AuthorizationException::class);
})->with(['Team Member', 'MEAL']);
