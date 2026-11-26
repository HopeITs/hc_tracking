<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use App\Models\DonorReport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Report Tracker's doors as numbers: which kinds a viewer may open, the
 * default monthly window and what each door counts. Shared by the page
 * (pages::active-reports.index) and its Desk panel (desk.panels.tracker), so
 * the panel always says what clicking through shows.
 */
final class ReportTrackerDoors
{
    /** The oldest month the default window may reach back to. */
    public const int MaxMonths = 12;

    /**
     * Monthly for anyone with reports.view, donor as DonorReportPolicy::viewAny decides.
     *
     * @return list<string>
     */
    public static function kinds(User $user): array
    {
        return $user->can('viewAny', DonorReport::class) ? ['monthly', 'donor'] : ['monthly'];
    }

    /**
     * My reporting duties sits only beside two kinds.
     *
     * @param  list<string>  $kinds
     * @return list<string>
     */
    public static function offered(array $kinds): array
    {
        return count($kinds) > 1 ? ['monthly', 'duties', 'donor'] : $kinds;
    }

    /**
     * @param  Collection<int, ActiveReportRow>  $rows
     * @return Collection<int, ActiveReportRow>
     */
    public static function ofType(Collection $rows, ActiveReportType $type): Collection
    {
        return $rows->filter(fn (ActiveReportRow $row): bool => $row->type === $type)->values();
    }

    /**
     * The last six started months, reaching back to the oldest open month (at most twelve) so no backlog hides.
     *
     * @param  Collection<int, ActiveReportRow>  $open
     * @return array{0: string, 1: string}
     */
    public static function defaultWindow(Collection $open): array
    {
        $now = CarbonImmutable::today()->startOfMonth();
        $start = $now->subMonths(5);
        $oldestOpen = self::ofType($open, ActiveReportType::Monthly)->pluck('periodKey')->filter()->min();

        if (is_string($oldestOpen) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $oldestOpen) === 1) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m', $oldestOpen);

            if ($parsed !== false && $parsed->lessThan($start)) {
                $start = $parsed->max($now->subMonths(self::MaxMonths - 1));
            }
        }

        return [$start->format('Y-m'), $now->format('Y-m')];
    }

    /**
     * Donor reports in lanes: overdue, due within thirty days, later, and
     * submitted with a due date in the last twelve months.
     *
     * @param  Collection<int, ActiveReportRow>  $open
     * @param  Collection<int, ActiveReportRow>  $closed
     * @return array{overdue: list<ActiveReportRow>, soon: list<ActiveReportRow>, later: list<ActiveReportRow>, submitted: list<ActiveReportRow>}
     */
    public static function lanes(Collection $open, Collection $closed, bool $mineOnly = false, ?int $donorId = null): array
    {
        $today = CarbonImmutable::today();
        $soon = $today->addDays(30);
        $byDonor = fn (ActiveReportRow $row): bool => $donorId === null || $row->donorId === $donorId;
        $open = $open->filter($byDonor)
            ->filter(fn (ActiveReportRow $row): bool => ! $mineOnly || $row->needsMe)
            ->sortBy(fn (ActiveReportRow $row): int => $row->due?->getTimestamp() ?? PHP_INT_MAX);
        $lane = fn (callable $keep): array => array_values($open->filter($keep)->all());

        return [
            'overdue' => $lane(fn (ActiveReportRow $row): bool => $row->overdue),
            'soon' => $lane(fn (ActiveReportRow $row): bool => ! $row->overdue && $row->due !== null && $row->due->lessThanOrEqualTo($soon)),
            'later' => $lane(fn (ActiveReportRow $row): bool => ! $row->overdue && ($row->due === null || $row->due->greaterThan($soon))),
            'submitted' => array_values($closed->filter($byDonor)
                ->filter(fn (ActiveReportRow $row): bool => $row->due !== null && $row->due->greaterThanOrEqualTo($today->subMonths(12)))
                ->sortByDesc(fn (ActiveReportRow $row): int => $row->due?->getTimestamp() ?? 0)
                ->all()),
        ];
    }

    /**
     * What each door counts, before any search or filter.
     *
     * @param  Collection<int, ActiveReportRow>  $open
     * @param  Collection<int, ActiveReportRow>  $closed
     * @return array{duties: array{total: int, late: int, check: int}, monthly: array{needs: int, late: int, open: int, done: int}, donor: array{needs: int, late: int, open: int, done: int}}
     */
    public static function counts(Collection $open, Collection $closed): array
    {
        $duties = ReportingDuties::group($open);
        $monthly = MonthlyTrackerGrid::build(self::ofType($open->concat($closed), ActiveReportType::Monthly), ...self::defaultWindow($open))['counts'];
        $openDonor = self::ofType($open, ActiveReportType::Donor);
        $lanes = self::lanes($openDonor, self::ofType($closed, ActiveReportType::Donor));

        return [
            'duties' => ['total' => $duties['total'], 'late' => $duties['late'], 'check' => count($duties['check'])],
            'monthly' => ['needs' => $monthly['needs'], 'late' => $monthly['late'], 'open' => $monthly['open'], 'done' => $monthly['done']],
            'donor' => ['needs' => $openDonor->where('needsMe', true)->count(), 'late' => count($lanes['overdue']), 'open' => $openDonor->count(), 'done' => count($lanes['submitted'])],
        ];
    }
}
