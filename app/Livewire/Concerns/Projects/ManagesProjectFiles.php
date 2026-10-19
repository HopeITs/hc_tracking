<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Projects;

use App\Actions\Projects\DeleteProjectFile;
use App\Actions\Projects\SaveProjectFile;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Project;
use App\Rules\SecureUpload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The files tab on projects/show: the category filter, the upload / edit
 * dialog and the delete confirmation, every action re-scoped to this project.
 *
 * Used by projects/show only. The page provides projectId and the #[Computed]
 * project() (whose media query reads hiddenCollections()), and uses
 * WithFileUploads for newFile; canViewAgreements() comes from
 * ChecksFinanceVisibility.
 *
 * @property int $projectId
 * @property-read Project $project  #[Computed] project()
 */
trait ManagesProjectFiles
{
    public string $fileCategoryFilter = 'all';

    public bool $showFileModal = false;

    public bool $confirmingFileDelete = false;

    #[Locked]
    public ?int $editingFileId = null;

    #[Locked]
    public ?int $deletingFileId = null;

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public string $fileCategory = '';

    public string $fileDisplayName = '';

    /**
     * The file collections this viewer may not see: signed agreements and
     * MOUs are agreement data, so they need funding-agreements.view; a donor
     * report's delivered file never belongs here.
     *
     * @return list<string>
     */
    protected function hiddenCollections(): array
    {
        $canViewAgreements = $this->canViewAgreements();

        return array_values(array_map(
            fn (MediaCollection $collection): string => $collection->value,
            array_filter(MediaCollection::cases(), fn (MediaCollection $collection): bool => $collection->isDonorReportOnly()
                || (! $canViewAgreements && $collection->isFundingPaper())),
        ));
    }

    /**
     * File category counts for the files tab's segmented filter, including
     * an "all" bucket first and only the categories actually present after
     * it — an empty category never earns a filter chip.
     *
     * @return array<int, array{value: string, label: string, count: int}>
     */
    #[Computed]
    public function fileCategoryCounts(): array
    {
        $files = $this->project->media;

        $counts = [[
            'value' => 'all',
            'label' => __('projects.all_files'),
            'count' => $files->count(),
        ]];

        foreach (MediaCollection::cases() as $collection) {
            $count = $files->filter(fn (Media $media): bool => $media->collection === $collection)->count();

            if ($count > 0) {
                $counts[] = ['value' => $collection->value, 'label' => $collection->label(), 'count' => $count];
            }
        }

        return $counts;
    }

    /**
     * This project's files narrowed to the selected category filter, still
     * newest first.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function filteredProjectFiles(): Collection
    {
        if ($this->fileCategoryFilter === 'all') {
            return $this->project->media;
        }

        return $this->project->media
            ->filter(fn (Media $media): bool => $media->collection->value === $this->fileCategoryFilter)
            ->values();
    }

    /**
     * The media row behind a file-tab action, scoped to this project.
     *
     * Every method below takes a media id from the client — a clicked
     * download/edit/delete button — so it must be re-scoped to this project
     * rather than trusting a bare findOrFail(). Without this guard a user
     * could act on another project's file by tampering with the id (IDOR).
     */
    private function projectFile(int $mediaId): Media
    {
        $media = Media::query()
            ->where('mediable_type', 'project')
            ->where('mediable_id', $this->projectId)
            ->findOrFail($mediaId);

        abort_if(in_array($media->collection->value, $this->hiddenCollections(), true), 403);

        return $media;
    }

    public function openCreateFileModal(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $this->resetValidation();
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $mediaId): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $media = $this->projectFile($mediaId);

        $this->resetValidation();
        $this->editingFileId = $media->id;
        $this->newFile = null;
        $this->fileCategory = $media->collection->value;
        $this->fileDisplayName = $media->original_name;
        $this->showFileModal = true;
    }

    /**
     * Create a new project file or update an existing one's metadata.
     *
     * Create validates and stores the upload itself; edit changes only the
     * category and display name — swapping the underlying document is
     * delete + re-upload, not an in-place replace.
     */
    public function saveFile(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        if ($this->editingFileId === null) {
            $allowedTypes = implode(',', FileType::values());

            $validated = $this->validate([
                'newFile' => ['required', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
                'fileCategory' => ['required', Rule::enum(MediaCollection::class)],
                'fileDisplayName' => ['nullable', 'string', 'max:255'],
            ], [
                'newFile.required' => __('projects.select_a_file_to_upload'),
                'fileCategory.required' => __('projects.select_a_file_category'),
            ]);

            abort_if(in_array($validated['fileCategory'], $this->hiddenCollections(), true), 403);

            app(SaveProjectFile::class)->handle(Project::query()->findOrFail($this->projectId), null, $this->newFile, $validated);

            $toast = __('projects.file_uploaded');
        } else {
            $media = $this->projectFile($this->editingFileId);

            $validated = $this->validate([
                'fileCategory' => ['required', Rule::enum(MediaCollection::class)],
                'fileDisplayName' => ['required', 'string', 'max:255'],
            ], [
                'fileCategory.required' => __('projects.select_a_file_category'),
            ]);

            abort_if(in_array($validated['fileCategory'], $this->hiddenCollections(), true), 403);

            app(SaveProjectFile::class)->handle(Project::query()->findOrFail($this->projectId), $media, null, $validated);

            $toast = __('projects.file_updated');
        }

        $this->closeFileModal();
        unset($this->project, $this->fileCategoryCounts, $this->filteredProjectFiles);

        Flux::toast(variant: 'success', text: $toast);
    }

    public function confirmDeleteFile(int $mediaId): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $media = $this->projectFile($mediaId);

        $this->deletingFileId = $media->id;
        $this->confirmingFileDelete = true;
    }

    /**
     * Delete a file's row and its underlying disk file.
     *
     * A storage I/O failure is logged, not surfaced — the record is removed
     * regardless so it doesn't linger stuck in the UI over a storage hiccup.
     */
    public function deleteFile(): void
    {
        Gate::authorize('update', Project::query()->findOrFail($this->projectId));

        $media = $this->projectFile($this->deletingFileId);

        app(DeleteProjectFile::class)->handle(Project::query()->findOrFail($this->projectId), $media);

        $this->confirmingFileDelete = false;
        $this->deletingFileId = null;
        unset($this->project, $this->fileCategoryCounts, $this->filteredProjectFiles);

        Flux::toast(variant: 'success', text: __('projects.file_deleted'));
    }

    public function closeFileModal(): void
    {
        $this->showFileModal = false;
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->resetValidation();
    }
}
