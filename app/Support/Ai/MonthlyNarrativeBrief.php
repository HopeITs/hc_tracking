<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\ActivityStatus;
use App\Models\MonthlyReport;
use App\Models\ReportTemplateQuestion;
use Illuminate\Support\Collection;

/**
 * Turns one monthly report into the plain-text facts the narrative drafter
 * reads: the month, the indicator figures, the activities and the questions.
 *
 * Built from the rows the report page already shows, so the model sees the
 * same figures, in the same wording, as the officer.
 */
final class MonthlyNarrativeBrief
{
    /**
     * @param  list<array{code: string, name: string, target: string, computed: string, note?: string|null}>  $lines
     * @param  list<array{statement: string, status: ActivityStatus|null, reason?: string|null, note?: string|null}>  $activities
     * @param  Collection<int, ReportTemplateQuestion>  $questions
     */
    public static function build(MonthlyReport $report, array $lines, array $activities, Collection $questions): string
    {
        $brief = [
            'PROJECT: '.$report->project->name,
            'MONTH: '.$report->period->translatedFormat('F Y'),
            '',
            'INDICATORS THIS MONTH (code | indicator | target | achieved | officer note):',
        ];

        foreach ($lines as $line) {
            $brief[] = '- '.implode(' | ', [$line['code'], $line['name'], $line['target'], $line['computed'], self::text($line['note'] ?? null)]);
        }

        if ($lines === []) {
            $brief[] = '- none';
        }

        $brief[] = '';
        $brief[] = 'ACTIVITIES THIS MONTH (activity | status | reason | officer note):';

        foreach ($activities as $activity) {
            $brief[] = '- '.implode(' | ', [
                $activity['statement'],
                $activity['status']?->label() ?? 'not reported',
                self::text($activity['reason'] ?? null),
                self::text($activity['note'] ?? null),
            ]);
        }

        if ($activities === []) {
            $brief[] = '- none';
        }

        $brief[] = '';
        $brief[] = 'QUESTIONS TO ANSWER (id: question):';

        foreach ($questions as $question) {
            $brief[] = "- {$question->id}: {$question->localizedText()}";
        }

        return implode("\n", $brief);
    }

    private static function text(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '—' : str_replace(["\r", "\n"], ' ', $value);
    }
}
