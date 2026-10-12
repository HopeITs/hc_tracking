<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Programs\ReadsProgramDelivery;
use Livewire\Component;

/**
 * Stands in for programs/show, with the trait that page gained when it was
 * split.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed. It
 * declares the page's own members the trait reads. Never instantiated.
 */
abstract class ProgramsShowPageHost extends Component
{
    use ReadsProgramDelivery;

    public int $programId;
}
