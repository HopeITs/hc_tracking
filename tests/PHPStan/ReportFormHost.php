<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ManagesReportForm;
use Livewire\Component;

/**
 * Stands in for reports/index and reports/show.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class ReportFormHost extends Component
{
    use ManagesReportForm;
}
