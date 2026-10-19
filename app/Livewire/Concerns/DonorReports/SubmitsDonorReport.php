<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\DonorReports;

use App\Actions\FundingAgreements\MarkDonorReportSubmitted;
use App\Enums\FileType;
use App\Enums\ReportAudience;
use App\Models\DonorReport;
use App\Models\User;
use App\Rules\SecureUpload;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The one "Mark as submitted" dialog (contracts §14.3, D-21): the submission
 * date and the delivered file, sent through MarkDonorReportSubmitted. Used by
 * funding-agreements/show, donor-reports/show and reporting-duties/index.
 *
 * The page uses WithFileUploads, scopes the report id (submittableReport())
 * and refreshes its readings after a submission (donorReportSubmitted()).
 */
trait SubmitsDonorReport
{
    public bool $showSubmitModal = false;

    #[Locked]
    public ?int $submittingReportId = null;

    #[Locked]
    public bool $submissionNeedsFile = true;

    #[Locked]
    public int $submissionFileCount = 0;

    public string $submittedOn = '';

    /** @var TemporaryUploadedFile|null */
    public $submissionFile = null;

    public function openSubmit(int $reportId): void
    {
        $report = $this->submittableReport($reportId);

        Gate::authorize('submit', $report);

        $this->resetValidation();
        $this->submittingReportId = $report->id;
        $this->submissionNeedsFile = $report->audience === ReportAudience::Donor;
        $this->submissionFileCount = $report->media()->count();
        $this->submittedOn = CarbonImmutable::today()->toDateString();
        $this->submissionFile = null;
        $this->showSubmitModal = true;
    }

    public function submitReport(): void
    {
        if ($this->submittingReportId === null) {
            return;
        }

        $report = $this->submittableReport($this->submittingReportId);

        Gate::authorize('submit', $report);

        $allowedTypes = implode(',', FileType::values());

        $this->validate([
            'submittedOn' => ['required', 'date', 'before_or_equal:today'],
            'submissionFile' => ['nullable', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ], [
            'submittedOn.before_or_equal' => __('donor_reports.submitted_in_future'),
        ]);

        /** @var User $user */
        $user = auth()->user();

        app(MarkDonorReportSubmitted::class)->handle(
            $report,
            $this->submittedOn,
            $this->submissionFile === null ? [] : [$this->submissionFile],
            $user,
        );

        $this->closeSubmitModal();
        $this->donorReportSubmitted();

        Flux::toast(variant: 'success', text: __('donor_reports.submitted_toast'));
    }

    public function closeSubmitModal(): void
    {
        $this->showSubmitModal = false;
        $this->submittingReportId = null;
        $this->submissionFile = null;
        $this->submittedOn = '';
    }

    /**
     * A report by id, only within what this page shows.
     */
    abstract protected function submittableReport(int $reportId): DonorReport;

    /**
     * Drop the page's memoised readings after a submission.
     */
    abstract protected function donorReportSubmitted(): void;
}
