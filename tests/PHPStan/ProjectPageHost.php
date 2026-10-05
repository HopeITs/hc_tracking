<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\BuildsProjectGantt;
use App\Livewire\Concerns\EditsProjectGantt;
use Livewire\Component;

/**
 * Stands in for projects/show, the editable project Gantt (BuildsProjectGantt brings in SchedulesProjectGantt).
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class ProjectPageHost extends Component
{
    use BuildsProjectGantt;
    use EditsProjectGantt;
}
