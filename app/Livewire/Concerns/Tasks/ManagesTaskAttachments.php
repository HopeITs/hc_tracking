<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Actions\Tasks\DeleteTaskAttachment;
use App\Actions\Tasks\SaveTaskAttachment;
use App\Enums\FileType;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Rules\SecureUpload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The attachments panel on tasks/show: the list, the upload / edit dialog
 * and the delete confirmation, every action re-scoped to this task.
 *
 * Used by tasks/show only. The page provides taskId and the #[Computed]
 * task(), and uses WithFileUploads for newFile. The page's previewableFiles()
 * and previewableFileId() (for PreviewsFiles) read attachments and
 * taskAttachment() from here.
 *
 * @property int $taskId
 * @property-read Task $task  #[Computed] task()
 * @property-read Collection<int, TaskAttachment> $attachments  #[Computed] attachments()
 */
trait ManagesTaskAttachments
{
    public bool $showFileModal = false;

    #[Locked]
    public ?int $editingFileId = null;

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public string $fileDisplayName = '';

    public string $fileDate = '';

    public bool $showDeleteFileModal = false;

    #[Locked]
    public ?int $deletingFileId = null;

    /** @return Collection<int, TaskAttachment> */
    #[Computed]
    public function attachments(): Collection
    {
        return TaskAttachment::query()
            ->select(['id', 'task_id', 'path', 'disk', 'original_name', 'size', 'date', 'type', 'uploaded_by'])
            ->with('uploader:id,hr_user_id')
            ->where('task_id', $this->taskId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    public function openCreateFileModal(): void
    {
        Gate::authorize('update', $this->task);

        $this->resetFileForm();
        $this->fileDate = now()->toDateString();
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $fileId): void
    {
        Gate::authorize('update', $this->task);

        $file = $this->taskAttachment($fileId);

        $this->resetFileForm();
        $this->editingFileId = $file->id;
        $this->fileDisplayName = $file->displayName();
        $this->fileDate = $file->date->toDateString();
        $this->showFileModal = true;
    }

    /**
     * Upload a new attachment, or rename, redate and optionally replace an
     * existing one — the same rules as a report's supporting files.
     */
    public function saveFile(): void
    {
        Gate::authorize('update', $this->task);

        $isCreate = $this->editingFileId === null;
        $allowedTypes = implode(',', FileType::values());

        $validated = $this->validate([
            'newFile' => [$isCreate ? 'required' : 'nullable', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
            'fileDisplayName' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
            'fileDate' => ['required', 'date'],
        ], [
            'newFile.required' => __('tasks.select_a_file_to_upload'),
            'fileDisplayName.required' => __('tasks.enter_a_file_name'),
            'fileDate.required' => __('tasks.set_the_file_date'),
        ]);

        app(SaveTaskAttachment::class)->handle(
            $this->task,
            $isCreate ? null : $this->taskAttachment($this->editingFileId),
            $this->newFile,
            $validated,
        );

        $this->closeFileModal();
        unset($this->attachments);

        Flux::toast(variant: 'success', text: $isCreate ? __('tasks.file_uploaded') : __('tasks.file_updated'));
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->resetFileForm();
    }

    protected function resetFileForm(): void
    {
        $this->resetValidation();
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileDisplayName = '';
        $this->fileDate = '';
    }

    public function confirmDeleteFile(int $fileId): void
    {
        Gate::authorize('update', $this->task);

        $this->deletingFileId = $this->taskAttachment($fileId)->id;
        $this->showDeleteFileModal = true;
    }

    /**
     * Delete an attachment's row and, for a real upload, its file on disk.
     */
    public function deleteFile(): void
    {
        Gate::authorize('update', $this->task);

        if ($this->deletingFileId === null) {
            $this->closeDeleteFileModal();

            return;
        }

        app(DeleteTaskAttachment::class)->handle($this->task, $this->taskAttachment($this->deletingFileId));

        $this->closeDeleteFileModal();
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('tasks.file_deleted'));
    }

    public function closeDeleteFileModal(): void
    {
        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
    }

    /**
     * The attachment behind a file action, scoped to this task.
     *
     * The id comes from the client, so without this scope a user could act
     * on another task's attachment by tampering with it (IDOR).
     */
    private function taskAttachment(int $fileId): TaskAttachment
    {
        return TaskAttachment::query()->where('task_id', $this->taskId)->findOrFail($fileId);
    }
}
