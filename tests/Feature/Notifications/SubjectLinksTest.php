<?php

use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\Risk;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;

test('every subject type leads to its page', function (string $type, Closure $expected) {
    expect(app(SubjectLinks::class)->routeFor($type, 5))->toBe($expected());
})->with([
    'report' => ['report', fn () => route('reports.show', 5)],
    'donor' => ['donor', fn () => route('donors.show', 5)],
    'strategy' => ['strategy', fn () => route('strategies.show', 5)],
    'funding agreement' => ['donor_proposal', fn () => route('funding-agreements.show', 5)],
    'risk' => ['risk', fn () => route('risks.index')],
    'indicator' => ['indicator', fn () => route('indicators.index', ['inspect' => 5])],
    'target' => ['plan_indicator', fn () => route('targets.index')],
    'result' => ['result', fn () => route('results.index')],
    'transfer' => ['transfer', fn () => route('transfers.index')],
    'account' => ['account', fn () => route('admin.accounts.index')],
]);

test('a donor report leads to its funding agreement', function () {
    $donorReport = Activity::withoutRecording(fn () => DonorReport::factory()->create());

    expect(app(SubjectLinks::class)->routeFor('donor_report', $donorReport->id))
        ->toBe(route('funding-agreements.show', $donorReport->donor_proposal_id));
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
