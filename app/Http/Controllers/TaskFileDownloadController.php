<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\UnreadableStoredFile;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
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

        try {
            return StoredFiles::stream($taskAttachment->disk, $taskAttachment->path, $taskAttachment->encryption_key_id, UploadName::clean($taskAttachment->displayName()), $taskAttachment->type, [
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('task_attachments', $taskAttachment->id);
        }
    }
}
