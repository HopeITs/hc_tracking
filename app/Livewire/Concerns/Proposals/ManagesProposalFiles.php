<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\DeleteProposalFile;
use App\Actions\Proposals\SaveProposalFile;
use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Rules\SecureUpload;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The documents on proposals/show: the proposal's own files (upload, edit,
 * delete) and the grant paperwork already filed on its funded projects.
 *
 * Used by proposals/show only. The page provides proposalId and the
 * #[Computed] proposal() and tabs(); projects comes from
 * ReadsProposalDelivery and canViewAgreements() from
 * ChecksFinanceVisibility. The page also uses WithFileUploads for newFile.
 *
 * @property int $proposalId
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read array<int, array{key: string, label: string, count: int}> $tabs  #[Computed] tabs()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 * @property-read Collection<int, Media> $proposalFiles  #[Computed] proposalFiles()
 * @property-read Collection<int, Media> $projectFiles  #[Computed] projectFiles()
 * @property-read Collection<int, Media> $documents  #[Computed] documents()
 */
trait ManagesProposalFiles
{
    /** The file categories a funded project holds that belong to the grant's paperwork. */
    private const GRANT_PAPER = [MediaCollection::Proposal, MediaCollection::Agreement, MediaCollection::Mou];

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
     * MOUs are agreement data, so they need funding-agreements.view.
     *
     * @return list<string>
     */
    protected function hiddenCollections(): array
    {
        if ($this->canViewAgreements()) {
            return [];
        }

        return array_values(array_map(
            fn (MediaCollection $collection): string => $collection->value,
            array_filter(MediaCollection::cases(), fn (MediaCollection $collection): bool => $collection->isFundingPaper()),
        ));
    }

    /**
     * The grant paperwork categories this viewer may see on funded projects.
     *
     * @return list<MediaCollection>
     */
    protected function visibleGrantPaper(): array
    {
        return array_values(array_filter(
            self::GRANT_PAPER,
            fn (MediaCollection $collection): bool => ! in_array($collection->value, $this->hiddenCollections(), true),
        ));
    }

    /**
     * Files filed on the proposal itself.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function proposalFiles(): Collection
    {
        return Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'disk', 'path', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
            ->where('mediable_type', 'proposal')
            ->where('mediable_id', $this->proposalId)
            ->whereNotIn('collection', $this->hiddenCollections())
            ->with('uploader:id,hr_user_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The grant paperwork — proposal, agreement, MOU — already filed on the
     * projects this proposal funds. Shown read-only: each file belongs to its
     * project and is managed there.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function projectFiles(): Collection
    {
        $projectIds = $this->projects->modelKeys();

        if ($projectIds === []) {
            return new Collection;
        }

        $projects = $this->projects->keyBy('id');

        return Media::query()
            ->select(['id', 'mediable_type', 'mediable_id', 'collection', 'disk', 'path', 'original_name', 'type', 'size', 'uploaded_at', 'uploaded_by'])
            ->where('mediable_type', 'project')
            ->whereIn('mediable_id', $projectIds)
            ->whereIn('collection', array_map(fn (MediaCollection $collection): string => $collection->value, $this->visibleGrantPaper()))
            ->with('uploader:id,hr_user_id')
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Media $media) => $media->setRelation('mediable', $projects->get($media->mediable_id)));
    }

    /**
     * A proposal file by id, scoped to this proposal so a tampered id cannot
     * reach another owner's file.
     */
    private function proposalFile(int $mediaId): Media
    {
        $media = Media::query()
            ->where('mediable_type', 'proposal')
            ->where('mediable_id', $this->proposalId)
            ->findOrFail($mediaId);

        abort_if(in_array($media->collection->value, $this->hiddenCollections(), true), 403);

        return $media;
    }

    public function openCreateFileModal(): void
    {
        Gate::authorize('update', $this->proposal);

        $this->resetValidation();
        $this->editingFileId = null;
        $this->newFile = null;
        $this->fileCategory = '';
        $this->fileDisplayName = '';
        $this->showFileModal = true;
    }

    public function openEditFileModal(int $mediaId): void
    {
        Gate::authorize('update', $this->proposal);

        $media = $this->proposalFile($mediaId);

        $this->resetValidation();
        $this->editingFileId = $media->id;
        $this->newFile = null;
        $this->fileCategory = $media->collection->value;
        $this->fileDisplayName = $media->original_name;
        $this->showFileModal = true;
    }

    /**
     * Upload a new proposal file, or rename, recategorise and optionally
     * replace an existing one — the same rules as a report's supporting files.
     *
     * The type is checked twice, by detected MIME and by extension, so a file
     * renamed to look like a PDF is refused. A replacement removes the old
     * document from disk once the row points at the new one.
     */
    public function saveFile(): void
    {
        Gate::authorize('update', $this->proposal);

        $isCreate = $this->editingFileId === null;
        $allowedTypes = implode(',', FileType::values());

        $validated = $this->validate([
            'newFile' => [$isCreate ? 'required' : 'nullable', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
            'fileCategory' => ['required', Rule::enum(MediaCollection::class)],
            'fileDisplayName' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'newFile.required' => __('proposals.select_a_file_to_upload'),
            'fileCategory.required' => __('proposals.select_a_file_category'),
            'fileDisplayName.required' => __('proposals.enter_a_file_name'),
        ]);

        abort_if(in_array($validated['fileCategory'], $this->hiddenCollections(), true), 403);

        app(SaveProposalFile::class)->handle(
            $this->proposal,
            $isCreate ? null : $this->proposalFile($this->editingFileId),
            $this->newFile,
            $validated,
        );

        $this->closeFileModal();
        unset($this->proposalFiles, $this->documents, $this->tabs);

        Flux::toast(variant: 'success', text: $isCreate ? __('proposals.file_uploaded') : __('proposals.file_updated'));
    }

    public function confirmDeleteFile(int $mediaId): void
    {
        Gate::authorize('update', $this->proposal);

        $this->deletingFileId = $this->proposalFile($mediaId)->id;
        $this->confirmingFileDelete = true;
    }

    /**
     * Delete a file's row, then the stored document.
     *
     * The row goes first: a failed row delete leaves the document intact
     * behind a row that still opens it, never a row pointing at nothing.
     */
    public function deleteFile(): void
    {
        Gate::authorize('update', $this->proposal);

        if ($this->deletingFileId === null) {
            return;
        }

        app(DeleteProposalFile::class)->handle($this->proposal, $this->proposalFile($this->deletingFileId));

        $this->confirmingFileDelete = false;
        $this->deletingFileId = null;
        unset($this->proposalFiles, $this->documents, $this->tabs);

        Flux::toast(variant: 'success', text: __('proposals.file_deleted'));
    }

    /**
     * Every document the tab shows, in the order it shows them: the
     * proposal's own files, then the funded projects' grant paperwork. The
     * preview steps through this same list.
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function documents(): Collection
    {
        return $this->proposalFiles->concat($this->projectFiles)->values();
    }

    /**
     * Where a document downloads from: its own proposal route, or the route
     * of the project it is filed on.
     */
    protected function documentDownloadUrl(Media $media): string
    {
        return $media->mediable_type === 'proposal'
            ? route('proposals.files.download', [$this->proposal, $media])
            : route('projects.files.download', [Project::query()->findOrFail($media->mediable_id), $media]);
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
