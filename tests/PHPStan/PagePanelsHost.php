<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Desk\ArrangesPanels;
use Livewire\Component;

/**
 * Stands in for pages::desk.index and components/desk/⚡page-panels, which
 * PHPStan cannot read. Never instantiated.
 */
abstract class PagePanelsHost extends Component
{
    use ArrangesPanels;
}
