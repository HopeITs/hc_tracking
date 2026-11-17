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
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The activities of a monthly report's month (contracts §13 "Activities this
 * month", D-49b, D-51): the one read helper used by the save action, Submit
 * and the T17B page.
 *
 * An activity of a donor-approved proposal of the report's template (Y-06,
 * D-78: every proposal a joined template carries) appears when its calendar
 * schedules it in the month (the project's override rows when it has any,
 * else the proposal calendar) and the month lies inside its proposal's
 * schedule window, when its last status confirmed by an earlier month is not
 * final (not started, started, ongoing, suspended), or when this report
 * already has an entry for it. A project with no template yet (data from
 * before Y-02) keeps the proposals linked to the project. Until Y-03 gives a
 * report its own template, the proposals of all the templates of the report's
 * project are used. Queries stay constant whatever the number of activities.
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
        $projectId = $report->project_id;

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

        /** @var Collection<int, Collection<int, ActivitySchedule>> $schedules */
        $schedules = ActivitySchedule::query()
            ->whereIn('result_id', $activityIds)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $projectId))
            ->get()
            ->groupBy('result_id');

        /** @var Collection<int, ProjectActivityStatus> $statuses */
        $statuses = ProjectActivityStatus::query()
            ->where('project_id', $projectId)
            ->whereIn('result_id', $activityIds)
            ->with('monthlyReport:id,period')
            ->get()
            ->keyBy('result_id');

        $entered = $report->activityEntries()->pluck('result_id')->all();

        $windows = $proposals->mapWithKeys(fn (Proposal $proposal): array => [$proposal->id => ProposalFramework::scheduleWindow($proposal)]);
        $labelled = $proposals->count() > 1;

        return $activities->map(function (Result $activity) use ($schedules, $statuses, $entered, $month, $report, $proposals, $windows, $labelled): ?array {
            $rows = $schedules->get($activity->id, collect());
            $calendar = $rows->contains(fn (ActivitySchedule $row): bool => $row->project_id !== null)
                ? $rows->whereNotNull('project_id')
                : $rows->whereNull('project_id');
            [$from, $to] = $windows->get($activity->proposal_id);
            $inWindow = $month->greaterThanOrEqualTo($from) && $month->lessThanOrEqualTo($to);
            $scheduledRow = $inWindow
                ? $calendar->first(fn (ActivitySchedule $row): bool => CarbonImmutable::parse($row->month)->isSameMonth($month))
                : null;

            $status = $statuses->get($activity->id);
            $confirmedEarlier = $status !== null
                && $status->monthly_report_id !== $report->id
                && $status->monthlyReport !== null
                && CarbonImmutable::parse($status->monthlyReport->period)->lessThan($month);
            $carried = $confirmedEarlier && ! $status->status->isFinal();
            $stoppedEarlier = $confirmedEarlier && $status->status->isFinal();

            $listed = in_array($activity->id, $entered, true)
                || $carried
                || ($scheduledRow !== null && ! $stoppedEarlier);

            if (! $listed) {
                return null;
            }

            /** @var list<int>|null $weeks */
            $weeks = $scheduledRow?->weeks;

            return [
                'activity' => $activity,
                'proposal_name' => $labelled ? (string) $proposals->get($activity->proposal_id)?->name : null,
                'scheduled' => $scheduledRow !== null,
                'planned_weeks' => $weeks,
                'carried_from' => $carried ? CarbonImmutable::parse($status->monthlyReport->period)->startOfMonth() : null,
                'late' => $carried && $status->status === ActivityStatus::NotStarted,
                'last_status' => $confirmedEarlier ? $status->status : null,
            ];
        })->filter()->values();
    }

    /**
     * The donor-approved proposals the report asks about, by id.
     *
     * @return Collection<int, Proposal>
     */
    private static function proposalsOf(MonthlyReport $report): Collection
    {
        $statuses = array_map(fn (ProposalStatus $status): string => $status->value, ProposalStatus::approvedByDonor());
        $templateIds = app(PlaceBridge::class)->templateIdsOfProject($report->project_id);

        if ($templateIds === []) {
            return $report->project()->firstOrFail()->proposals()
                ->whereIn('proposals.status', $statuses)
                ->get(['proposals.*'])
                ->keyBy('id');
        }

        return Proposal::query()
            ->whereIn('proposals.status', $statuses)
            ->whereIn('proposals.id', DB::table('proposal_report_template')->whereIn('report_template_id', $templateIds)->select('proposal_id'))
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }
}
