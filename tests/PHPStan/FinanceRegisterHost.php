<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\FiltersFinanceRegister;
use Livewire\Component;

/**
 * Stands in for the finance registers: accounts, donors, funding agreements, proposals and transfers.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class FinanceRegisterHost extends Component
{
    use FiltersFinanceRegister;
}
