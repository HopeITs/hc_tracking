<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Program;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Launch seeding of the 2026 annual plans (contracts §12.4, D-44): gives every
 * tracking program that has none its 2026 plan, managed by the program's
 * manager. A program without a manager is skipped and listed by id, never
 * given an invented one; an existing 2026 plan is never touched. Prints one
 * row per plan created, then the created / already there / skipped totals.
 * Idempotent; --dry-run prints the same rows and totals and writes nothing.
 * Runs silent: a launch fill writes no activity rows (Dev 1, 2026-10-23).
 */
class EnsureAnnualPlans2026 extends Command
{
    /**
     * The only fiscal year this command plans.
     */
    public const int YEAR = 2026;

    protected $signature = 'annual-plans:ensure-2026
        {--dry-run : List the plans that would be created without writing anything}';

    protected $description = 'Create the missing 2026 annual plan of every program';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $planned = AnnualPlan::query()
            ->where('year', self::YEAR)
            ->pluck('program_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();
        $programs = Program::query()->orderBy('id')->get(['id', 'manager_id']);
        $counts = ['created' => 0, 'existing' => 0];
        $skipped = [];

        foreach ($programs as $program) {
            if ($planned->has($program->id)) {
                $counts['existing']++;

                continue;
            }

            if ($program->manager_id === null) {
                $skipped[] = $program->id;

                continue;
            }

            if (! $dryRun && ! Activity::withoutRecording(fn (): bool => $this->createPlan($program))) {
                $counts['existing']++;

                continue;
            }

            $counts['created']++;
            $this->line(__('annual_plans.console.plan_row', [
                'program' => $program->id,
                'year' => self::YEAR,
                'manager' => $program->manager_id,
            ]));
        }

        if ($skipped !== []) {
            $this->warn(__('annual_plans.console.skipped_no_manager', ['programs' => implode(', ', $skipped)]));
        }

        $this->info(__('annual_plans.console.summary', [
            'created' => $counts['created'],
            'existing' => $counts['existing'],
            'skipped' => count($skipped),
        ]));

        if ($dryRun) {
            $this->info(__('annual_plans.console.dry_run'));
        }

        return self::SUCCESS;
    }

    /**
     * Create the program's plan, unless a concurrent creator got there first.
     *
     * The program row is locked and the plan re-checked inside the
     * transaction; a creator that does not take the lock still meets the
     * (program_id, year) unique index, which is read as "already there".
     *
     * @return bool whether this call created the plan
     */
    private function createPlan(Program $program): bool
    {
        try {
            return DB::transaction(function () use ($program): bool {
                Program::query()->whereKey($program->id)->lockForUpdate()->first(['id']);

                if (AnnualPlan::query()->where('program_id', $program->id)->where('year', self::YEAR)->exists()) {
                    return false;
                }

                $plan = new AnnualPlan([
                    'program_id' => $program->id,
                    'manager_id' => $program->manager_id,
                    'year' => self::YEAR,
                ]);
                $plan->setRelation('program', $program);
                $plan->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
