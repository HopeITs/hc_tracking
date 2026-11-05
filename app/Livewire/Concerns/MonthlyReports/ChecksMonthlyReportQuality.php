<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\ReportTemplateQuestion;
use App\Support\Ai\MonthlyReportChecks;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * The smart check on monthly-reports/show: unusual figures and narrative
 * numbers that match no figure, shown while the month can still change
 * (draft) or is being validated (submitted). Advisory, never blocking.
 *
 * Used next to ReadsMonthlyReport, which provides report() and questions().
 *
 * @property-read MonthlyReport $report
 * @property-read Collection<int, ReportTemplateQuestion> $questions
 * @property-read list<array{kind: 'spike'|'drop'|'unmatched_number', text: string}> $qualityChecks
 */
trait ChecksMonthlyReportQuality
{
    /**
     * @return list<array{kind: 'spike'|'drop'|'unmatched_number', text: string}>
     */
    #[Computed]
    public function qualityChecks(): array
    {
        if ($this->report->status === MonthlyReportStatus::Validated) {
            return [];
        }

        return MonthlyReportChecks::for($this->report, $this->questions);
    }
}
