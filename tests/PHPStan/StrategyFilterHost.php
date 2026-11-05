<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\FiltersByStrategy;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for the registers that can be narrowed to one strategy (risks,
 * indicators, milestones, reports, funding agreements), which are single-file
 * Livewire pages PHPStan cannot read. Never instantiated.
 */
abstract class StrategyFilterHost extends Component
{
    use FiltersByStrategy;
    use WithPagination;
}
