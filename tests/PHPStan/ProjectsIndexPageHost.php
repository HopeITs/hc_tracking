<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Projects\ArchivesProjects;
use App\Livewire\Concerns\Projects\FiltersProjectDirectory;
use App\Livewire\Concerns\Projects\ManagesProjectForm;
use App\Livewire\Concerns\Projects\PresentsProjectDirectory;
use App\Livewire\Concerns\Projects\SummarisesProjectFunding;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for projects/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the project traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class ProjectsIndexPageHost extends Component
{
    use ArchivesProjects;
    use FiltersProjectDirectory;
    use ManagesProjectForm;
    use PresentsProjectDirectory;
    use SummarisesProjectFunding;
    use WithPagination;
}
