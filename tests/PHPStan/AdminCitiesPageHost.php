<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Admin\ManagesMealCoverage;
use Livewire\Component;

/**
 * Stands in for admin/cities so PHPStan analyses ManagesMealCoverage.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read. The trait reads the page's allCities() and mapCities()
 * computed values, declared on the trait. Never instantiated.
 */
abstract class AdminCitiesPageHost extends Component
{
    use ManagesMealCoverage;
}
