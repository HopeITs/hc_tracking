<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "My reporting duties": what waits on the viewer, grouped by what they have
 * to do. Monthly reports to fill in merge per place (one line opening the
 * oldest month); each report to validate keeps its own line; donor reports the
 * viewer is responsible for are to send. Late lines first, then by due date.
 */
final class ReportingDuties
{
    /**
     * @param  Collection<int, ActiveReportRow>  $rows
     * @return array{fill: list<DutyLine>, check: list<DutyLine>, send: list<DutyLine>, total: int, late: int}
     */
    public static function group(Collection $rows): array
    {
        $mine = $rows->filter(fn (ActiveReportRow $row): bool => $row->needsMe);
        $isMonthly = fn (ActiveReportRow $row): bool => $row->type === ActiveReportType::Monthly;

        $fill = $mine->filter(fn (ActiveReportRow $row): bool => $isMonthly($row) && ! $row->needsCheck)
            ->groupBy(fn (ActiveReportRow $row): int => (int) $row->templateId)
            ->map(fn (Collection $place): DutyLine => self::merged($place));

        $check = $mine->filter(fn (ActiveReportRow $row): bool => $isMonthly($row) && $row->needsCheck)
            ->map(fn (ActiveReportRow $row): DutyLine => self::merged(collect([$row])));

        $send = $mine->filter(fn (ActiveReportRow $row): bool => ! $isMonthly($row))
            ->map(fn (ActiveReportRow $row): DutyLine => self::merged(collect([$row])));

        $groups = ['fill' => self::sorted($fill), 'check' => self::sorted($check), 'send' => self::sorted($send)];
        $all = [...$groups['fill'], ...$groups['check'], ...$groups['send']];

        return [
            ...$groups,
            'total' => count($all),
            'late' => count(array_filter($all, fn (DutyLine $line): bool => $line->late)),
        ];
    }

    /**
     * @param  Collection<int, ActiveReportRow>  $rows  one or more reports of one place
     */
    private static function merged(Collection $rows): DutyLine
    {
        $ordered = $rows->sortBy(fn (ActiveReportRow $row): string => (string) ($row->periodKey ?? $row->due?->format('Y-m-d')))->values();
        /** @var ActiveReportRow $oldest */
        $oldest = $ordered->first();
        /** @var ActiveReportRow $newest */
        $newest = $ordered->last();

        return new DutyLine(
            type: $oldest->type,
            key: $oldest->key(),
            name: $newest->name,
            context: $newest->context,
            months: $oldest->type === ActiveReportType::Monthly ? array_values($ordered->pluck('periodKey')->map(fn (mixed $month): string => (string) $month)->all()) : [],
            due: $oldest->due ?? CarbonImmutable::today(),
            late: $ordered->contains(fn (ActiveReportRow $row): bool => $row->overdue || $row->returned),
            url: $oldest->url,
        );
    }

    /**
     * @param  Collection<int|string, DutyLine>  $lines
     * @return list<DutyLine>
     */
    private static function sorted(Collection $lines): array
    {
        return array_values($lines
            ->sort(fn (DutyLine $a, DutyLine $b): int => [! $a->late, $a->due->getTimestamp(), $a->key] <=> [! $b->late, $b->due->getTimestamp(), $b->key])
            ->all());
    }
}
