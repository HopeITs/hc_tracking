<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Support\StoredFiles;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a task attachment's row and, for a real upload, its file on disk.
 */
class DeleteTaskAttachment
{
    public function handle(Task $task, TaskAttachment $file): void
    {
        Gate::authorize('update', $task);

        if ($file->original_name !== null) {
            StoredFiles::forget($file->disk, $file->path, 'task attachment', ['task_attachment_id' => $file->id]);
        }

        $file->delete();
    }
}
