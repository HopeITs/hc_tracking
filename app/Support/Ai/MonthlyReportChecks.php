<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\ReportTemplateQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The quality check on a monthly report: things a MEAL reviewer would ask
 * about before validating, found by rules, not by a model, so they are
 * instant, free and repeatable. Advisory only: nothing here blocks Submit.
 *
 * - A figure far from the same indicator's usual level in the project's
 *   last three submitted months (spike or drop).
 * - A narrative answer quoting a number that appears nowhere in the month's
 *   figures, targets or notes (a typo, or a figure that was never entered).
 */
final class MonthlyReportChecks
{
    /**
     * A figure at least this many times its usual level is a spike.
     */
    public const float SPIKE_FACTOR = 2.0;

    /**
     * A figure at most this share of its usual level is a drop.
     */
    public const float DROP_FACTOR = 0.5;

    /**
     * Narrative numbers below this are counted words ("two groups", "3 days"),
     * not figures worth checking.
     */
    public const int SMALLEST_CHECKED_NUMBER = 10;

    /**
     * @param  Collection<int, ReportTemplateQuestion>  $questions
     * @return list<array{kind: 'spike'|'drop'|'unmatched_number', text: string}>
     */
    public static function for(MonthlyReport $report, Collection $questions): array
    {
        return [...self::unusualFigures($report), ...self::unmatchedNumbers($report, $questions)];
    }

    /**
     * @return list<array{kind: 'spike'|'drop', text: string}>
     */
    private static function unusualFigures(MonthlyReport $report): array
    {
        $lines = $report->lines->filter(fn (MonthlyReportLine $line): bool => ! $line->not_measured && $line->value !== null);

        if ($lines->isEmpty()) {
            return [];
        }

        $period = CarbonImmutable::parse($report->period)->startOfMonth();
        $history = MonthlyReportLine::query()
            ->join('monthly_reports', 'monthly_reports.id', '=', 'monthly_report_lines.monthly_report_id')
            ->where('monthly_reports.project_id', $report->project_id)
            ->whereIn('monthly_reports.status', [MonthlyReportStatus::Submitted->value, MonthlyReportStatus::Validated->value])
            ->where('monthly_reports.period', '<', $period->toDateString())
            ->where('monthly_reports.period', '>=', $period->subMonths(3)->toDateString())
            ->whereIn('monthly_report_lines.library_indicator_id', $lines->pluck('library_indicator_id'))
            ->where('monthly_report_lines.not_measured', false)
            ->whereNotNull('monthly_report_lines.value')
            ->get(['monthly_report_lines.library_indicator_id', 'monthly_report_lines.value'])
            ->groupBy('library_indicator_id');

        $checks = [];

        foreach ($lines as $line) {
            $previous = $history->get($line->library_indicator_id);

            if ($previous === null) {
                continue;
            }

            $usual = (float) $previous->avg(fn (MonthlyReportLine $row): float => (float) $row->value);
            $value = (float) $line->value;

            if ($usual <= 0.0) {
                continue;
            }

            $kind = match (true) {
                $value >= $usual * self::SPIKE_FACTOR => 'spike',
                $value <= $usual * self::DROP_FACTOR => 'drop',
                default => null,
            };

            if ($kind !== null) {
                $checks[] = ['kind' => $kind, 'text' => (string) __('ai.checks.'.$kind, [
                    'indicator' => $line->libraryIndicator->localizedName(),
                    'value' => self::figure($value),
                    'usual' => self::figure($usual),
                    'months' => $previous->count(),
                ])];
            }
        }

        return $checks;
    }

    /**
     * @param  Collection<int, ReportTemplateQuestion>  $questions
     * @return list<array{kind: 'unmatched_number', text: string}>
     */
    private static function unmatchedNumbers(MonthlyReport $report, Collection $questions): array
    {
        $known = self::knownNumbers($report);
        $questionTexts = $questions->keyBy('id');
        $checks = [];

        foreach ($report->answers as $answer) {
            /** @var MonthlyReportAnswer $answer */
            $question = $questionTexts->get($answer->report_template_question_id);

            if ($question === null || blank($answer->answer)) {
                continue;
            }

            foreach (array_unique(self::numbersIn((string) $answer->answer)) as $number) {
                if ($number < self::SMALLEST_CHECKED_NUMBER || self::isYear($number) || in_array($number, $known, true)) {
                    continue;
                }

                $checks[] = ['kind' => 'unmatched_number', 'text' => (string) __('ai.checks.unmatched_number', [
                    'number' => self::figure($number),
                    'question' => $question->localizedText(),
                ])];
            }
        }

        return $checks;
    }

    /**
     * Every number the month's own data contains: figures, targets,
     * denominators and the numbers written in line and activity notes.
     *
     * @return list<float>
     */
    private static function knownNumbers(MonthlyReport $report): array
    {
        $known = [];

        foreach ($report->lines as $line) {
            array_push($known, ...self::numbersOf([$line->value, $line->numerator, $line->denominator, $line->fixed_denominator]));
            array_push($known, ...self::numbersIn((string) $line->note));

            foreach ($line->shares as $share) {
                /** @var MonthlyReportLineShare $share */
                array_push($known, ...self::numbersOf([$share->value, $share->numerator, $share->denominator, $share->fixed_denominator, $share->planIndicator->target]));
            }
        }

        foreach ($report->activityEntries as $entry) {
            /** @var MonthlyReportActivityEntry $entry */
            array_push($known, ...self::numbersIn((string) $entry->note.' '.$entry->reason));
        }

        return array_values(array_unique($known, SORT_REGULAR));
    }

    /**
     * @param  list<int|float|string|null>  $values
     * @return list<float>
     */
    private static function numbersOf(array $values): array
    {
        return array_values(array_map(
            fn (int|float|string $value): float => round((float) $value, 2),
            array_filter($values, fn (mixed $value): bool => is_numeric($value)),
        ));
    }

    /**
     * The numbers written in a text, Arabic-Indic digits included.
     *
     * @return list<float>
     */
    private static function numbersIn(string $text): array
    {
        $latin = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '']);

        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $latin, $matches);

        return array_map(fn (string $match): float => round((float) str_replace(',', '', $match), 2), $matches[0]);
    }

    private static function isYear(float $number): bool
    {
        return $number >= 1990 && $number <= 2100 && floor($number) === $number;
    }

    private static function figure(float $value): string
    {
        return floor($value) === $value ? number_format($value) : number_format($value, 1);
    }
}
