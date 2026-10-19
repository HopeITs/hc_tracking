<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Actions\FundingAgreements\DeleteDonorReport;
use App\Actions\FundingAgreements\SaveDonorReport;
use App\Enums\DonorReportType;
use App\Livewire\Concerns\DonorReports\SubmitsDonorReport;
use App\Models\DonorReport;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;

/**
 * The reports a donor expects under an agreement: the add/edit form, the
 * shared submit dialog (SubmitsDonorReport: date + delivered file, D-21) and
 * the delete confirmation, each scoped to the agreement. A submitted report
 * is frozen: it is neither edited nor deleted.
 *
 * Used by funding-agreements/show only. The page provides agreementId,
 * WithFileUploads and forgetReadings(), which drops its memoised readings
 * after a write.
 *
 * @property int $agreementId
 */
trait ManagesDonorReports
{
    use SubmitsDonorReport;

    public bool $showReportModal = false;

    #[Locked]
    public ?int $editingReportId = null;

    public string $reportTitle = '';

    public string $reportType = 'narrative';

    public string $reportDueOn = '';

    public bool $showReportDeleteModal = false;

    #[Locked]
    public ?int $deletingReportId = null;

    public function openReportCreate(): void
    {
        Gate::authorize('funding-agreements.update');

        $this->resetReportForm();
        $this->showReportModal = true;
    }

    public function openReportEdit(int $reportId): void
    {
        Gate::authorize('funding-agreements.update');

        $report = $this->scopedReport($reportId);

        $this->resetValidation();
        $this->editingReportId = $report->id;
        $this->reportTitle = $report->title;
        $this->reportType = $report->type->value;
        $this->reportDueOn = $report->due_on->toDateString();
        $this->showReportModal = true;
    }

    public function saveReport(): void
    {
        Gate::authorize('funding-agreements.update');

        $this->reportTitle = trim($this->reportTitle);

        $validated = $this->validate([
            'reportTitle' => ['required', 'string', 'max:255'],
            'reportType' => ['required', new Enum(DonorReportType::class)],
            'reportDueOn' => ['required', 'date'],
        ], [
            'reportTitle.required' => __('funding_agreements.name_the_report'),
            'reportDueOn.required' => __('funding_agreements.enter_the_date_the_report_is_due'),
        ]);

        $report = app(SaveDonorReport::class)->handle(
            $this->agreementId,
            $this->editingReportId === null ? null : $this->scopedReport($this->editingReportId),
            $validated,
        );

        $isCreate = $report->wasRecentlyCreated;

        $this->closeReportModal();
        $this->forgetReadings();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('funding_agreements.donor_report_added') : __('funding_agreements.donor_report_updated'),
        );
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    public function confirmReportDelete(int $reportId): void
    {
        $report = $this->scopedReport($reportId);

        Gate::authorize('delete', $report);

        $this->deletingReportId = $report->id;
        $this->showReportDeleteModal = true;
    }

    public function deleteReport(): void
    {
        if ($this->deletingReportId !== null) {
            $report = $this->scopedReport($this->deletingReportId);

            Gate::authorize('delete', $report);

            app(DeleteDonorReport::class)->handle($report);
        }

        $this->closeReportDeleteModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('funding_agreements.donor_report_deleted'));
    }

    public function closeReportDeleteModal(): void
    {
        $this->showReportDeleteModal = false;
        $this->deletingReportId = null;
    }

    /**
     * A report by id, only if it belongs to this agreement.
     */
    private function scopedReport(int $reportId): DonorReport
    {
        return DonorReport::query()->where('donor_proposal_id', $this->agreementId)->findOrFail($reportId);
    }

    private function resetReportForm(): void
    {
        $this->resetValidation();
        $this->editingReportId = null;
        $this->reportTitle = '';
        $this->reportType = DonorReportType::Narrative->value;
        $this->reportDueOn = '';
    }

    protected function submittableReport(int $reportId): DonorReport
    {
        return $this->scopedReport($reportId);
    }

    protected function donorReportSubmitted(): void
    {
        $this->forgetReadings();
    }

    abstract private function forgetReadings(): void;
}
