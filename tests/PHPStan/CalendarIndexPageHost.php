<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Calendar\BuildsCalendarGrid;
use App\Livewire\Concerns\Calendar\FiltersCalendarTypes;
use App\Livewire\Concerns\Calendar\FindsFocusedTypeDates;
use App\Livewire\Concerns\Calendar\ManagesCalendarMilestones;
use App\Livewire\Concerns\Calendar\ManagesCalendarTasks;
use App\Livewire\Concerns\Calendar\PresentsCalendarItems;
use App\Livewire\Concerns\Calendar\ScopesCalendarPeople;
use App\Livewire\Concerns\Calendar\ShowsCalendarDay;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\NavigatesCalendarPeriods;
use Livewire\Component;

/**
 * Stands in for calendar/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the calendar traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class CalendarIndexPageHost extends Component
{
    use BuildsCalendarGrid;
    use ChecksFinanceVisibility;
    use FiltersCalendarTypes;
    use FindsFocusedTypeDates;
    use ManagesCalendarMilestones;
    use ManagesCalendarTasks;
    use NavigatesCalendarPeriods;
    use PresentsCalendarItems;
    use ScopesCalendarPeople;
    use ShowsCalendarDay;

    public string $month = '';

    public string $anchorDate = '';

    public string $viewMode = 'month';

    /** @var array<int, string> */
    public array $filterEmployeeIds = [];
}
