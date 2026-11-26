<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\Alerts\WeeklyDigestScanner;
use App\Support\StrategyRollup;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Y-13 (wave4/AGENT.md §8.8): the two figures that count risks — the weekly
 * digest's "open risks" per program and the strategies register's high risks —
 * must not move when the risks leave the project for its location row.
 *
 * The expected number is computed here the way the code counted it before
 * Y-13, through `risks.project_id` and `projects.program_id`, so the
 * assertion is against the old definition rather than against the new query
 * repeated twice. The dataset gives each project exactly one row, which is
 * where the two definitions must agree.
 */

/**
 * Open risks per program, counted through the project as the code did before
 * Y-13: a risk that happened with no solution recorded.
 *
 * @return array<int, int>
 */
function openRisksByProgramThroughProjects(): array
{
    return DB::table('risks')
        ->join('projects', 'projects.id', '=', 'risks.project_id')
        ->where('risks.happened', true)
        ->where(fn ($query) => $query->whereNull('risks.solution')->orWhere('risks.solution', ''))
        ->groupBy('projects.program_id')
        ->selectRaw('projects.program_id, count(*) as aggregate')
        ->pluck('aggregate', 'program_id')
        ->map(fn (mixed $count): int => (int) $count)
        ->all();
}

/**
 * High risks per strategy, counted through the project as the code did before
 * Y-13.
 *
 * @return array<int, int>
 */
function highRisksByStrategyThroughProjects(): array
{
    return DB::table('risks')
        ->join('projects', 'projects.id', '=', 'risks.project_id')
        ->join('programs', 'programs.id', '=', 'projects.program_id')
        ->whereRaw('risks.likelihood * risks.severity >= ?', [StrategyRollup::HighRiskScore])
        ->groupBy('programs.strategy_id')
        ->selectRaw('programs.strategy_id, count(*) as aggregate')
        ->pluck('aggregate', 'strategy_id')
        ->map(fn (mixed $count): int => (int) $count)
        ->all();
}

/**
 * The weekly program digest sentence sent to $user.
 */
function weeklyDigestSentence(User $user): string
{
    return collect(sentencesFor($user))->first(fn (string $sentence): bool => str_contains($sentence, 'this week')) ?? '';
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->managerEmployee = insertHrEmployee();
    $this->manager = userWithRole('Managers', $this->managerEmployee);

    Activity::withoutRecording(function (): void {
        $this->strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
        $this->program = Program::factory()->create([
            'manager_id' => $this->managerEmployee,
            'strategy_id' => $this->strategy->id,
            'name_en' => 'WASH',
        ]);
        $this->project = Project::factory()->create(['program_id' => $this->program->id, 'end_date' => '2027-12-31']);
        $this->row = ProposalLocation::factory()->forProject($this->project)->create();

        // Logged the way the register logged them before Y-13: on the project.
        Risk::factory()->rated(3, 3)->create(['project_id' => $this->project->id, 'happened' => true, 'solution' => null]);
        Risk::factory()->rated(3, 3)->create(['project_id' => $this->project->id, 'happened' => true, 'solution' => 'Rerouted']);
        Risk::factory()->rated(1, 2)->create(['project_id' => $this->project->id]);
    });
});

test('the weekly digest counts the same open risks after the risks move to the row', function () {
    $expected = openRisksByProgramThroughProjects();

    expect($expected)->toBe([$this->program->id => 1]);

    $this->artisan('risks:row-backfill')->assertSuccessful();

    app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-21 08:00')); // Monday

    expect(weeklyDigestSentence($this->manager))
        ->toContain($expected[$this->program->id].' open risks')
        ->and(DB::table('risks')->whereNotNull('proposal_location_id')->count())->toBe(3);
});

test('the strategies register counts the same high risks after the risks move to the row', function () {
    $expected = highRisksByStrategyThroughProjects();

    expect($expected)->toBe([$this->strategy->id => 2]);

    $this->artisan('risks:row-backfill')->assertSuccessful();

    $viewer = User::factory()->role('General Manager')->create();

    expect(StrategyRollup::for($viewer, [$this->strategy->id])[$this->strategy->id]['risks'])
        ->toBe($expected[$this->strategy->id]);
});

test('a risk no row could take is counted by neither figure, and is not invented anywhere', function () {
    $orphanProgram = Activity::withoutRecording(function (): Program {
        $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);
        $project = Project::factory()->create(['program_id' => $program->id]);
        Risk::factory()->rated(3, 3)->create(['project_id' => $project->id, 'happened' => true, 'solution' => null]);

        return $program;
    });

    $this->artisan('risks:row-backfill')->assertSuccessful();

    app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-21 08:00'));

    expect(weeklyDigestSentence($this->manager))->toContain('1 open risks')
        ->and(StrategyRollup::for(User::factory()->role('General Manager')->create(), [$this->strategy->id])[$this->strategy->id]['risks'])->toBe(2)
        ->and(DB::table('risks')->whereNull('proposal_location_id')->count())->toBe(1)
        ->and($orphanProgram->id)->not->toBe($this->program->id);
});
