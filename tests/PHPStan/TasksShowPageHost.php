<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Tasks\ManagesSubtasks;
use App\Livewire\Concerns\Tasks\ManagesTaskAttachments;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Stands in for tasks/show, with every trait that page gained when it was
 * split (its PreviewsFiles trait is analysed through FilePreviewHost).
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the task traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class TasksShowPageHost extends Component
{
    use ManagesSubtasks;
    use ManagesTaskAttachments;
    use WithFileUploads;

    public int $taskId;
}
