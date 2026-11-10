<?php

/**
 * One word per idea across the Funding pages, in both languages.
 *
 * The same figure is printed on the transfers register and in the funding
 * agreements ledger; before this, one called it "Landed (net)" and the other
 * "Received (net)". These assertions pin the shared wording so the two files
 * cannot drift apart again.
 */

/**
 * A translation line in one locale, without switching the app's locale.
 */
function line(string $locale, string $key): string
{
    return (string) trans($key, [], $locale);
}

test('the net figure is called the same thing on every funding page, in both languages', function (string $locale) {
    // funding-agreements' ledger label and transfers' register label.
    expect(line($locale, 'funding_agreements.landed_net'))
        ->toBe(line($locale, 'finance_tables.received_net'));

    // The gross figure likewise: the agreement page and the tranche tables.
    expect(line($locale, 'funding_agreements.received_gross_from_donors'))
        ->toBe(line($locale, 'finance_tables.gross_received'));

    // The part of a budget no tranche covers: the register column and the
    // ledger segment name it identically.
    expect(line($locale, 'funding_agreements.not_scheduled_yet'))
        ->toBe(line($locale, 'finance_tables.not_scheduled'));
})->with(['en', 'ar']);

test('the three headline states read as three short, distinct words in both languages', function (string $locale) {
    $states = collect(['attention', 'moving', 'not_started'])
        ->map(fn (string $state): string => line($locale, 'funding_agreements.health_'.$state));

    expect($states->unique())->toHaveCount(3)
        ->and($states->every(fn (string $word): bool => $word !== ''))->toBeTrue();

    // A status word sits in a table cell, so it has to stay short.
    foreach ($states as $word) {
        expect(mb_strlen($word))->toBeLessThanOrEqual(20);
    }

    // "Not started" (no tranche at all) must not read the same as the stage
    // filter for "some of the budget is unscheduled" — they select different
    // agreements, so they cannot share a word.
    expect(line($locale, 'funding_agreements.health_not_started'))
        ->not->toBe(line($locale, 'funding_agreements.stage_unscheduled'));
})->with(['en', 'ar']);

test('no funding string still says "yet" where its twin does not', function (string $locale) {
    expect(line($locale, 'funding_agreements.no_tranches_scheduled_yet'))
        ->toBe(line($locale, 'funding_agreements.no_tranches_scheduled'));
})->with(['en', 'ar']);
