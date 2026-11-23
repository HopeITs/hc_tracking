<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportReopenRequest;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\MealCoverage;
use App\Support\MonthlyReportPreparers;

/**
 * Who does what on a monthly report (contracts §13.1 items 6 and 9).
 *
 * - Preparer side (D-84): a report writer who is the responsible employee of
 *   a location row of one of the template's proposals at its place, or a
 *   supervisor of one (MonthlyReportPreparers). They open, fill, submit,
 *   delete a never-submitted draft and request a reopen.
 * - Validator: a reports.approve holder who is the responsible MEAL officer
 *   (MealCoverage::responsibleForPlace() of the report template), the MEAL Manager slot holder, or any
 *   MEAL role holder when no one is responsible (D-12a). Never the preparer or
 *   submitter, never the General Manager.
 * - Reopen decider (D-09a): a reports.reopen holder who holds the MEAL
 *   Manager slot, else, while the slot is vacant, an active Public Relations
 *   Manager. Never the requester or the preparer.
 *
 * Super Admin passes every check technically but is never a tested path (D-20a).
 */
class MonthlyReportPolicy
{
    public function __construct(
        private MonthlyReportPreparers $preparers,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('reports.view');
    }

    /**
     * Read-only access to any monthly report.
     */
    public function view(User $user, MonthlyReport $monthlyReport): bool
    {
        return $user->can('reports.view');
    }

    /**
     * Whether the user may open (prepare) a month of the template. A list
     * that checks many templates passes their responsibles, read once with
     * MonthlyReportPreparers::responsiblesByTemplate(), so the rule stays
     * here and costs no query per template.
     *
     * @param  list<int>|null  $responsibles
     */
    public function open(User $user, ReportTemplate $template, ?array $responsibles = null): bool
    {
        return $user->can('reports.create') && ($responsibles === null
            ? $this->preparers->isPreparer($user, $template)
            : $this->preparers->isPreparerAmong($user, $responsibles));
    }

    /**
     * Fill the manager's lines, activities, answers and files of a draft.
     */
    public function update(User $user, MonthlyReport $monthlyReport): bool
    {
        return $monthlyReport->status === MonthlyReportStatus::Draft
            && $user->can('reports.update')
            && $this->isPreparerSide($user, $monthlyReport);
    }

    /**
     * Fill or mark not measured the MEAL lines while the report is submitted (D-48a).
     */
    public function fillMealLines(User $user, MonthlyReport $monthlyReport): bool
    {
        return $monthlyReport->status === MonthlyReportStatus::Submitted && $this->isValidator($user, $monthlyReport);
    }

    public function submit(User $user, MonthlyReport $monthlyReport): bool
    {
        return $this->update($user, $monthlyReport);
    }

    public function validate(User $user, MonthlyReport $monthlyReport): bool
    {
        return $monthlyReport->status === MonthlyReportStatus::Submitted && $this->isValidator($user, $monthlyReport);
    }

    public function return(User $user, MonthlyReport $monthlyReport): bool
    {
        return $this->validate($user, $monthlyReport);
    }

    /**
     * Ask for a validated report to be reopened. Whether a request is already
     * pending is checked by the action.
     */
    public function requestReopen(User $user, MonthlyReport $monthlyReport): bool
    {
        return $monthlyReport->status === MonthlyReportStatus::Validated
            && $user->can('reports.update')
            && $this->isPreparerSide($user, $monthlyReport);
    }

    /**
     * Grant or deny a pending reopen request of this report (D-09a).
     */
    public function decideReopen(User $user, MonthlyReport $monthlyReport, MonthlyReportReopenRequest $request): bool
    {
        if ($request->monthly_report_id !== $monthlyReport->id
            || $request->status !== ReopenRequestStatus::Pending
            || ! $user->can('reports.reopen')
            || $user->employee_id === null
            || in_array($user->id, [$request->requested_by, $monthlyReport->prepared_by], true)) {
            return false;
        }

        $slotHolder = MealCoverage::mealManagerEmployeeId();

        if ($slotHolder !== null) {
            return $user->employee_id === $slotHolder;
        }

        return $user->hasRole('Public Relations Manager')
            && MealCoverage::activeEmployeeIds([$user->employee_id]) !== [];
    }

    /**
     * Acknowledge a submitted or validated report for MEAL.
     */
    public function markSeen(User $user, MonthlyReport $monthlyReport): bool
    {
        return $monthlyReport->status !== MonthlyReportStatus::Draft
            && $user->can('reports.mark-seen')
            && $this->isValidator($user, $monthlyReport);
    }

    /**
     * Delete a draft that was never submitted (so it has no reopen request).
     */
    public function delete(User $user, MonthlyReport $monthlyReport): bool
    {
        return $monthlyReport->submitted_at === null && $this->update($user, $monthlyReport);
    }

    /**
     * A responsible of the template's rows at its place, or a supervisor of one (D-84).
     */
    private function isPreparerSide(User $user, MonthlyReport $monthlyReport): bool
    {
        $template = $monthlyReport->relationLoaded('template')
            ? $monthlyReport->template
            : ReportTemplate::query()->findOrFail($monthlyReport->report_template_id);

        return $this->preparers->isPreparer($user, $template);
    }

    /**
     * The responsible officer, the MEAL Manager slot holder, or any MEAL role
     * holder when no one is responsible; never the preparer, the submitter or
     * the General Manager. A template with no legacy project falls back to the
     * report's own project_id mirror, as MonthlyReport::validatableBy() does.
     */
    private function isValidator(User $user, MonthlyReport $monthlyReport): bool
    {
        if (! $user->can('reports.approve')
            || $user->hasRole('General Manager')
            || in_array($user->id, [$monthlyReport->prepared_by, $monthlyReport->submitted_by], true)) {
            return false;
        }

        $template = $monthlyReport->relationLoaded('template')
            ? $monthlyReport->template
            : ReportTemplate::query()->findOrFail($monthlyReport->report_template_id);
        $responsible = MealCoverage::responsibleForPlace($template->place());

        if ($user->employee_id !== null
            && ($user->employee_id === $responsible || $user->employee_id === MealCoverage::mealManagerEmployeeId())) {
            return true;
        }

        return $responsible === null && $user->hasRole('MEAL');
    }
}
