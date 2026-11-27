<?php

use App\Livewire\Concerns\Targets\ManagesTargetForm;
use App\Livewire\Concerns\Targets\NarrowsTargetFormOptions;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Proposal;
use App\Models\Result;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    use ManagesTargetForm {
        openCreateModal as protected openTargetForm;
        saveTarget as protected persistTarget;
    }
    use NarrowsTargetFormOptions;

    #[Locked]
    public ?int $proposalId = null;

    public function mount(?int $proposalId = null, bool $openOnLoad = false, ?int $initialIndicatorId = null): void
    {
        $this->proposalId = $proposalId;

        if ($openOnLoad && Gate::allows('targets.create')) {
            $this->openCreateModal($initialIndicatorId);
        }
    }

    #[On('commit-framework-target')]
    public function openCreateModal(?int $indicatorId = null): void
    {
        Gate::authorize('targets.create');

        if ($indicatorId !== null) {
            $this->targetIndicators()->findOrFail($indicatorId);
        }

        $this->openTargetForm();
        $this->targetIndicatorId = (string) $indicatorId;
        if ($this->proposalId !== null) {
            $this->targetSourceType = 'proposal';
            $this->targetSourceId = (string) $this->proposalId;
        }
        $this->updatedTargetIndicatorId();
    }

    #[On('edit-framework-target')]
    public function editFrameworkTarget(int $targetId): void
    {
        $this->openEditModal($targetId);
    }

    #[On('delete-framework-target')]
    public function deleteFrameworkTarget(int $targetId): void
    {
        Gate::authorize('targets.delete');
        $this->editableTargets()->findOrFail($targetId);
        $this->confirmDelete($targetId);
    }

    public function updatedTargetSourceType(): void
    {
        $this->targetSourceId = '';
        unset($this->sourceOptions);
        $this->syncFormLocation();
    }

    /** @return Builder<Indicator> */
    protected function targetIndicators(): Builder
    {
        $results = Result::query();
        if ($this->proposalId === null) {
            $results->programFramework();
        } else {
            Gate::authorize('view', Proposal::query()->findOrFail($this->proposalId));
            $results->where('proposal_id', $this->proposalId);
        }

        return Indicator::query()->whereIn('result_id', $results->select('id'));
    }

    /** @return Builder<PlanIndicator> */
    protected function editableTargets(): Builder
    {
        return PlanIndicator::query()
            ->whereIn('indicator_id', $this->targetIndicators()->select('id'))
            ->when($this->proposalId !== null, fn (Builder $query) => $query
                ->where('source_type', 'proposal')->where('source_id', $this->proposalId));
    }

    public function saveTarget(): void
    {
        if ($this->proposalId !== null) {
            $this->targetSourceType = 'proposal';
            $this->targetSourceId = (string) $this->proposalId;
        }

        $this->persistTarget();
    }

    protected function resetPage(): void
    {
        $this->dispatch('framework-target-saved');
    }
};
?>

<div>
    @include('pages.targets._index-target-modal', ['sourceTypeLabels' => ['annual_plan' => __('common.annual_plan'), 'proposal' => __('common.proposal')]])
    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('targets.delete_target')"
        :message="__('targets.delete_target')"
        confirm="deleteTarget"
        :confirm-label="__('common.delete')"
    />
</div>
