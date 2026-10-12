<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Reports;

use App\Actions\Reports\DeleteReportFile;
use App\Actions\Reports\SaveReportFile;
use App\Enums\FileType;
use App\Models\Report;
use App\Models\ReportFile;
use App\Rules\SecureUpload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The supporting-files panel on reports/show: the list, the upload / edit
 * dialog and the delete confirmation, every action re-scoped to this report
 * and locked once the report is no longer a draft.
 *
 * Used by reports/show only. The page provides reportId and the #[Computed]
 * report(), and uses WithFileUploads for newFile. The page's previewableFiles()
 * and previewableFileId() (for PreviewsFiles) read files and reportFile() from
 * here.
 *
 * @property int $reportId
 * @property-read Report $report  #[Computed] report()
 * @property-read Collection<int, ReportFile> $files  #[Computed] files()
 */
trait ManagesReportFiles
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

    /** @return Collection<int, ReportFile> */
    #[Computed]
    public function files(): Collection
    {
        return ReportFile::query()
            ->select(['id', 'report_id', 'path', 'disk', 'original_name', 'size', 'date', 'type', 'uploaded_by'])
            ->with('uploader:id,hr_user_id')
            ->where('report_id', $this->reportId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    public function openCreateFileModal(): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        Gate::authorize('update', $this->report);

        $this->resetFileForm();
        $this->fileDate = now()->toDateString();
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $fileId): void
    {
        if (! $this->report->isEditable()) {
            return;
        }

        Gate::authorize('update', $this->report);

        $file = $this->reportFile($fileId);

        $this->resetFileForm();
        $this->editingFileId = $file->id;
        $this->fileDisplayName = $file->displayName();
        $this->fileDate = $file->date->toDateString();
        $this->showFileModal = true;
    }

    /**
     * Upload a new supporting file, or update an existing one.
     *
     * Only available while the report itself is editable: a file backs up the
     * report's figure, so it follows the same draft-only lock. Editing changes
     * the display name and document date, and may swap in a replacement file —
     * the old one is then removed from disk.
     */
    public function saveFile(): void
    {
        if (! $this->report->isEditable()) {
            $this->closeFileModal();

            return;
        }

        Gate::authorize('update', $this->report);

        $isCreate = $this->editingFileId === null;
        $allowedTypes = implode(',', FileType::values());

        $validated = $this->validate([
            'newFile' => [$isCreate ? 'required' : 'nullable', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
            'fileDisplayName' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
            'fileDate' => ['required', 'date'],
        ], [
            'newFile.required' => __('reports.select_a_file_to_upload'),
            'fileDisplayName.required' => __('reports.enter_a_file_name'),
            'fileDate.required' => __('reports.set_the_file_date'),
        ]);

        app(SaveReportFile::class)->handle(
            $this->report,
            $isCreate ? null : $this->reportFile($this->editingFileId),
            $this->newFile,
            $validated,
        );

        $this->closeFileModal();
        unset($this->files);

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('reports.file_uploaded') : __('reports.file_updated'),
        );
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
        if (! $this->report->isEditable()) {
            return;
        }

        Gate::authorize('update', $this->report);

        $this->deletingFileId = $this->reportFile($fileId)->id;
        $this->showDeleteFileModal = true;
    }

    /**
     * Delete a file's row and, for a real upload, its file on disk.
     */
    public function deleteFile(): void
    {
        if ($this->deletingFileId === null || ! $this->report->isEditable()) {
            $this->closeDeleteFileModal();

            return;
        }

        Gate::authorize('update', $this->report);

        app(DeleteReportFile::class)->handle($this->report, $this->reportFile($this->deletingFileId));

        $this->closeDeleteFileModal();
        unset($this->files);

        Flux::toast(variant: 'success', text: __('reports.file_deleted'));
    }

    public function closeDeleteFileModal(): void
    {
        $this->showDeleteFileModal = false;
        $this->deletingFileId = null;
    }

    /**
     * The file behind a file action, scoped to this report.
     *
     * The id comes from the client, so without this scope a user could act on
     * another report's file by tampering with it (IDOR).
     */
    private function reportFile(int $fileId): ReportFile
    {
        return ReportFile::query()->where('report_id', $this->reportId)->findOrFail($fileId);
    }
}
