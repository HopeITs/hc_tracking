<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The day-detail dialog on calendar/index: every visible item on one day,
 * read from the same per-day groups the grid's chips use.
 *
 * Used by calendar/index only. The #[Computed] calendar() comes from
 * BuildsCalendarGrid, canViewAgreements() from ChecksFinanceVisibility.
 *
 * @property-read array{month: CarbonImmutable, weeks: ?array<int, array<int, CarbonImmutable>>, days: ?array<int, CarbonImmutable>, milestonesByDay: \Illuminate\Support\Collection<string, Collection<int, Milestone>>, tasksByDay: \Illuminate\Support\Collection<string, Collection<int, Task>>, reportsByDay: \Illuminate\Support\Collection<string, Collection<int, Report>>, proposalsByDay: \Illuminate\Support\Collection<string, Collection<int, Proposal>>, transfersByDay: \Illuminate\Support\Collection<string, Collection<int, TransfersTracking>>, donorFollowUpsByDay: \Illuminate\Support\Collection<string, Collection<int, Donor>>, donorReportsByDay: \Illuminate\Support\Collection<string, Collection<int, DonorReport>>, projectStartsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, projectEndsByDay: \Illuminate\Support\Collection<string, Collection<int, Project>>, unscheduledTasks: Collection<int, Task>, nowOffsetPercent: float} $calendar  #[Computed] calendar()
 */
trait ShowsCalendarDay
{
    public string $dayDetailDate = '';

    public bool $showDayDetailModal = false;

    /**
     * Opens the day-detail list for a day that already has at least one
     * visible item on it — the click target that would otherwise land on
     * the "add" context menu instead, since a day with content is something
     * to look at, not a blank slot to fill.
     */
    public function openDayDetail(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $this->dayDetailDate = $date;
        $this->showDayDetailModal = true;
    }

    public function closeDayDetail(): void
    {
        $this->showDayDetailModal = false;
    }

    /**
     * Every item visible on $dayDetailDate, across every type currently
     * shown on the grid — built from the same per-day groupings the chips
     * render from, so this list never disagrees with what the day's chips
     * actually show.
     *
     * @return \Illuminate\Support\Collection<int, array{type: string, kind: string, id: int, url: ?string, title: string, subLabel: ?string}>
     */
    #[Computed]
    public function dayDetailItems(): \Illuminate\Support\Collection
    {
        if ($this->dayDetailDate === '') {
            return new \Illuminate\Support\Collection;
        }

        $calendar = $this->calendar;
        $date = $this->dayDetailDate;
        $rows = new \Illuminate\Support\Collection;

        foreach ($calendar['milestonesByDay']->get($date, new Collection) as $milestone) {
            $rows->push(['type' => 'milestone', 'kind' => 'modal-milestone', 'id' => $milestone->id, 'url' => null, 'title' => $milestone->name, 'subLabel' => $milestone->project?->name]);
        }

        foreach ($calendar['tasksByDay']->get($date, new Collection) as $task) {
            $rows->push(['type' => 'task', 'kind' => 'modal-task', 'id' => $task->id, 'url' => null, 'title' => $task->title, 'subLabel' => null]);
        }

        foreach ($calendar['reportsByDay']->get($date, new Collection) as $report) {
            $rows->push(['type' => 'report', 'kind' => 'link', 'id' => $report->id, 'url' => route('reports.show', $report), 'title' => $report->planIndicator?->indicator?->name ?? __('calendar.report_deadline'), 'subLabel' => null]);
        }

        foreach ($calendar['proposalsByDay']->get($date, new Collection) as $proposal) {
            $rows->push(['type' => 'proposal', 'kind' => 'link', 'id' => $proposal->id, 'url' => route('proposals.show', $proposal), 'title' => $proposal->name, 'subLabel' => null]);
        }

        foreach ($calendar['transfersByDay']->get($date, new Collection) as $transfer) {
            $rows->push([
                'type' => 'transfer',
                'kind' => 'link',
                'id' => $transfer->id,
                'url' => $this->canViewAgreements() ? route('funding-agreements.show', $transfer->donorProposal) : null,
                'title' => $transfer->donorProposal?->donor?->name.' · '.number_format((float) $transfer->payment_amount, 2).' '.$transfer->amount_transfered_currency->value,
                'subLabel' => null,
            ]);
        }

        foreach ($calendar['donorFollowUpsByDay']->get($date, new Collection) as $donor) {
            $rows->push(['type' => 'donor', 'kind' => 'link', 'id' => $donor->id, 'url' => route('donors.show', $donor), 'title' => $donor->name, 'subLabel' => __('calendar.donor_follow_up')]);
        }

        foreach ($calendar['donorReportsByDay']->get($date, new Collection) as $donorReport) {
            $rows->push(['type' => 'donor', 'kind' => 'link', 'id' => $donorReport->id, 'url' => route('funding-agreements.show', $donorReport->donorProposal), 'title' => $donorReport->title, 'subLabel' => __('calendar.donor_report_due')]);
        }

        foreach ($calendar['projectStartsByDay']->get($date, new Collection) as $project) {
            $rows->push(['type' => 'project', 'kind' => 'link', 'id' => $project->id, 'url' => route('projects.show', $project), 'title' => $project->name, 'subLabel' => __('calendar.project_starts')]);
        }

        foreach ($calendar['projectEndsByDay']->get($date, new Collection) as $project) {
            $rows->push(['type' => 'project', 'kind' => 'link', 'id' => $project->id, 'url' => route('projects.show', $project), 'title' => $project->name, 'subLabel' => __('calendar.project_ends')]);
        }

        return $rows;
    }
}
