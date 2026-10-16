<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the missing `draft` monthly reports of a project, one per month
 * (contracts §13 "generate", D-04b, D-05). Months that already have a report
 * are left exactly as they are, whatever their status; nothing is deleted.
 * New drafts have no lines: MonthlyReportLineBuilder adds them when the
 * month starts.
 *
 * A system writer with no Gate: it runs from the activation listener and
 * the backfill command. The project row is locked, so two runs on one
 * project serialise, and the unique (project, period) index backs the
 * check. The months are written in one bulk insert, so the query count does
 * not grow with the period; no activity is recorded per month.
 */
final class MonthlyReportGenerator
{
    /**
     * Create the missing months from the month of $from to the month of $to.
     *
     * @return list<int> the ids of the reports created
     */
    public function generate(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::transaction(function () use ($project, $from, $to): array {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail(['id', 'slug']);
            $missing = $this->missingMonths($locked, $from, $to);

            if ($missing === []) {
                return [];
            }

            return Activity::withoutRecording(fn (): array => $this->insert($locked, $missing));
        });
    }

    /**
     * The months between $from and $to (by month) that have no report yet,
     * oldest first. Read-only, for the dry runs too.
     *
     * @return list<CarbonImmutable>
     */
    public function missingMonths(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = self::monthsBetween($from, $to);

        if ($months === []) {
            return [];
        }

        $existing = MonthlyReport::query()
            ->where('project_id', $project->id)
            ->whereBetween('period', [$months[0]->toDateString(), end($months)->toDateString()])
            ->pluck('period')
            ->map(fn (CarbonImmutable $period): string => $period->toDateString())
            ->all();

        return array_values(array_filter($months, fn (CarbonImmutable $month): bool => ! in_array($month->toDateString(), $existing, true)));
    }

    /**
     * The first day of every month from the month of $from to the month of $to.
     *
     * @return list<CarbonImmutable>
     */
    public static function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];

        for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to->startOfMonth()); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * Insert the months in one statement. A month whose slug is already
     * taken (by another project's report) is saved through the model
     * instead, so HasSlug adds its numeric suffix.
     *
     * @param  list<CarbonImmutable>  $months
     * @return list<int>
     */
    private function insert(Project $project, array $months): array
    {
        $slugs = [];

        foreach ($months as $month) {
            $slugs[$month->toDateString()] = Str::slug(($project->slug ?? '').'-'.$month->format('Y-m'));
        }

        $taken = MonthlyReport::query()->whereIn('slug', array_values($slugs))->pluck('slug')->all();
        $now = now();
        $rows = [];

        foreach ($months as $month) {
            $slug = $slugs[$month->toDateString()];

            if (in_array($slug, $taken, true)) {
                $report = new MonthlyReport(['project_id' => $project->id, 'period' => $month, 'status' => MonthlyReportStatus::Draft]);
                $report->setRelation('project', $project);
                $report->save();

                continue;
            }

            $rows[] = [
                'slug' => $slug,
                'project_id' => $project->id,
                'period' => $month->toDateString(),
                'status' => MonthlyReportStatus::Draft->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('monthly_reports')->insertOrIgnore($rows);
        }

        return array_values(MonthlyReport::query()
            ->where('project_id', $project->id)
            ->whereIn('period', array_map(fn (CarbonImmutable $month): string => $month->toDateString(), $months))
            ->orderBy('period')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }
}
