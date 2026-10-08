<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ExecutiveSummary\LoadsSummarySources;
use App\Livewire\Concerns\ExecutiveSummary\ReadsSummaryCoverage;
use App\Livewire\Concerns\ExecutiveSummary\ReadsSummaryLedger;
use Livewire\Component;

/**
 * Stands in for executive-summary/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the executive-summary traits are
 * never analysed. The page's own members the traits read ($openRow and the
 * #[Computed] fiscalYear / figures / priorFigures) are declared on the traits
 * with @property / @property-read. Never instantiated.
 */
abstract class ExecutiveSummaryIndexPageHost extends Component
{
    use LoadsSummarySources;
    use ReadsSummaryCoverage;
    use ReadsSummaryLedger;
}
