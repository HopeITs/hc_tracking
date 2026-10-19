<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\MonthlyReports\EditsMonthlyReportLines;
use App\Livewire\Concerns\MonthlyReports\ReadsMonthlyReport;
use App\Livewire\Concerns\MonthlyReports\RunsMonthlyReportWorkflow;
use Livewire\Component;

/**
 * Stands in for monthly-reports/show.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the traits are never analysed.
 * Never instantiated.
 */
abstract class MonthlyReportShowPageHost extends Component
{
    use EditsMonthlyReportLines;
    use ReadsMonthlyReport;
    use RunsMonthlyReportWorkflow;
}
