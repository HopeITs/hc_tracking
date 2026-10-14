<?php

use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Report;
use App\Models\Risk;
use App\Models\Strategy;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;

test('every subject type leads to its page', function (string $type, Closure $record, Closure $expected) {
    $model = Activity::withoutRecording($record);

    expect(app(SubjectLinks::class)->routeFor($type, $model->getKey()))->toBe($expected($model));
})->with([
    'report' => ['report', fn () => Report::factory()->create(), fn ($report) => route('reports.show', $report)],
    'donor' => ['donor', fn () => Donor::factory()->create(), fn ($donor) => route('donors.show', $donor)],
    'strategy' => ['strategy', fn () => Strategy::factory()->create(), fn ($strategy) => route('strategies.show', $strategy)],
    'funding agreement' => ['donor_proposal', fn () => DonorProposal::factory()->create(), fn ($agreement) => route('funding-agreements.show', $agreement)],
]);

test('every directory-only subject type leads to its directory', function (string $type, Closure $expected) {
    expect(app(SubjectLinks::class)->routeFor($type, 5))->toBe($expected());
})->with([
    'risk' => ['risk', fn () => route('risks.index')],
    'indicator' => ['indicator', fn () => route('indicators.index', ['inspect' => 5])],
    'library indicator' => ['library_indicator', fn () => route('indicator-library.index', ['entry' => 5])],
    'target' => ['plan_indicator', fn () => route('targets.index')],
    'result' => ['result', fn () => route('results.index')],
    'transfer' => ['transfer', fn () => route('transfers.index')],
    'account' => ['account', fn () => route('admin.accounts.index')],
    'supervision' => ['supervision', fn () => route('admin.users.index', ['tab' => 'supervision'])],
]);

test('routeFor returns null for a show-route subject that no longer exists', function () {
    expect(app(SubjectLinks::class)->routeFor('report', 999999))->toBeNull();
});

test('a donor report leads to its funding agreement', function () {
    $donorReport = Activity::withoutRecording(fn () => DonorReport::factory()->create());

    expect(app(SubjectLinks::class)->routeFor('donor_report', $donorReport->id))
        ->toBe(route('funding-agreements.show', $donorReport->donorProposal));
});

test('existing() keeps only records that are still there', function () {
    $risk = Activity::withoutRecording(fn () => Risk::factory()->create());
    $donor = Activity::withoutRecording(fn () => Donor::factory()->create());

    expect(app(SubjectLinks::class)->existing(['risk' => [$risk->id, 999999], 'donor' => [$donor->id]]))
        ->toBe(['risk' => [$risk->id], 'donor' => [$donor->id]]);
});

test('a list of names renders in the reader language', function () {
    $names = ['localized_list' => [['en' => 'Nour', 'ar' => 'نور'], ['en' => 'Rami', 'ar' => 'رامي']]];

    expect(app(NotificationPresenter::class)->param($names, 'en'))->toBe('Nour, Rami')
        ->and(app(NotificationPresenter::class)->param($names, 'ar'))->toBe('نور، رامي');
});
