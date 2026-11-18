<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the missing `draft` monthly reports of a report template, one per
 * month (contracts §13 "generate", D-04b, D-05, D-76, Y-03). Months that
 * already have a report are left exactly as they are, whatever their status;
 * nothing is deleted. New drafts have no lines: MonthlyReportLineBuilder adds
 * them when the month starts.
 *
 * A system writer with no Gate: it runs from the template confirmation
 * listener, the backfill command and the seeders. The template row is
 * locked, so two runs on one template serialise, and the unique (template,
 * period) index backs the check. Each new report also gets the template's
 * legacy project (`project_id`, PlaceBridge::legacyProjectIdFor(), null when
 * there is none) for the readers that still need it. The months are written
 * in one bulk insert, so the query count does not grow with the period; no
 * activity is recorded per month. This class never reads `projects` itself.
 */
final class MonthlyReportGenerator
{
    /**
     * The proposal columns windowOf() reads, for callers that eager-load
     * `proposals` on many templates at once.
     */
    public const WindowColumns = ['proposals.id', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'extended_date_end'];

    /**
     * Create the missing months from the month of $from to the month of $to.
     *
     * @return list<int> the ids of the reports created
     */
    public function generate(ReportTemplate $template, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::transaction(function () use ($template, $from, $to): array {
            $locked = ReportTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $missing = $this->missingMonths($locked, $from, $to);

            if ($missing === []) {
                return [];
            }

            return Activity::withoutRecording(fn (): array => $this->insert($locked, $missing));
        });
    }

    /**
     * The months between $from and $to (by month) that have no report of the
     * template yet, oldest first. Read-only, for the dry runs too.
     *
     * @return list<CarbonImmutable>
     */
    public function missingMonths(ReportTemplate $template, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = self::monthsBetween($from, $to);

        if ($months === []) {
            return [];
        }

        $existing = MonthlyReport::query()
            ->where('report_template_id', $template->id)
            ->whereBetween('period', [$months[0]->toDateString(), end($months)->toDateString()])
            ->pluck('period')
            ->map(fn (CarbonImmutable $period): string => $period->toDateString())
            ->all();

        return array_values(array_filter($months, fn (CarbonImmutable $month): bool => ! in_array($month->toDateString(), $existing, true)));
    }

    /**
     * The months the template reports for: from the earliest start of its
     * proposals (`actual_date_begin`, else `planned_date_begin`) to their
     * latest end (`extended_date_end`, else `actual_date_end`, else
     * `planned_date_end`). Null when it has no proposal. Reads `proposals`
     * when the caller eager-loaded them, else one query.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function windowOf(ReportTemplate $template): ?array
    {
        $proposals = $template->relationLoaded('proposals')
            ? $template->proposals
            : $template->proposals()->get(self::WindowColumns);

        $starts = $proposals->map(fn (Proposal $proposal): CarbonImmutable => $proposal->actual_date_begin ?? $proposal->planned_date_begin)->filter();
        $ends = $proposals->map(fn (Proposal $proposal): CarbonImmutable => $proposal->extended_date_end ?? $proposal->actual_date_end ?? $proposal->planned_date_end)->filter();

        if ($starts->isEmpty() || $ends->isEmpty()) {
            return null;
        }

        /** @var CarbonImmutable $start */
        $start = $starts->min();
        /** @var CarbonImmutable $end */
        $end = $ends->max();

        return [$start, $end];
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
     * Insert the months in one statement. The slug is
     * `report-{template id}-{month}` (Y-03 slice 3: no project in it). A month
     * whose slug is already taken is saved through the model instead, so
     * HasSlug adds its numeric suffix.
     *
     * @param  list<CarbonImmutable>  $months
     * @return list<int>
     */
    private function insert(ReportTemplate $template, array $months): array
    {
        $projectId = app(PlaceBridge::class)->legacyProjectIdFor($template);
        $prefix = 'report-'.$template->id;
        $slugs = [];

        foreach ($months as $month) {
            $slugs[$month->toDateString()] = Str::slug($prefix.'-'.$month->format('Y-m'));
        }

        $taken = MonthlyReport::query()->whereIn('slug', array_values($slugs))->pluck('slug')->all();
        $now = now();
        $rows = [];

        foreach ($months as $month) {
            $slug = $slugs[$month->toDateString()];

            if (in_array($slug, $taken, true)) {
                $this->saveThroughModel($template, $projectId, $month);

                continue;
            }

            $rows[] = [
                'slug' => $slug,
                'report_template_id' => $template->id,
                'project_id' => $projectId,
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
            ->where('report_template_id', $template->id)
            ->whereIn('period', array_map(fn (CarbonImmutable $month): string => $month->toDateString(), $months))
            ->orderBy('period')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all());
    }

    /**
     * Save one month through the model, so HasSlug suffixes a taken slug.
     */
    private function saveThroughModel(ReportTemplate $template, ?int $projectId, CarbonImmutable $month): void
    {
        $report = new MonthlyReport([
            'report_template_id' => $template->id,
            'project_id' => $projectId,
            'period' => $month,
            'status' => MonthlyReportStatus::Draft,
        ]);

        $report->save();
    }
}
