<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FileType;
use App\Models\Task;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        $contentType = match ($taskAttachment->type) {
            FileType::Pdf => 'application/pdf',
            FileType::Png => 'image/png',
            default => 'image/jpeg',
        };

        return Storage::disk($taskAttachment->disk)->response($taskAttachment->path, $taskAttachment->displayName(), [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
