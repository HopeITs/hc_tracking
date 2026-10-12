<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Reports\ManagesReportFiles;
use App\Livewire\Concerns\Reports\RunsReportWorkflow;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Stands in for reports/show, with every trait that page gained when it was
 * split (its ManagesReportForm and PreviewsFiles traits are analysed through
 * ReportFormHost and FilePreviewHost).
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the report traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class ReportsShowPageHost extends Component
{
    use ManagesReportFiles;
    use RunsReportWorkflow;
    use WithFileUploads;

    public int $reportId;
}
