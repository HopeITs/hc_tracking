<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Actions\Reports\ApproveReport;
use App\Actions\Reports\MarkReportSeenByMeal;
use App\Actions\Reports\ReturnReportToDraft;
use App\Actions\Reports\SubmitReportForReview;
use App\Models\Report;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The workflow panel on reports/show: submit, approve, return to draft and
 * the one-way "seen by MEAL" acknowledgement, plus the reporting history of
 * the same target. Each action authorizes itself inside its Action class.
 *
 * Used by reports/show only. The page provides reportId and the #[Computed]
 * report().
 *
 * @property int $reportId
 * @property-read Report $report  #[Computed] report()
 */
trait RunsReportWorkflow
{
    /**
     * Every report against this same target, newest first, this one included.
     *
     * This is what lets the detail page show the figure in the context of its
     * own reporting history rather than as an isolated number.
     *
     * @return Collection<int, Report>
     */
    #[Computed]
    public function history(): Collection
    {
        return Report::query()
            ->select(['id', 'status', 'planned_date', 'value', 'is_seen_by_meal'])
            ->where('plan_indicator_id', $this->report->plan_indicator_id)
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Submit a draft for review.
     *
     * Only valid from Draft. Writes status and submit_date together so the two
     * can never disagree about whether — and when — this was submitted.
     */
    public function submitForReview(SubmitReportForReview $submit): void
    {
        if (! $submit->handle(Report::query()->findOrFail($this->reportId))) {
            return;
        }

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_submitted_for_review'));
    }

    /**
     * Approve a reviewed report.
     *
     * Only valid from Reviewed.
     */
    public function approve(ApproveReport $approve): void
    {
        if (! $approve->handle(Report::query()->findOrFail($this->reportId))) {
            return;
        }

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_approved'));
    }

    /**
     * Send a reviewed or approved report back to draft.
     *
     * Editability is derived from status (Report::isEditable()), so returning
     * to draft is what reopens a report for editing — there is no separate
     * lock/unlock action any more. submit_date is cleared with it: a draft
     * has not been submitted, and submitting again stamps a fresh date.
     */
    public function returnToDraft(ReturnReportToDraft $returnToDraft): void
    {
        if (! $returnToDraft->handle(Report::query()->findOrFail($this->reportId))) {
            return;
        }

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.report_returned_to_draft'));
    }

    /**
     * Acknowledge that MEAL has reviewed this figure.
     *
     * One way on purpose: is_seen_by_meal has no paired date column, so there
     * is nothing to record about un-seeing it, and "MEAL never looked at this"
     * is not a state a reviewer can restore.
     */
    public function markSeenByMeal(MarkReportSeenByMeal $markSeen): void
    {
        $markSeen->handle(Report::query()->findOrFail($this->reportId));

        unset($this->report);

        Flux::toast(variant: 'success', text: __('reports.marked_as_seen_by_meal'));
    }
}
