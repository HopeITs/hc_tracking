<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Insert a strategy and return its id.
 *
 * The ERD tables have no models or factories yet, so tests arrange their parent
 * rows through the query builder. Replace these with factories once the models
 * exist.
 */
function insertStrategy(): int
{
    return DB::table('strategies')->insertGetId([
        'name' => 'Country Strategy',
        'start_year' => 2021,
        'end_year' => 2025,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a program under the given strategy and manager, and return its id.
 */
function insertProgram(int $strategyId, int $managerId, string $name = 'WASH'): int
{
    return DB::table('programs')->insertGetId([
        'name' => $name,
        'strategy_id' => $strategyId,
        'manager_id' => $managerId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a proposal with only its required columns, and return its id.
 */
function insertProposal(string $name = 'Emergency WASH Response'): int
{
    return DB::table('proposals')->insertGetId([
        'name' => $name,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a city and return its id.
 *
 * `cities.name` is unique, so the default name is sequenced to keep a test that
 * needs several cities from colliding with itself.
 */
function insertCity(?string $name = null): int
{
    static $sequence = 0;

    $sequence++;

    return DB::table('cities')->insertGetId([
        'name' => $name ?? "City {$sequence}",
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a project against the given city, program and user, and return its id.
 */
function insertProject(int $cityId, int $programId, int $userId): int
{
    return DB::table('projects')->insertGetId([
        'name' => 'Water Network Rehabilitation',
        'city_id' => $cityId,
        'program_id' => $programId,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a donor and return its id.
 */
function insertDonor(string $name = 'UNICEF'): int
{
    return DB::table('donors')->insertGetId([
        'name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a funding agreement between a donor and a proposal, and return its id.
 *
 * Leaving $currency null exercises the column default rather than setting it.
 */
function insertDonorProposal(int $donorId, int $proposalId, string $budget = '500000.00', ?string $currency = null): int
{
    $agreement = [
        'donor_id' => $donorId,
        'proposal_id' => $proposalId,
        'budget' => $budget,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if ($currency !== null) {
        $agreement['currency'] = $currency;
    }

    return DB::table('donor_proposal')->insertGetId($agreement);
}
