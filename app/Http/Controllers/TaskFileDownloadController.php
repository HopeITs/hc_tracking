<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskFileDownloadController extends Controller
{
    /**
     * Stream a task's attached file to the browser.
     *
     * The attachment must belong to the task in the URL — the IDOR guard,
     * same as ReportFileDownloadController. Reference-only rows with nothing
     * on disk 404 rather than 500.
     */
    public function __invoke(Task $task, TaskAttachment $taskAttachment): StreamedResponse
    {
        Gate::authorize('view', $task);

        abort_unless($taskAttachment->task_id === $task->id, 404);
        abort_unless($taskAttachment->isStored(), 404);

        return Storage::disk($taskAttachment->disk)->download($taskAttachment->path, $taskAttachment->displayName(), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
