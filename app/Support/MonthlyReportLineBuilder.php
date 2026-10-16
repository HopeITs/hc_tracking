<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Models\MonthlyReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the lines of a draft monthly report from the project's confirmed
 * report template (contracts §13 "Lines of month M", §13.1 item 1, D-38).
 *
 * The lines of month M are the confirmed template lines due in M
 * (ReportTemplateLine::isDueIn()) whose target's window covers M
 * (PlanIndicator::coverableWindow()), grouped by library indicator: one
 * `monthly_report_lines` row per library indicator and one share per target
 * in both attribution modes. A line is `meal` when any of its template
 * lines is. Each share's fixed denominator is its template line's; the
 * line's is their sum in a `split` program and the highest in a `shared`
 * one.
 *
 * Only drafts are built, and only by adding: existing lines and shares are
 * never changed or removed, so running it again (or after a template
 * change) adds what is missing and nothing else. A system writer with no
 * Gate: the prepare and backfill commands call it, and the T17A actions
 * OpenMonthlyReport and RefreshMonthlyReportLines (slice 3) authorise
 * before calling it. The report row is locked and the unique indexes back
 * the inserts; the query count does not grow with the number of lines.
 */
final class MonthlyReportLineBuilder
{
    /**
     * Add the missing lines and shares of a draft report.
     *
     * @return array{lines: int, shares: int} rows created
     */
    public function build(MonthlyReport $report): array
    {
        return DB::transaction(function () use ($report): array {
            $locked = MonthlyReport::query()->whereKey($report->id)->lockForUpdate()->first(['id', 'project_id', 'period', 'status']);

            if ($locked === null || $locked->status !== MonthlyReportStatus::Draft) {
                return ['lines' => 0, 'shares' => 0];
            }

            $project = Project::query()->with('program:id,donor_attribution')->findOrFail($locked->project_id);
            $planned = $this->plannedLines($this->templateLines($project), $locked->period, self::attributionOf($project));

            return $planned === [] ? ['lines' => 0, 'shares' => 0] : $this->insert($locked, $planned);
        });
    }

    /**
     * The project's confirmed template lines, with what isDueIn() and the
     * target window read eager-loaded. Load once, then plan many months.
     *
     * @return Collection<int, ReportTemplateLine>
     */
    public function templateLines(Project $project): Collection
    {
        return ReportTemplateLine::query()
            ->whereIn('report_template_id', ReportTemplate::query()->select('id')->where('project_id', $project->id))
            ->where('status', ReportTemplateLineStatus::Confirmed)
            ->with(['planIndicator.indicator.libraryIndicator', 'planIndicator.source'])
            ->orderBy('id')
            ->get();
    }

    /**
     * The lines month M should have, keyed by library indicator id. Pure: no
     * query once the template lines are loaded.
     *
     * @param  Collection<int, ReportTemplateLine>  $templateLines
     * @return array<int, array{entered_by: IndicatorCollector, fixed_denominator: ?int, shares: array<int, ?int>}> shares: plan_indicator id => fixed denominator
     */
    public function plannedLines(Collection $templateLines, CarbonImmutable $month, DonorAttribution $attribution): array
    {
        $month = $month->startOfMonth();
        $groups = [];

        foreach ($templateLines as $templateLine) {
            $target = $templateLine->planIndicator;
            $libraryId = $target->indicator->library_indicator_id;

            if ($libraryId === null || ! $templateLine->isDueIn($month) || ! self::covers($target, $month)) {
                continue;
            }

            $groups[$libraryId] ??= ['entered_by' => IndicatorCollector::ProjectTeam, 'fixed_denominator' => null, 'shares' => []];
            $groups[$libraryId]['shares'][$target->id] = $templateLine->fixed_denominator;

            if ($templateLine->entered_by === IndicatorCollector::Meal) {
                $groups[$libraryId]['entered_by'] = IndicatorCollector::Meal;
            }
        }

        foreach ($groups as $libraryId => $group) {
            $denominators = array_values(array_filter($group['shares'], fn (?int $denominator): bool => $denominator !== null));

            $groups[$libraryId]['fixed_denominator'] = $denominators === [] ? null : match ($attribution) {
                DonorAttribution::Split => array_sum($denominators),
                DonorAttribution::Shared => max($denominators),
            };
        }

        ksort($groups);

        return $groups;
    }

    /**
     * How many lines build() would add to the month: the planned lines the
     * report does not have yet. Read-only, for the dry runs. A report that
     * is not a draft gets none.
     *
     * @param  Collection<int, ReportTemplateLine>  $templateLines  from templateLines()
     * @param  MonthlyReport|null  $report  null for a month not generated yet; with `lines` loaded
     */
    public function missingLineCount(Project $project, Collection $templateLines, CarbonImmutable $month, ?MonthlyReport $report): int
    {
        if ($report !== null && $report->status !== MonthlyReportStatus::Draft) {
            return 0;
        }

        $planned = array_keys($this->plannedLines($templateLines, $month, self::attributionOf($project)));
        $existing = $report === null ? [] : $report->lines->pluck('library_indicator_id')->map(fn (int|string $id): int => (int) $id)->all();

        return count(array_diff($planned, $existing));
    }

    /**
     * The donor attribution mode of the project's program (D-46). Load
     * `program` first.
     */
    public static function attributionOf(Project $project): DonorAttribution
    {
        return $project->program->donor_attribution;
    }

    /**
     * Whether the target's window (proposal or plan year) covers the month.
     */
    private static function covers(PlanIndicator $target, CarbonImmutable $month): bool
    {
        $window = $target->coverableWindow();

        return $window !== null
            && $window[0]->startOfMonth()->lessThanOrEqualTo($month)
            && $window[1]->greaterThanOrEqualTo($month);
    }

    /**
     * Insert the missing lines, then the missing shares of every planned
     * line (an existing line may gain the share of a later proposal).
     *
     * @param  array<int, array{entered_by: IndicatorCollector, fixed_denominator: ?int, shares: array<int, ?int>}>  $planned
     * @return array{lines: int, shares: int}
     */
    private function insert(MonthlyReport $report, array $planned): array
    {
        $now = now();
        $lines = DB::table('monthly_report_lines')->insertOrIgnore(array_map(fn (int $libraryId, array $line): array => [
            'monthly_report_id' => $report->id,
            'library_indicator_id' => $libraryId,
            'entered_by' => $line['entered_by']->value,
            'fixed_denominator' => $line['fixed_denominator'],
            'not_measured' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys($planned), $planned));

        $lineIds = $report->lines()->whereIn('library_indicator_id', array_keys($planned))->pluck('id', 'library_indicator_id');
        $shares = [];

        foreach ($planned as $libraryId => $line) {
            foreach ($line['shares'] as $planIndicatorId => $fixedDenominator) {
                $shares[] = [
                    'monthly_report_line_id' => (int) $lineIds[$libraryId],
                    'plan_indicator_id' => $planIndicatorId,
                    'fixed_denominator' => $fixedDenominator,
                    'not_measured' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return ['lines' => $lines, 'shares' => DB::table('monthly_report_line_shares')->insertOrIgnore($shares)];
    }
}
