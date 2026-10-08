<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ChecksFinanceVisibility;
use Livewire\Component;

/**
 * Stands in for the calendar, donors, projects/show, proposals (index and
 * show), targets and timeline pages.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class FinanceVisibilityHost extends Component
{
    use ChecksFinanceVisibility;
}
