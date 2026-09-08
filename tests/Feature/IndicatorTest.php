<?php

use App\Enums\ResultType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a new indicator is measured numerically', function () {
    $indicator = DB::table('indicators')->find(insertIndicator(insertResult()));

    expect($indicator->measure)->toBe('numeric')
        ->and($indicator->name)->toBe('Number of people reached');
});

test('two indicators under one result cannot share a name', function () {
    $resultId = insertResult();
    insertIndicator($resultId);

    expect(fn () => insertIndicator($resultId))->toThrow(QueryException::class);

    $this->assertDatabaseCount('indicators', 1);
});

test('the same indicator name may be reused under a different result', function () {
    insertIndicator(insertResult(statement: 'Improved access to safe drinking water.'));
    insertIndicator(insertResult(statement: 'Improved sanitation coverage.'));

    $this->assertDatabaseCount('indicators', 2);
});

test('deleting a result deletes its indicators', function () {
    $resultId = insertResult();
    insertIndicator($resultId);

    DB::table('results')->where('id', $resultId)->delete();

    $this->assertDatabaseCount('indicators', 0);
});

test('deleting an impact deletes the indicators under its child results', function () {
    $impactId = insertResult(type: ResultType::Impact, statement: 'Reduced waterborne disease.');
    $outcomeId = insertResult($impactId);
    insertIndicator($outcomeId);

    DB::table('results')->where('id', $impactId)->delete();

    $this->assertDatabaseCount('indicators', 0);
});
