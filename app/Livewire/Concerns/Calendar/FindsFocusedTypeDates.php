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
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;

/**
 * The dates of the focused type on calendar/index: moving the grid to its
 * nearest occurrence, and the related-dates list beside the grid.
 *
 * Used by calendar/index only. The page provides the #[Url] month,
 * anchorDate and viewMode; focusedType() and canViewDonors() come from
 * FiltersCalendarTypes, scopedEmployeeIds() from ScopesCalendarPeople,
 * canViewProposals(), canViewTransfers() and canViewAgreements() from
 * ChecksFinanceVisibility.
 *
 * @property string $month
 * @property string $anchorDate
 * @property string $viewMode
 */
trait FindsFocusedTypeDates
{
    /**
     * Moves the grid to the month (or period anchor) containing the nearest
     * occurrence of $type: the next upcoming one if there is one, otherwise
     * the most recent past one, so focusing a type with only historical
     * records still lands somewhere with data instead of today's empty month.
     */
    protected function jumpToNearest(string $type): void
    {
        $nearest = $this->nearestDateFor($type);

        if ($nearest === null) {
            return;
        }

        if ($this->viewMode === 'month') {
            $this->month = $nearest->format('Y-m');
        } else {
            $this->anchorDate = $nearest->toDateString();
        }
    }

    protected function nearestDateFor(string $type): ?CarbonImmutable
    {
        $employeeIds = $this->scopedEmployeeIds();

        if ($employeeIds === []) {
            return null;
        }

        return match ($type) {
            'milestone' => $this->nearestOrLatestDate(
                Milestone::query()->whereIn('responsible_user_id', $employeeIds),
                'planned_start_date'
            ),
            'task' => $this->nearestOrLatestDate(
                Task::query()->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))->whereNotNull('due_date'),
                'due_date'
            ),
            'report' => $this->nearestOrLatestDate(
                Report::query()->whereIn('user_id', User::query()->whereIn('employee_id', $employeeIds)->pluck('id')),
                'planned_date'
            ),
            'proposal' => ! $this->canViewProposals() ? null : $this->nearestOrLatestDate(
                Proposal::query()->whereHas('projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'planned_date_end'
            ),
            'transfer' => ! $this->canViewTransfers() ? null : $this->nearestOrLatestDate(
                TransfersTracking::query()->whereNotNull('scheduled_date')->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'scheduled_date'
            ),
            'donor' => $this->pickCloserDate(
                ! $this->canViewDonors() ? null : $this->nearestOrLatestDate(
                    Donor::query()->whereNotNull('next_step_due_on')->whereHas('proposals.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                    'next_step_due_on'
                ),
                ! $this->canViewAgreements() ? null : $this->nearestOrLatestDate(
                    DonorReport::query()->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                    'due_on'
                ),
            ),
            'project' => $this->pickCloserDate(
                $this->nearestOrLatestDate(
                    Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('start_date'),
                    'start_date'
                ),
                $this->nearestOrLatestDate(
                    Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('end_date'),
                    'end_date'
                ),
            ),
            default => null,
        };
    }

    /**
     * The next date on/after today for $column, or the most recent one
     * before today when nothing upcoming exists.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    protected function nearestOrLatestDate(Builder $query, string $column): ?CarbonImmutable
    {
        $today = CarbonImmutable::today()->toDateString();

        $upcoming = (clone $query)->where($column, '>=', $today)->orderBy($column)->value($column);

        if ($upcoming !== null) {
            return CarbonImmutable::parse($upcoming);
        }

        $past = (clone $query)->where($column, '<', $today)->orderByDesc($column)->value($column);

        return $past === null ? null : CarbonImmutable::parse($past);
    }

    /**
     * Picks whichever of two candidate dates sits fewer days from today, so
     * a composite type (donor follow-ups + donor reports, project starts +
     * ends) jumps to whichever of its two sources is actually the nearer one.
     */
    protected function pickCloserDate(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        $today = CarbonImmutable::today();

        return abs($today->diffInDays($a, false)) <= abs($today->diffInDays($b, false)) ? $a : $b;
    }

