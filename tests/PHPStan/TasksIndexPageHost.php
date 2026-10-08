<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ManagesTaskVisibility;
use App\Livewire\Concerns\NavigatesCalendarPeriods;
use App\Livewire\Concerns\Tasks\ComposesInlineTasks;
use App\Livewire\Concerns\Tasks\FiltersTaskDirectory;
use App\Livewire\Concerns\Tasks\ManagesTaskDrawer;
use App\Livewire\Concerns\Tasks\ProvidesTaskOptions;
use App\Livewire\Concerns\Tasks\ReadsTaskViews;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for tasks/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the task traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class TasksIndexPageHost extends Component
{
    use ComposesInlineTasks;
    use FiltersTaskDirectory;
    use ManagesTaskDrawer;
    use ManagesTaskVisibility;
    use NavigatesCalendarPeriods;
    use ProvidesTaskOptions;
    use ReadsTaskViews;
    use WithPagination;

    public string $month = '';

    public string $anchorDate = '';
}
