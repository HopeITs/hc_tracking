<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\SavesTaskForm;
use Livewire\Component;

/**
 * Stands in for calendar/index and tasks/index, which PHPStan cannot read.
 * Never instantiated.
 */
abstract class TaskFormHost extends Component
{
    use SavesTaskForm;

    public ?int $editingTaskId = null;

    public string $title = '';

    public string $description = '';

    public string $taskStatus = 'todo';

    public string $taskPriority = 'medium';

    public string $startDate = '';

    public string $dueDate = '';

    public string $completedDate = '';

    public string $progressPercentage = '0';

    /** @var array<int, string> */
    public array $assignedTo = [];

    public string $taskSourceType = 'proposal';

    public string $taskSourceId = '';
}
