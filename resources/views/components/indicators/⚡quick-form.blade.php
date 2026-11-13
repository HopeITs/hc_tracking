<?php

use App\Livewire\Concerns\Indicators\ManagesIndicatorForm;
use App\Models\Result;
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
    use ManagesIndicatorForm;

    #[On('add-indicator')]
    public function addForResult(int $resultId): void
    {
        $this->openCreateModal();

        if (Result::query()->whereKey($resultId)->exists()) {
            $this->indicatorResultId = (string) $resultId;
        }
    }

    #[On('edit-indicator')]
    public function editIndicator(int $indicatorId): void
    {
        $this->openEditModal($indicatorId);
    }

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
    @include('pages.indicators._index-form-modal')
</div>
