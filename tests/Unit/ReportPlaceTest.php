<?php

declare(strict_types=1);

use App\Support\Reporting\ReportPlace;

it('equals a place with the same city, program and center', function (): void {
    expect((new ReportPlace(1, 2, 3, false))->equals(new ReportPlace(1, 2, 3, false)))->toBeTrue()
        ->and((new ReportPlace(1, 2, 3, false))->equals(new ReportPlace(1, 2, 4, false)))->toBeFalse()
        ->and((new ReportPlace(1, 2, 3, false))->equals(new ReportPlace(1, 9, 3, false)))->toBeFalse()
        ->and((new ReportPlace(1, 2, 3, false))->equals(new ReportPlace(9, 2, 3, false)))->toBeFalse();
});

it('treats two city-wide places of one city and program as equal', function (): void {
    expect((new ReportPlace(1, 2, null, true))->equals(new ReportPlace(1, 2, null, true)))->toBeTrue()
        ->and((new ReportPlace(1, 2, null, true))->equals(new ReportPlace(1, 3, null, true)))->toBeFalse();
});

it('never equals a centered place when city-wide', function (): void {
    expect((new ReportPlace(1, 2, null, true))->equals(new ReportPlace(1, 2, 3, false)))->toBeFalse()
        ->and((new ReportPlace(1, 2, 3, false))->equals(new ReportPlace(1, 2, null, true)))->toBeFalse();
});

it('equals nothing when unset, not even another unset place', function (): void {
    $unset = new ReportPlace(1, 2, null, false);

    expect($unset->isUnset())->toBeTrue()
        ->and($unset->equals(new ReportPlace(1, 2, null, false)))->toBeFalse()
        ->and($unset->equals($unset))->toBeFalse()
        ->and((new ReportPlace(1, 2, null, true))->isUnset())->toBeFalse()
        ->and((new ReportPlace(1, 2, 3, false))->isUnset())->toBeFalse();
});

it('gives a stable key per place', function (): void {
    expect((new ReportPlace(1, 2, 3, false))->key())->toBe('1:2:c3')
        ->and((new ReportPlace(1, 2, null, true))->key())->toBe('1:2:city-wide')
        ->and((new ReportPlace(1, 2, null, false))->key())->toBe('1:2:unset');
});
