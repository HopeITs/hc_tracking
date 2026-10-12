<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\FundingAgreements;

use App\Actions\FundingAgreements\DeleteDonorReport;
use App\Actions\FundingAgreements\MarkDonorReportSubmitted;
use App\Actions\FundingAgreements\SaveDonorReport;
use App\Enums\DonorReportType;
use App\Models\DonorReport;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;

/**
 * The reports a donor expects under an agreement: the add/edit form, marking
 * one submitted, and the delete confirmation, each scoped to the agreement.
 *
 * Used by funding-agreements/show only. The page provides agreementId and
 * forgetReadings(), which drops its memoised readings after a write.
 *
 * @property int $agreementId
 */
trait ManagesDonorReports
{
    public bool $showReportModal = false;

    #[Locked]
    public ?int $editingReportId = null;

    public string $reportTitle = '';

    public string $reportType = 'narrative';

    public string $reportDueOn = '';

    public string $reportSubmittedOn = '';

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
        $this->reportSubmittedOn = $report->submitted_on?->toDateString() ?? '';
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
            'reportSubmittedOn' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'reportTitle.required' => __('funding_agreements.name_the_report'),
            'reportDueOn.required' => __('funding_agreements.enter_the_date_the_report_is_due'),
            'reportSubmittedOn.before_or_equal' => __('funding_agreements.a_report_cannot_be_submitted_in_the_future'),
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

    /**
     * Record that a report went out today.
     */
    public function markReportSubmitted(int $reportId): void
    {
        Gate::authorize('funding-agreements.update');

        app(MarkDonorReportSubmitted::class)->handle($this->scopedReport($reportId));

        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('funding_agreements.donor_report_marked_submitted'));
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    public function confirmReportDelete(int $reportId): void
    {
        Gate::authorize('funding-agreements.update');

        $this->deletingReportId = $this->scopedReport($reportId)->id;
        $this->showReportDeleteModal = true;
    }

    public function deleteReport(): void
    {
        Gate::authorize('funding-agreements.update');

        if ($this->deletingReportId !== null) {
            app(DeleteDonorReport::class)->handle($this->scopedReport($this->deletingReportId));
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
        $this->reportSubmittedOn = '';
    }

    abstract private function forgetReadings(): void;
}
