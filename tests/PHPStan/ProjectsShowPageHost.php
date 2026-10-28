<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\Projects\ArchivesProjects;
use App\Livewire\Concerns\Projects\ManagesProjectAssignment;
use App\Livewire\Concerns\Projects\ManagesProjectFiles;
use App\Livewire\Concerns\Projects\ManagesProjectForm;
use App\Livewire\Concerns\Projects\ManagesReportTemplate;
use App\Livewire\Concerns\Projects\ReadsProjectDelivery;
use App\Livewire\Concerns\Projects\ReadsProjectFunding;
use App\Livewire\Concerns\Projects\ReadsProjectStory;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Stands in for projects/show, with every trait that page gained when it was
 * split (its Gantt traits are analysed through ProjectPageHost).
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the project traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class ProjectsShowPageHost extends Component
{
    use ArchivesProjects;
    use ChecksFinanceVisibility;
    use ManagesProjectAssignment, ManagesProjectForm {
        ManagesProjectAssignment::refreshFromInbox insteadof ManagesProjectForm;
    }
    use ManagesProjectFiles;
    use ManagesReportTemplate;
    use ReadsProjectDelivery;
    use ReadsProjectFunding;
    use ReadsProjectStory;
    use WithFileUploads;

    public int $projectId;

    /** The page re-reads the project and its story here (it has no pager). */
    abstract protected function resetPage(): void;
}
