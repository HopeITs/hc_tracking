<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Enums\DonorAttribution;
use App\Enums\IndicatorMeasure;
use App\Enums\ReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\Report;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The context around one monthly report (2026-11-23 redesign): for each
 * count line, how the month compares with its monthly share of the target
 * and where the year stands with this month included; and the template's
 * other months of the year, to step between them. Reads the validated
 * mirrors of the line's targets once; nothing here is written.
 *
 * Targets and months combine as the program's attribution says: a shared
 * program counts one figure toward every target (the largest), a split one
 * adds the donors' shares.
 *
 * @property-read MonthlyReport $report
 * @property-read DonorAttribution $attribution
 */
trait ReadsMonthlyReportContext
{
    /**
     * Per count line (keyed by line id): its year target, the month's share
     * of it, the month against that share, the year so far with this month,
     * the share of the year that should be reached by now, and the monthly
     * figures from January to this month. Lines that are not counts, or have
     * no target, are absent.
     *
     * @return array<int, array{target: float, share: float, month: float|null, vsShare: float|null, ytd: float, ytdPercent: float, expectedPercent: float, behind: bool, series: list<array{month: int, value: float, current: bool}>}>
     */
    #[Computed]
    public function lineContext(): array
    {
        $report = $this->report;
        $year = $report->period->year;
        $monthNumber = $report->period->month;
        $lines = $report->lines->filter(fn (MonthlyReportLine $line): bool => $line->libraryIndicator?->measure === IndicatorMeasure::Numeric);
        $targetIds = $lines->flatMap(fn (MonthlyReportLine $line) => $line->shares->pluck('plan_indicator_id'))->unique()->values()->all();

        if ($targetIds === []) {
            return [];
        }

        $byTargetMonth = [];

        Report::query()
            ->whereIn('plan_indicator_id', $targetIds)
            ->where('status', ReportStatus::Approved->value)
            ->whereNotNull('monthly_report_id')
            ->where('monthly_report_id', '!=', $report->id)
            ->whereYear('period_start', $year)
            ->whereMonth('period_start', '<', $monthNumber)
            ->toBase()
            ->get(['plan_indicator_id', 'period_start', 'value'])
            ->each(function (object $mirror) use (&$byTargetMonth): void {
                $month = (int) substr((string) $mirror->period_start, 5, 2);
                $byTargetMonth[(int) $mirror->plan_indicator_id][$month] = ($byTargetMonth[(int) $mirror->plan_indicator_id][$month] ?? 0.0) + (float) $mirror->value;
            });

        $context = [];

        foreach ($lines as $line) {
            $targets = $line->shares->pluck('planIndicator')->filter()->unique('id');
            $target = $this->attribution->combine(array_values($targets->map(fn ($planIndicator): float => (float) $planIndicator->target)->all()));

            if ($target <= 0.0) {
                continue;
            }

            $series = [];
            $before = 0.0;

            for ($month = 1; $month < $monthNumber; $month++) {
                $value = $this->attribution->combine(array_values($targets->map(fn ($planIndicator): float => $byTargetMonth[$planIndicator->id][$month] ?? 0.0)->all()));
                $before += $value;
                $series[] = ['month' => $month, 'value' => $value, 'current' => false];
            }

            $thisMonth = $line->value === null ? null : (float) $line->value;
            $series[] = ['month' => $monthNumber, 'value' => $thisMonth ?? 0.0, 'current' => true];
            $share = $target / 12;
            $ytd = $before + ($thisMonth ?? 0.0);
            $expectedPercent = $monthNumber / 12 * 100;
            $ytdPercent = $ytd / $target * 100;

            $context[$line->id] = [
                'target' => $target,
                'share' => $share,
                'month' => $thisMonth,
                'vsShare' => $thisMonth === null ? null : $thisMonth / $share * 100,
                'ytd' => $ytd,
                'ytdPercent' => $ytdPercent,
                'expectedPercent' => $expectedPercent,
                'behind' => $ytdPercent < $expectedPercent * 0.95,
                'series' => $series,
            ];
        }

        return $context;
    }

    /**
     * The template's reports of the same year, January first, to step
     * between months.
     *
     * @return Collection<int, MonthlyReport>
     */
    #[Computed]
    public function yearReports(): Collection
    {
        $report = $this->report;

        return MonthlyReport::query()
            ->where('report_template_id', $report->report_template_id)
            ->whereYear('period', $report->period->year)
            ->orderBy('period')
            ->get(['id', 'slug', 'period', 'status']);
    }
}
