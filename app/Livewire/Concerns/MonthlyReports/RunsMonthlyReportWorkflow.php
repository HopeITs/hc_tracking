<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\DecideMonthlyReportReopen;
use App\Actions\MonthlyReports\DeleteMonthlyReport;
use App\Actions\MonthlyReports\MarkMonthlyReportSeen;
use App\Actions\MonthlyReports\RefreshMonthlyReportLines;
use App\Actions\MonthlyReports\RequestMonthlyReportReopen;
use App\Actions\MonthlyReports\ReturnMonthlyReport;
use App\Actions\MonthlyReports\SubmitMonthlyReport;
use App\Actions\MonthlyReports\ValidateMonthlyReport;
use App\Models\MonthlyReport;
use Closure;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The workflow of monthly-reports/show (T17B, contracts §13 Workflow): each
 * button opens its confirmation (checked against the policy, for the UI
 * only), and each confirmation calls T17A's action with the status the page
 * rendered. The actions authorize; a stale status is a toast and a reload.
 *
 * Requires ReadsMonthlyReport and EditsMonthlyReportLines.
 *
 * @property-read MonthlyReport $report  #[Computed] report()
 */
trait RunsMonthlyReportWorkflow
{
    public bool $showSubmitModal = false;

    public bool $showValidateModal = false;

    public bool $showReturnModal = false;

    public bool $showReopenModal = false;

    public bool $showDecisionModal = false;

    public bool $showDeleteModal = false;

    public string $returnReason = '';

    public string $reopenReason = '';

    public string $decisionNote = '';

    #[Locked]
    public ?int $decidingRequestId = null;

    public function openSubmitModal(): void
    {
        Gate::authorize('submit', $this->report);
        $this->resetErrorBag('workflow');
        $this->showSubmitModal = true;
    }

    public function submitReport(): void
    {
        if ($this->runWorkflow(fn () => app(SubmitMonthlyReport::class)->handle($this->report, $this->renderedStatus()), __('monthly_reports.toast_submitted'))) {
            $this->showSubmitModal = false;
        }
    }

    public function openValidateModal(): void
    {
        Gate::authorize('validate', $this->report);
        $this->resetErrorBag('workflow');
        $this->showValidateModal = true;
    }

    public function validateReport(): void
    {
        if ($this->runWorkflow(fn () => app(ValidateMonthlyReport::class)->handle($this->report, $this->renderedStatus()), __('monthly_reports.toast_validated'))) {
            $this->showValidateModal = false;
        }
    }

    public function openReturnModal(): void
    {
        Gate::authorize('return', $this->report);
        $this->resetErrorBag();
        $this->returnReason = '';
        $this->showReturnModal = true;
    }

    public function returnReport(): void
    {
        $succeeded = $this->runWorkflow(
            fn () => app(ReturnMonthlyReport::class)->handle($this->report, $this->renderedStatus(), $this->returnReason),
            __('monthly_reports.toast_returned'),
            ['reason' => 'returnReason'],
        );

        if ($succeeded) {
            $this->showReturnModal = false;
            $this->returnReason = '';
        }
    }

    public function openReopenModal(): void
    {
        Gate::authorize('requestReopen', $this->report);
        $this->resetErrorBag();
        $this->reopenReason = '';
        $this->showReopenModal = true;
    }

    public function requestReopen(): void
    {
        $succeeded = $this->runWorkflow(
            fn () => app(RequestMonthlyReportReopen::class)->handle($this->report, $this->renderedStatus(), $this->reopenReason),
            __('monthly_reports.toast_reopen_requested'),
            ['reason' => 'reopenReason'],
        );

        if ($succeeded) {
            $this->showReopenModal = false;
            $this->reopenReason = '';
        }
    }

    public function openDecisionModal(int $requestId): void
    {
        $request = $this->report->reopenRequests()->whereKey($requestId)->firstOrFail();
        Gate::authorize('decideReopen', [$this->report, $request]);
        $this->resetErrorBag();
        $this->decidingRequestId = $request->id;
        $this->decisionNote = '';
        $this->showDecisionModal = true;
    }

    public function decideReopen(bool $grant): void
    {
        if ($this->decidingRequestId === null) {
            return;
        }

        $requestId = $this->decidingRequestId;
        $note = trim($this->decisionNote) === '' ? null : $this->decisionNote;

        $succeeded = $this->runWorkflow(
            fn () => app(DecideMonthlyReportReopen::class)->handle($this->report, $requestId, $this->renderedStatus(), $grant, $note),
            $grant ? __('monthly_reports.toast_reopen_granted') : __('monthly_reports.toast_reopen_denied'),
            ['decision_note' => 'decisionNote'],
        );

        if ($succeeded) {
            $this->showDecisionModal = false;
            $this->decidingRequestId = null;
            $this->decisionNote = '';
        }
    }

    public function markSeen(): void
    {
        $this->runWorkflow(fn () => app(MarkMonthlyReportSeen::class)->handle($this->report), __('monthly_reports.toast_seen'));
    }

    public function refreshLines(): void
    {
        $added = ['lines' => 0, 'shares' => 0];

        $this->runWorkflow(
            function () use (&$added): void {
                $added = app(RefreshMonthlyReportLines::class)->handle($this->report, $this->renderedStatus());
            },
            function () use (&$added): string {
                return trans_choice('monthly_reports.lines_added', $added['lines'], ['count' => $added['lines']]);
            },
        );
    }

    public function openDeleteModal(): void
    {
        Gate::authorize('delete', $this->report);
        $this->resetErrorBag('workflow');
        $this->showDeleteModal = true;
    }

    public function deleteReport(): void
    {
        $deleted = $this->runWorkflow(
            fn () => app(DeleteMonthlyReport::class)->handle($this->report, $this->renderedStatus()),
            __('monthly_reports.toast_deleted'),
            reload: false,
        );

        if ($deleted) {
            $this->redirectRoute('monthly-reports.index', navigate: true);
        }
    }

    /**
     * Run one workflow action and turn its refusals into page state:
     * - a stale status → toast, every dialog closed, reload from the server;
     * - a lost permission → toast and reload;
     * - any other message → under its input (`$errorKeys`), else `workflow`.
     *
     * @param  string|Closure(): string  $success
     * @param  array<string, string>  $errorKeys  action error key => page input
     */
    protected function runWorkflow(Closure $action, string|Closure $success, array $errorKeys = [], bool $reload = true): bool
    {
        $this->resetErrorBag(['workflow', ...array_values($errorKeys)]);

        try {
            $action();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                if ($messages[0] === __('monthly_reports.stale')) {
                    $this->abandonWorkflow($messages[0]);

                    return false;
                }

                $this->addError($errorKeys[$key] ?? 'workflow', $messages[0]);
            }

            return false;
        } catch (AuthorizationException) {
            $this->abandonWorkflow(__('monthly_reports.not_allowed'));

            return false;
        }

        Flux::toast(variant: 'success', text: $success instanceof Closure ? $success() : $success);

        if ($reload) {
            $this->forgetMonthlyReport();
            $this->seedFigures();
        }

        return true;
    }

    protected function abandonWorkflow(string $message): void
    {
        Flux::toast(variant: 'danger', text: $message);
        $this->showSubmitModal = $this->showValidateModal = $this->showReturnModal = false;
        $this->showReopenModal = $this->showDecisionModal = $this->showDeleteModal = false;
        $this->forgetMonthlyReport();
        $this->seedFigures();
    }
}
