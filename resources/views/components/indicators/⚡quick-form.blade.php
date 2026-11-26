<?php

use App\Livewire\Concerns\Indicators\ManagesIndicatorForm;
use App\Livewire\Concerns\IndicatorLibrary\ManagesLibraryEntryForm;
use App\Models\Result;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The indicator form of indicators/index, opened in place on a results
 * framework (results/index and the proposal Logical Framework tab), so adding
 * or editing an indicator never leaves the page (MEAL manager request,
 * 2026-11-13).
 *
 * x-ui.framework-node dispatches `add-indicator` (resultId) and
 * `edit-indicator` (indicatorId); after a write this component dispatches
 * `indicator-saved`, which ManagesResultForm answers by re-reading the
 * framework. The permissions are the form's own (indicators.create/update).
 */
new class extends Component {
    use ManagesIndicatorForm, ManagesLibraryEntryForm {
        ManagesLibraryEntryForm::openEditModal insteadof ManagesIndicatorForm;
        ManagesIndicatorForm::openEditModal as private openInstanceEditor;
        ManagesIndicatorForm::confirmDelete insteadof ManagesLibraryEntryForm;
        ManagesIndicatorForm::cancelDelete insteadof ManagesLibraryEntryForm;
    }

    #[Locked]
    public ?int $proposalId = null;

    public function mount(?int $proposalId = null, ?int $initialResultId = null, bool $openOnLoad = false, ?int $initialLocationId = null): void
    {
        $this->proposalId = $proposalId;

        if ($openOnLoad && auth()->user()?->can('indicators.create')) {
            $result = $initialResultId === null ? null : Result::query()->findOrFail($initialResultId);
            $this->openEntryForResult($result, $proposalId, $initialLocationId);
        }
    }

    #[On('add-indicator')]
    public function addForResult(?int $resultId = null): void
    {
        $result = $resultId === null ? null : Result::query()
            ->when($this->proposalId !== null, fn ($query) => $query->proposalFramework($this->proposalId))
            ->findOrFail($resultId);
        $this->openEntryForResult($result, $this->proposalId);
    }

    #[On('edit-indicator')]
    public function editIndicator(int $indicatorId): void
    {
        $this->openInstanceEditor($indicatorId);
    }

    #[On('edit-library-entry')]
    public function editLibraryEntry(int $libraryIndicatorId): void
    {
        if ($this->proposalId !== null) {
            abort_unless(\App\Models\Indicator::query()->where('library_indicator_id', $libraryIndicatorId)
                ->whereHas('result', fn ($query) => $query->proposalFramework($this->proposalId))->exists(), 404);
        }
        $this->openEditModal($libraryIndicatorId);
    }

    protected function indicatorFormProposalId(): ?int
    {
        return $this->proposalId;
    }

    protected function forgetLibrary(): void {}

    /**
     * There is no list here to refresh: the framework page re-reads itself.
     */
    protected function forgetIndicators(): void
    {
        $this->dispatch('indicator-saved');
    }

    /**
     * The form resets the directory's pager after a write; this page has none.
     */
    protected function resetPage(): void {}
};
?>

<div>
    @include('pages.indicator-library._index-entry-modal')
    @include('pages.indicators._index-form-modal')
</div>
