<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Report Tracker's monthly view: one line per place (report template),
 * one cell per month of the window, each cell the place's monthly report for
 * that month or null when there is none. Counts are read from the same cells,
 * so the door, the summary and the grid never disagree.
 */
final class MonthlyTrackerGrid
{
    /**
     * @param  Collection<int, ActiveReportRow>  $rows  monthly rows, open and finished
     * @param  string  $from  first month, 'Y-m'
     * @param  string  $to  last month, 'Y-m'
     * @return array{
     *     months: list<string>,
     *     places: list<array{templateId: int, name: string, context: string, owner: ?string, programId: ?int, behind: int, cells: array<string, ?ActiveReportRow>}>,
     *     counts: array{open: int, needs: int, late: int, wait: int, done: int}
     * }
     */
    public static function build(Collection $rows, string $from, string $to): array
    {
        $months = self::months($from, $to);
        $inWindow = array_flip($months);
        $counts = ['open' => 0, 'needs' => 0, 'late' => 0, 'wait' => 0, 'done' => 0];

        $places = $rows
            ->filter(fn (ActiveReportRow $row): bool => $row->type === ActiveReportType::Monthly
                && $row->templateId !== null
                && isset($inWindow[(string) $row->periodKey]))
            ->groupBy(fn (ActiveReportRow $row): int => (int) $row->templateId)
            ->map(function (Collection $placeRows, int $templateId) use ($months, &$counts): array {
                $cells = array_fill_keys($months, null);

                foreach ($placeRows as $row) {
                    $cells[(string) $row->periodKey] = $row;
                    $state = $row->state();

                    if ($state === 'done') {
                        $counts['done']++;
                    } else {
                        $counts['open']++;
                    }

                    if ($state === 'late' || $state === 'returned') {
                        $counts['late']++;
                    }

                    if ($state === 'wait') {
                        $counts['wait']++;
                    }

                    if ($row->needsMe) {
                        $counts['needs']++;
                    }
                }

                /** @var ActiveReportRow $newest */
                $newest = $placeRows->sortByDesc(fn (ActiveReportRow $row): string => (string) $row->periodKey)->first();

                return [
                    'templateId' => $templateId,
                    'name' => $newest->name,
                    'context' => $newest->context,
                    'owner' => $newest->owner,
                    'programId' => $newest->programId,
                    'behind' => $placeRows->filter(fn (ActiveReportRow $row): bool => in_array($row->state(), ['late', 'returned'], true))->count(),
                    'cells' => $cells,
                ];
            })
            ->sort(fn (array $a, array $b): int => [$b['behind'], mb_strtolower($a['name'])] <=> [$a['behind'], mb_strtolower($b['name'])])
            ->values();

        return ['months' => $months, 'places' => array_values($places->all()), 'counts' => $counts];
    }

    /**
     * Every month from $from to $to, both included, as 'Y-m'.
     *
     * @return list<string>
     */
    public static function months(string $from, string $to): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $from);
        $last = CarbonImmutable::createFromFormat('!Y-m', $to);
        $months = [];

        while ($month !== false && $last !== false && $month->lessThanOrEqualTo($last)) {
            $months[] = $month->format('Y-m');
            $month = $month->addMonth();
        }

        return $months;
    }
}
