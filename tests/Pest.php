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
function insertDonorProposal(int $donorId, int $proposalId, string $budget = '500000.00', ?string $currency = null, ?string $projectNum = null): int
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

    if ($projectNum !== null) {
        $agreement['project_num'] = $projectNum;
    }

    return DB::table('donor_proposal')->insertGetId($agreement);
}

/**
 * Insert a milestone, optionally nested under a parent milestone.
 */
function insertMilestone(int $projectId, int $responsibleUserId, ?int $parentId = null, string $name = 'Baseline assessment'): int
{
    return DB::table('milestones')->insertGetId([
        'project_id' => $projectId,
        'name' => $name,
        'planned_date' => '2026-03-31',
        'responsible_user_id' => $responsibleUserId,
        'parent_milestone_id' => $parentId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a task under a proposal, optionally linked to a milestone.
 */
function insertTask(int $proposalId, int $assignedTo, int $createdBy, ?int $milestoneId = null, string $title = 'Draft the distribution plan'): int
{
    return DB::table('tasks')->insertGetId([
        'proposal_id' => $proposalId,
        'milestone_id' => $milestoneId,
        'title' => $title,
        'assigned_to' => $assignedTo,
        'created_by' => $createdBy,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a logframe result, optionally nested under a parent result and
 * optionally attached to a program.
 */
function insertResult(?int $parentId = null, ?int $programId = null, string $type = 'outcome', string $statement = 'Improved access to safe drinking water.'): int
{
    return DB::table('results')->insertGetId([
        'statement' => $statement,
        'parent_id' => $parentId,
        'program_id' => $programId,
        'type' => $type,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert an indicator under the given result, and return its id.
 */
function insertIndicator(int $resultId, string $name = 'Number of people reached'): int
{
    return DB::table('indicators')->insertGetId([
        'name' => $name,
        'result_id' => $resultId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a planned target for an indicator, and return its id.
 *
 * `source_type`/`source_id` are polymorphic and carry no foreign key, so the
 * caller decides what the target is planned against.
 */
function insertPlanIndicator(int $indicatorId, string $sourceType, int $sourceId, ?int $projectId = null, string $target = '12000.00'): int
{
    return DB::table('plan_indicators')->insertGetId([
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'indicator_id' => $indicatorId,
        'target' => $target,
        'project_id' => $projectId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert a reported actual against a planned target, and return its id.
 */
function insertReport(int $planIndicatorId, int $userId, string $value = '9500.00'): int
{
    return DB::table('reports')->insertGetId([
        'plan_indicator_id' => $planIndicatorId,
        'user_id' => $userId,
        'planned_date' => '2026-03-31',
        'value' => $value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Insert an organisational bank account, and return its id.
 */
function insertAccount(string $name = 'Operations USD'): int
{
    return DB::table('accounts')->insertGetId([
        'name' => $name,
        'opened_at' => '2024-03-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
