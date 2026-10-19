<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\DonorReports\SubmitsDonorReport;
use App\Models\DonorReport;
use Livewire\Component;

/**
 * Stands in for donor-reports/show and reporting-duties/index, which use the
 * shared submit dialog (funding-agreements/show reaches it through
 * ManagesDonorReports).
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class DonorReportSubmitHost extends Component
{
    use SubmitsDonorReport;

    protected function submittableReport(int $reportId): DonorReport
    {
        return DonorReport::query()->findOrFail($reportId);
    }

    protected function donorReportSubmitted(): void {}
}
