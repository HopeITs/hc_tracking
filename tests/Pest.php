<?php

use App\Enums\Currency;
use App\Enums\ResultType;
use App\Models\Account;
use App\Models\City;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Strategy;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * These helpers hand back ids rather than models on purpose: the tests around
 * them assert database-level behaviour (column defaults, foreign key actions,
 * unique indexes) and read their rows back through the query builder, so an
 * Eloquent cast must not sit between the assertion and the stored value.
 */
function insertStrategy(): int
{
    return Strategy::factory()->create([
        'name' => 'Country Strategy',
        'start_year' => 2021,
        'end_year' => 2025,
    ])->id;
}

/**
 * Insert a program under the given strategy and manager, and return its id.
 */
function insertProgram(int $strategyId, int $managerId, string $name = 'WASH'): int
{
    return Program::factory()->create([
        'name' => $name,
        'strategy_id' => $strategyId,
        'manager_id' => $managerId,
    ])->id;
}

/**
 * Insert a proposal with only its required columns, and return its id.
 *
 * `status` and the actual dates are left out so the column defaults decide
 * them.
 */
function insertProposal(string $name = 'Emergency WASH Response'): int
{
    return Proposal::factory()->create([
        'name' => $name,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
    ])->id;
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

    return City::factory()->create([
        'name' => $name ?? "City {$sequence}",
    ])->id;
}

/**
 * Insert a project against the given city, program and user, and return its id.
 */
function insertProject(int $cityId, int $programId, int $userId): int
{
    return Project::factory()->create([
        'name' => 'Water Network Rehabilitation',
        'city_id' => $cityId,
        'program_id' => $programId,
        'user_id' => $userId,
    ])->id;
}

/**
 * Insert a donor and return its id.
 */
function insertDonor(string $name = 'UNICEF'): int
{
    return Donor::factory()->create(['name' => $name])->id;
}

/**
 * Insert a funding agreement between a donor and a proposal, and return its id.
 *
 * Leaving $currency null exercises the column default rather than setting it.
 */
function insertDonorProposal(int $donorId, int $proposalId, string $budget = '500000.00', ?Currency $currency = null, ?string $projectNum = null): int
{
    $agreement = [
        'donor_id' => $donorId,
        'proposal_id' => $proposalId,
        'budget' => $budget,
    ];

    if ($currency !== null) {
        $agreement['currency'] = $currency;
    }

    if ($projectNum !== null) {
        $agreement['project_num'] = $projectNum;
    }

    return DonorProposal::factory()->create($agreement)->id;
}

/**
 * Insert a milestone, optionally nested under a parent milestone.
 */
function insertMilestone(int $projectId, int $responsibleUserId, ?int $parentId = null, string $name = 'Baseline assessment'): int
{
    return Milestone::factory()->create([
        'project_id' => $projectId,
        'name' => $name,
        'description' => null,
        'planned_date' => '2026-03-31',
        'responsible_user_id' => $responsibleUserId,
        'parent_milestone_id' => $parentId,
    ])->id;
}

/**
 * Insert a task under a proposal, optionally linked to a milestone.
 */
function insertTask(int $proposalId, int $assignedTo, int $createdBy, ?int $milestoneId = null, string $title = 'Draft the distribution plan'): int
{
    return Task::factory()->create([
        'proposal_id' => $proposalId,
        'milestone_id' => $milestoneId,
        'title' => $title,
        'description' => null,
        'assigned_to' => $assignedTo,
        'created_by' => $createdBy,
    ])->id;
}

/**
 * Insert a logframe result, optionally nested under a parent result and
 * optionally attached to a program.
 */
function insertResult(?int $parentId = null, ?int $programId = null, ResultType $type = ResultType::Outcome, string $statement = 'Improved access to safe drinking water.'): int
{
    return Result::factory()->create([
        'statement' => $statement,
        'parent_id' => $parentId,
        'program_id' => $programId,
        'type' => $type,
    ])->id;
}

/**
 * Insert an indicator under the given result, and return its id.
 */
function insertIndicator(int $resultId, string $name = 'Number of people reached'): int
{
    return Indicator::factory()->create([
        'name' => $name,
        'result_id' => $resultId,
    ])->id;
}

/**
 * Insert a planned target for an indicator, and return its id.
 *
 * `source_type`/`source_id` are polymorphic and carry no foreign key, so the
 * caller decides what the target is planned against.
 */
function insertPlanIndicator(int $indicatorId, string $sourceType, int $sourceId, ?int $projectId = null, string $target = '12000.00'): int
{
    return PlanIndicator::factory()->create([
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'indicator_id' => $indicatorId,
        'target' => $target,
        'project_id' => $projectId,
    ])->id;
}

/**
 * Insert a reported actual against a planned target, and return its id.
 */
function insertReport(int $planIndicatorId, int $userId, string $value = '9500.00'): int
{
    return Report::factory()->create([
        'plan_indicator_id' => $planIndicatorId,
        'user_id' => $userId,
        'planned_date' => '2026-03-31',
        'value' => $value,
        'narrative_report_notes' => null,
    ])->id;
}

/**
 * Insert an organisational bank account, and return its id.
 */
function insertAccount(string $name = 'Operations USD'): int
{
    return Account::factory()->create([
        'name' => $name,
        'opened_at' => '2024-03-15',
    ])->id;
}
