<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * The in-page file viewer on the proposal, report and task pages: open one
 * stored file, step to the previous or next, close.
 *
 * The page renders x-files.preview-modal and says which files the viewer
 * steps through (previewableFiles) and whether an id is one of them
 * (previewableFileId).
 */
trait PreviewsFiles
{
    public bool $showPreviewModal = false;

    #[Locked]
    public ?int $previewingFileId = null;

    /**
     * The files the viewer steps through, in the order the page lists them.
     *
     * @return Collection<int, Model>
     */
    abstract protected function previewableFiles(): Collection;

    /**
     * The id to open, or null when the id is not one of this page's files.
     */
    abstract protected function previewableFileId(int $fileId): ?int;

    public function openPreview(int $fileId): void
    {
        $this->previewingFileId = $this->previewableFileId($fileId);
        $this->showPreviewModal = $this->previewingFileId !== null;
    }

    /**
     * Step the open preview to the previous (-1) or next (1) file, wrapping
     * at either end.
     */
    public function previewAdjacent(int $direction): void
    {
        $ids = $this->previewableFiles()->pluck('id')->values();
        $position = $ids->search($this->previewingFileId);

        if ($position === false || $ids->count() < 2) {
            return;
        }

        $this->previewingFileId = $ids[($position + ($direction < 0 ? -1 : 1) + $ids->count()) % $ids->count()];
    }

    public function closePreview(): void
    {
        $this->showPreviewModal = false;
        $this->previewingFileId = null;
    }
}
