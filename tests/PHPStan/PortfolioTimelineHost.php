<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\BuildsPortfolioGantt;
use Livewire\Component;

/**
 * Stands in for timeline/index and funding-agreements/show, the read-only portfolio Gantt.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class PortfolioTimelineHost extends Component
{
    use BuildsPortfolioGantt;
}
