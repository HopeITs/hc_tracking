<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\AnnualPlans\ReadsAnnualPlanSummary;
use Livewire\Component;

/**
 * Stands in for annual-plans/show, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the annual-plan summary trait is
 * never analysed. It declares the page's own members the trait reads. Never
 * instantiated.
 */
abstract class AnnualPlansShowPageHost extends Component
{
    use ReadsAnnualPlanSummary;

    public int $annualPlanId;
}
