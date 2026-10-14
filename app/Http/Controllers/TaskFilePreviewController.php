<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FileType;
use App\Exceptions\UnreadableStoredFile;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Support\StoredFiles;
use App\Support\UploadName;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskFilePreviewController extends Controller
{
    /**
     * Stream an attachment inline so the task page can show it in its
     * preview modal.
     *
     * Same task-scoped IDOR guard as the download route. Only PDFs and
     * images are served inline; the Content-Type comes from the validated
     * FileType rather than the stored client MIME, and nosniff stops the
     * browser from reinterpreting the body as anything else.
     */
    public function __invoke(Task $task, TaskAttachment $taskAttachment): StreamedResponse
    {
        Gate::authorize('view', $task);

        abort_unless($taskAttachment->task_id === $task->id, 404);
        abort_unless($taskAttachment->isPreviewable() && $taskAttachment->isStored(), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, max-age=300',
        ];

        // A sandboxed image opened in its own tab can never act as the app.
        // PDFs are left out: Chrome refuses to render a PDF in a sandboxed
        // document, and its viewer already isolates the file's scripts.
        if ($taskAttachment->type !== FileType::Pdf) {
            $headers['Content-Security-Policy'] = 'sandbox';
        }

        try {
            return StoredFiles::stream($taskAttachment->disk, $taskAttachment->path, $taskAttachment->encryption_key_id, UploadName::clean($taskAttachment->displayName()), $taskAttachment->type, $headers, 'inline');
        } catch (UnreadableStoredFile $exception) {
            throw $exception->forRow('task_attachments', $taskAttachment->id);
        }
    }
}
