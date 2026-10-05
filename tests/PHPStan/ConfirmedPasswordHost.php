<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\RequiresConfirmedPassword;
use Livewire\Component;

/**
 * Stands in for the security settings pages.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class ConfirmedPasswordHost extends Component
{
    use RequiresConfirmedPassword;
}
