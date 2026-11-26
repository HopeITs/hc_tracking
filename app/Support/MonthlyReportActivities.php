<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ActivityStatus;
use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\MonthlyReport;
use App\Models\ProjectActivityStatus;
use App\Models\Proposal;
use App\Models\Result;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The activities of a monthly report's month (contracts §13 "Activities this
 * month", D-49b, D-51): the one read helper used by the save action, Submit
 * and the T17B page.
 *
 * An activity of a donor-approved proposal of the report's template (Y-06,
 * D-78: every proposal a joined template carries; Y-03, D-76: the report's
 * own template) appears when its calendar schedules it in the month
 * (ActivitySchedule::effectiveOf() at its proposal's location row at the
 * template's place, Y-15) and
 * the month lies inside its proposal's schedule window, when its last status
 * confirmed by an earlier month is not final (not started, started, ongoing,
 * suspended), or when this report already has an entry for it. Both the
 * calendar and the confirmed statuses are read at that row (Y-15). Queries stay constant whatever the
 * number of activities.
 */
final class MonthlyReportActivities
{
    /**
     * `proposal_name` is set only when more than one proposal feeds the report.
     *
     * @return Collection<int, array{activity: Result, proposal_name: string|null, scheduled: bool, planned_weeks: list<int>|null, carried_from: CarbonImmutable|null, late: bool, last_status: ActivityStatus|null}>
     */
    public static function for(MonthlyReport $report): Collection
    {
        $month = CarbonImmutable::parse($report->period)->startOfMonth();

        $proposals = self::proposalsOf($report);
        $proposalIds = $proposals->keys()->all();

        $activities = Result::query()
            ->where('type', ResultType::Activity->value)
            ->whereIn('proposal_id', $proposalIds)
            ->orderBy('id')
            ->get();

        if ($activities->isEmpty()) {
            return collect();
        }

        $activityIds = $activities->modelKeys();

        $locationIds = $report->loadMissing('template')->template?->locationIdsByProposal(array_values($proposalIds)) ?? [];

        /** @var Collection<int, Collection<int, ActivitySchedule>> $schedules */
        $schedules = ActivitySchedule::query()
            ->whereIn('result_id', $activityIds)
            ->where(fn ($query) => $query->whereNull('proposal_location_id')->when($locationIds !== [], fn ($query) => $query->orWhereIn('proposal_location_id', array_values($locationIds))))
            ->get()
            ->groupBy('result_id');

        /** @var Collection<int, ProjectActivityStatus> $statuses */
        $statuses = ProjectActivityStatus::query()
            ->whereIn('proposal_location_id', array_values($locationIds))
            ->whereIn('result_id', $activityIds)
            ->with('monthlyReport:id,period')
            ->get()
            ->keyBy('result_id');

        $entered = $report->activityEntries()->pluck('result_id')->all();

        $windows = $proposals->mapWithKeys(fn (Proposal $proposal): array => [$proposal->id => ProposalFramework::scheduleWindow($proposal)]);
        $labelled = $proposals->count() > 1;

        return $activities->map(function (Result $activity) use ($schedules, $statuses, $entered, $month, $report, $proposals, $windows, $labelled, $locationIds): ?array {
            $calendar = ActivitySchedule::effectiveOf($schedules->get($activity->id, collect()), $locationIds[$activity->proposal_id] ?? null);
            [$from, $to] = $windows->get($activity->proposal_id);
            $inWindow = $month->greaterThanOrEqualTo($from) && $month->lessThanOrEqualTo($to);
            $scheduled = $inWindow && array_key_exists($month->format('Y-m'), $calendar);

            $status = $statuses->get($activity->id);
            $confirmedEarlier = $status !== null
                && $status->monthly_report_id !== $report->id
                && $status->monthlyReport !== null
                && CarbonImmutable::parse($status->monthlyReport->period)->lessThan($month);
            $carried = $confirmedEarlier && ! $status->status->isFinal();
            $stoppedEarlier = $confirmedEarlier && $status->status->isFinal();

            $listed = in_array($activity->id, $entered, true)
                || $carried
                || ($scheduled && ! $stoppedEarlier);

            if (! $listed) {
                return null;
            }

            $weeks = $scheduled ? $calendar[$month->format('Y-m')] : null;

            return [
                'activity' => $activity,
                'proposal_name' => $labelled ? (string) $proposals->get($activity->proposal_id)?->name : null,
                'scheduled' => $scheduled,
                'planned_weeks' => $weeks,
                'carried_from' => $carried ? CarbonImmutable::parse($status->monthlyReport->period)->startOfMonth() : null,
                'late' => $carried && $status->status === ActivityStatus::NotStarted,
                'last_status' => $confirmedEarlier ? $status->status : null,
            ];
        })->filter()->values();
    }

    /**
     * The donor-approved proposals of the report's template (D-76), by id.
     *
     * @return Collection<int, Proposal>
     */
    public static function proposalsOf(MonthlyReport $report): Collection
    {
        $statuses = array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor());

        return Proposal::query()
            ->whereIn('proposals.status', $statuses)
            ->whereIn('proposals.id', DB::table('proposal_report_template')->where('report_template_id', $report->report_template_id)->select('proposal_id'))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }
}
