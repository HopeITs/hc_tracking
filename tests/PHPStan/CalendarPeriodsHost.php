<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\NavigatesCalendarPeriods;
use Livewire\Component;

/**
 * Stands in for calendar/index, milestones/index and tasks/index, which
 * PHPStan cannot read. Never instantiated.
 */
abstract class CalendarPeriodsHost extends Component
{
    use NavigatesCalendarPeriods;

    public string $month = '';

    public string $anchorDate = '';

    public string $viewMode = 'month';
}
