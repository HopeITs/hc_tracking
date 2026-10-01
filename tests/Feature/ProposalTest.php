<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a new proposal starts as a concept note', function () {
    $id = insertProposal();

    $proposal = DB::table('proposals')->find($id);

    expect($proposal->status)->toBe('concept note')
        ->and($proposal->actual_date_begin)->toBeNull()
        ->and($proposal->actual_date_end)->toBeNull()
        ->and($proposal->notes)->toBeNull();
});

/**
 * Proposal carries a $attributes mirror of this default so an unrefreshed
 * model can report a phase, which means the factory no longer proves the
 * column default. This raw insert does.
 */
test('the status column defaults to concept note without the model supplying it', function () {
    $id = DB::table('proposals')->insertGetId([
        'name' => 'Emergency WASH Response',
        'internal_project_number' => 'HC-2026-001',
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('proposals')->find($id)->status)->toBe('concept note');
});

test('a proposal that has run records its actual dates and notes', function () {
    $id = DB::table('proposals')->insertGetId([
        'name' => 'Emergency WASH Response',
        'internal_project_number' => 'HC-2026-001',
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'actual_date_begin' => '2026-02-15',
        'actual_date_end' => '2027-01-20',
        'status' => 'approved',
        'notes' => 'Start delayed six weeks pending donor countersignature.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $proposal = DB::table('proposals')->find($id);

    expect($proposal->actual_date_begin)->toBe('2026-02-15')
        ->and($proposal->actual_date_end)->toBe('2027-01-20')
        ->and($proposal->status)->toBe('approved')
        ->and($proposal->notes)->toBe('Start delayed six weeks pending donor countersignature.');
});

test('a proposal cannot be stored without its planned dates', function () {
    DB::table('proposals')->insert([
        'name' => 'Emergency WASH Response',
        'internal_project_number' => 'HC-2026-001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('a proposal cannot be stored without an internal project number', function () {
    DB::table('proposals')->insert([
        'name' => 'Emergency WASH Response',
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

/**
 * The column is utf8mb4_unicode_ci, so the unique index ignores case: a
 * number differing only in case is the same number.
 */
test('two proposals cannot share an internal project number, whatever its case', function (string $second) {
    $proposal = fn (string $number): array => [
        'name' => 'Emergency WASH Response',
        'internal_project_number' => $number,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('proposals')->insert($proposal('HC-2026-001'));
    DB::table('proposals')->insert($proposal($second));
})->with(['HC-2026-001', 'hc-2026-001'])->throws(UniqueConstraintViolationException::class);