    /**
     * Up to $limit upcoming records for $column (today or later), or, when
     * none are upcoming, the $limit most recent past ones in chronological
     * order — so a focused type with only historical records still shows a
     * populated related-dates list instead of an empty one.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    protected function upcomingOrRecent(Builder $query, string $column, int $limit = 15): Collection
    {
        $today = CarbonImmutable::today()->toDateString();

        $upcoming = (clone $query)->where($column, '>=', $today)->orderBy($column)->limit($limit)->get();

        if ($upcoming->isNotEmpty()) {
            return $upcoming;
        }

        return (clone $query)->where($column, '<', $today)->orderByDesc($column)->limit($limit)->get()->reverse()->values();
    }

    /**
     * The related-dates list for the currently focused type: title, date,
     * an optional sub-label (which of a composite type's two sources this
     * row came from) and how to open it — a modal action for milestones and
     * tasks (they have no show page of their own), a link for everything
     * else, matching the same click-through the grid chips use.
     *
     * Returns the base Support Collection, not the Eloquent one aliased as
     * Collection in this file: mapping models to plain arrays makes Eloquent
     * Collection::map() downgrade to toBase() internally, so the real return
     * value never satisfies an Eloquent-typed signature.
     *
     * @return \Illuminate\Support\Collection<int, array{kind: string, id: int, url: ?string, title: string, subLabel: ?string, date: CarbonImmutable}>
     */
    #[Computed]
    public function focusedItems(): \Illuminate\Support\Collection
    {
        $type = $this->focusedType();

        if ($type === null) {
            return new \Illuminate\Support\Collection;
        }

        $employeeIds = $this->scopedEmployeeIds();

        if ($employeeIds === []) {
            return new \Illuminate\Support\Collection;
        }

        return match ($type) {
            'milestone' => $this->upcomingOrRecent(
                Milestone::query()->whereIn('responsible_user_id', $employeeIds)->with('project:id,name'),
                'planned_start_date'
            )->map(fn (Milestone $milestone): array => [
                'kind' => 'modal-milestone',
                'id' => $milestone->id,
                'url' => null,
                'title' => $milestone->name,
                'subLabel' => $milestone->project?->name,
                'date' => $milestone->planned_start_date,
            ]),
            'task' => $this->upcomingOrRecent(
                Task::query()->whereHas('taskAssignees', fn (Builder $query) => $query->whereIn('employee_id', $employeeIds))->whereNotNull('due_date'),
                'due_date'
            )->map(fn (Task $task): array => [
                'kind' => 'modal-task',
                'id' => $task->id,
                'url' => null,
                'title' => $task->title,
                'subLabel' => null,
                'date' => $task->due_date,
            ]),
            'report' => $this->upcomingOrRecent(
                Report::query()->whereIn('user_id', User::query()->whereIn('employee_id', $employeeIds)->pluck('id'))->with('planIndicator.indicator:id,name'),
                'planned_date'
            )->map(fn (Report $report): array => [
                'kind' => 'link',
                'id' => $report->id,
                'url' => route('reports.show', $report),
                'title' => $report->planIndicator?->indicator?->name ?? __('calendar.report_deadline'),
                'subLabel' => null,
                'date' => $report->planned_date,
            ]),
            'proposal' => ($this->canViewProposals() ? $this->upcomingOrRecent(
                Proposal::query()->whereHas('projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'planned_date_end'
            ) : new Collection)->map(fn (Proposal $proposal): array => [
                'kind' => 'link',
                'id' => $proposal->id,
                'url' => route('proposals.show', $proposal),
                'title' => $proposal->name,
                'subLabel' => null,
                'date' => $proposal->planned_date_end,
            ]),
            'transfer' => ($this->canViewTransfers() ? $this->upcomingOrRecent(
                TransfersTracking::query()
                    ->whereNotNull('scheduled_date')
                    ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds))
                    ->with('donorProposal:id,slug,donor_id', 'donorProposal.donor:id,name'),
                'scheduled_date'
            ) : new Collection)->map(fn (TransfersTracking $transfer): array => [
                'kind' => 'link',
                'id' => $transfer->id,
                'url' => $this->canViewAgreements() ? route('funding-agreements.show', $transfer->donorProposal) : null,
                'title' => $transfer->donorProposal?->donor?->name.' · '.number_format((float) $transfer->payment_amount, 2).' '.$transfer->amount_transfered_currency->value,
                'subLabel' => null,
                'date' => $transfer->scheduled_date,
            ]),
            'donor' => ($this->canViewDonors() ? $this->upcomingOrRecent(
                Donor::query()->whereNotNull('next_step_due_on')->whereHas('proposals.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds)),
                'next_step_due_on'
            ) : new Collection)->map(fn (Donor $donor): array => [
                'kind' => 'link',
                'id' => $donor->id,
                'url' => route('donors.show', $donor),
                'title' => $donor->name,
                'subLabel' => __('calendar.donor_follow_up'),
                'date' => $donor->next_step_due_on,
            ])->concat(
                ($this->canViewAgreements() ? $this->upcomingOrRecent(
                    DonorReport::query()
                        ->whereHas('donorProposal.proposal.projects', fn (Builder $query) => $query->whereIn('projects.user_id', $employeeIds))
                        ->with('donorProposal:id,slug'),
                    'due_on'
                ) : new Collection)->map(fn (DonorReport $report): array => [
                    'kind' => 'link',
                    'id' => $report->id,
                    'url' => route('funding-agreements.show', $report->donorProposal),
                    'title' => $report->title,
                    'subLabel' => __('calendar.donor_report_due'),
                    'date' => $report->due_on,
                ])
            )->sortBy('date')->values()->take(15),
            'project' => $this->upcomingOrRecent(
                Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('start_date'),
                'start_date'
            )->map(fn (Project $project): array => [
                'kind' => 'link',
                'id' => $project->id,
                'url' => route('projects.show', $project),
                'title' => $project->name,
                'subLabel' => __('calendar.project_starts'),
                'date' => $project->start_date,
            ])->concat(
                $this->upcomingOrRecent(
                    Project::query()->whereIn('user_id', $employeeIds)->whereNotNull('end_date'),
                    'end_date'
                )->map(fn (Project $project): array => [
                    'kind' => 'link',
                    'id' => $project->id,
                    'url' => route('projects.show', $project),
                    'title' => $project->name,
                    'subLabel' => __('calendar.project_ends'),
                    'date' => $project->end_date,
                ])
            )->sortBy('date')->values()->take(15),
            default => new \Illuminate\Support\Collection,
        };
    }
}
