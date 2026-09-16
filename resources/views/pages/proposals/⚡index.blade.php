<?php

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.proposals')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    public bool $showProposalModal = false;
    public ?int $editingProposalId = null;
    public ?int $deletingProposalId = null;

    public string $name = '';
    public string $plannedDateBegin = '';
    public string $plannedDateEnd = '';
    public string $actualDateBegin = '';
    public string $actualDateEnd = '';
    public string $proposalStatus = 'concept note';
    public string $notes = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPhase(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->phase = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Proposal> */
    #[Computed]
    public function proposals(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'updated_at'])
            ->withCount(['donorProposals', 'projects', 'tasks', 'statusNotes', 'planIndicators'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%');
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            // There is no phase column: a phase is a set of statuses, derived
            // from the same match that ProposalStatus::phase() uses.
            ->when(
                $this->phase !== '',
                fn ($query) => $query->whereIn('status', ProposalPhase::from($this->phase)->statuses())
            )
            ->orderByDesc('planned_date_begin')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Whether this proposal may be deleted.
     *
     * donor_proposal.proposal_id RESTRICTs on delete, deliberately: without it
     * one proposal delete would reach through the agreement into
     * transfers_tracking and erase every record of money received. The UI
     * refuses first so the user gets a reason instead of a query exception.
     */
    public function canDelete(int $proposalId): bool
    {
        $proposal = Proposal::query()
            ->select(['id'])
            ->withCount('donorProposals')
            ->find($proposalId);

        return $proposal !== null && $proposal->donor_proposals_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetProposalForm();
        $this->showProposalModal = true;
    }

    public function openEditModal(int $proposalId): void
    {
        $proposal = Proposal::query()
            ->select(['id', 'name', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'status', 'notes'])
            ->findOrFail($proposalId);

        $this->resetValidation();
        $this->editingProposalId = $proposal->id;
        $this->name = $proposal->name;
        $this->plannedDateBegin = $proposal->planned_date_begin?->toDateString() ?? '';
        $this->plannedDateEnd = $proposal->planned_date_end?->toDateString() ?? '';
        $this->actualDateBegin = $proposal->actual_date_begin?->toDateString() ?? '';
        $this->actualDateEnd = $proposal->actual_date_end?->toDateString() ?? '';
        $this->proposalStatus = $proposal->status->value;
        $this->notes = $proposal->notes ?? '';
        $this->showProposalModal = true;
    }

    public function saveProposal(): void
    {
        $this->name = trim($this->name);
        $this->notes = trim($this->notes);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'plannedDateBegin' => ['required', 'date'],
            'plannedDateEnd' => ['required', 'date', 'after_or_equal:plannedDateBegin'],
            'actualDateBegin' => ['nullable', 'date'],
            'actualDateEnd' => ['nullable', 'date', 'after_or_equal:actualDateBegin'],
            'proposalStatus' => ['required', new Enum(ProposalStatus::class)],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('proposals.enter_a_proposal_name'),
            'plannedDateBegin.required' => __('proposals.set_the_planned_start_date'),
            'plannedDateEnd.required' => __('proposals.set_the_planned_end_date'),
            'plannedDateEnd.after_or_equal' => __('proposals.the_planned_end_cannot_fall_before_the_planned_start'),
            'actualDateEnd.after_or_equal' => __('proposals.the_actual_end_cannot_fall_before_the_actual_start'),
        ]);

        $proposal = $this->editingProposalId === null
            ? new Proposal
            : Proposal::query()->findOrFail($this->editingProposalId);

        $proposal->fill([
            'name' => $validated['name'],
            'planned_date_begin' => $validated['plannedDateBegin'],
            'planned_date_end' => $validated['plannedDateEnd'],
            'actual_date_begin' => $validated['actualDateBegin'] === '' ? null : $validated['actualDateBegin'],
            'actual_date_end' => $validated['actualDateEnd'] === '' ? null : $validated['actualDateEnd'],
            'status' => $validated['proposalStatus'],
            'notes' => $validated['notes'] === '' ? null : $validated['notes'],
        ])->save();

        $wasRecentlyCreated = $proposal->wasRecentlyCreated;

        $this->showProposalModal = false;
        $this->resetProposalForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('proposals.proposal_created') : __('proposals.proposal_updated'),
        );
    }

    public function confirmDelete(int $proposalId): void
    {
        $this->deletingProposalId = $proposalId;
    }

    public function deleteProposal(): void
    {
        if ($this->deletingProposalId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingProposalId)) {
            $this->deletingProposalId = null;

            Flux::toast(
                variant: 'danger',
                text: __('proposals.this_proposal_has_a_funding_agreement_remove_the_agreement_f'),
            );

            return;
        }

        Proposal::query()->findOrFail($this->deletingProposalId)->delete();

        $this->deletingProposalId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('proposals.proposal_deleted'));
    }

    public function closeProposalModal(): void
    {
        $this->showProposalModal = false;
        $this->resetProposalForm();
    }

    protected function resetProposalForm(): void
    {
        $this->resetValidation();
        $this->editingProposalId = null;
        $this->name = '';
        $this->plannedDateBegin = '';
        $this->plannedDateEnd = '';
        $this->actualDateBegin = '';
        $this->actualDateEnd = '';
        $this->proposalStatus = 'concept note';
        $this->notes = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $phase !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="proposal-pipeline-heading">
    <x-ui.page-header
        heading-id="proposal-pipeline-heading"
        :title="__('common.proposals')"
        :count="$this->proposals->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('proposals.search_proposals')" :placeholder="__('proposals.name_or_notes')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'proposal-status-'.$statusCase->value">{{ $statusCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('proposals.phase')" wire:model.live="phase" :value="$phase">
                <flux:select.option value="">{{ __('proposals.all_phases') }}</flux:select.option>
                @foreach (ProposalPhase::cases() as $phaseCase)
                    <flux:select.option :value="$phaseCase->value" :wire:key="'proposal-phase-'.$phaseCase->value">{{ $phaseCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-proposal-button">
                {{ __('proposals.add_proposal') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('proposals.count_matching_proposal', $this->proposals->total(), ['count' => $this->proposals->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->proposals->isEmpty())
            <x-ui.empty-state
                icon="document-text"
                :heading="$filtersAreActive ? __('proposals.no_matching_proposals') : __('proposals.no_proposals_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('proposals.add_the_first_proposal_to_open_the_pipeline')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->proposals as $proposal)
                    <article class="flex flex-col gap-3 p-3.5 @4xl/directory:flex-row @4xl/directory:items-center" wire:key="proposal-{{ $proposal->id }}">
                        <div class="min-w-0 @4xl/directory:me-auto">
                            <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $proposal->name }}</a>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $proposal->planned_date_begin?->translatedFormat(__('formats.date')) }} – {{ $proposal->planned_date_end?->translatedFormat(__('formats.date')) }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.enum-badge :case="$proposal->status" />
                            {{-- phase is a read-only accessor over status->phase(), not a column. --}}
                            <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                {{ $proposal->phase->label() }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-secondary-soft @4xl/directory:w-64">
                            <span>{{ trans_choice('common.count_agreement', $proposal->donor_proposals_count, ['count' => $proposal->donor_proposals_count]) }}</span>
                            <span>{{ trans_choice('common.count_project', $proposal->projects_count, ['count' => $proposal->projects_count]) }}</span>
                            <span>{{ trans_choice('proposals.count_task', $proposal->tasks_count, ['count' => $proposal->tasks_count]) }}</span>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />

                            @if ($proposal->donor_proposals_count === 0)
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                            @else
                                <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                    <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                        <flux:icon.lock-closed class="size-4" />
                                    </span>
                                </flux:tooltip>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->proposals" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#proposal-pipeline-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showProposalModal" class="w-full max-w-3xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveProposal" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingProposalId === null ? __('proposals.add_proposal') : __('proposals.edit_proposal') }}</flux:heading>
                <flux:subheading>{{ __('proposals.phase_follows_automatically_from_the_status_you_choose') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('proposals.proposal_name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="plannedDateBegin" :label="__('proposals.planned_start')" type="date" required />
                <x-ui.input wire:model="plannedDateEnd" :label="__('proposals.planned_end')" type="date" required />
                <x-ui.input wire:model="actualDateBegin" :label="__('proposals.actual_start')" type="date" />
                <x-ui.input wire:model="actualDateEnd" :label="__('proposals.actual_end')" type="date" />
            </div>

            <x-ui.select wire:model="proposalStatus" :label="__('common.status')" required>
                @foreach (ProposalStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'proposal-status-option-'.$statusCase->value">
                        {{ $statusCase->label() }} — {{ $statusCase->phase()->label() }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <flux:textarea wire:model="notes" :label="__('common.notes')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="rounded-lg border border-secondary-soft bg-secondary-soft/50 p-3 text-sm text-secondary-medium dark:border-secondary-medium dark:bg-secondary-medium/50 dark:text-secondary-soft">
                <div class="flex items-start gap-2.5">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                    <p>{{ __('proposals.changing_status_here_does_not_write_an_audit_note_use_the_st') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeProposalModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-proposal-button">
                    {{ $editingProposalId === null ? __('proposals.create_proposal') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingProposalId !== null" wire:close="$set('deletingProposalId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('proposals.delete_this_proposal') }}</flux:heading>
            <flux:subheading>{{ __('proposals.its_tasks_project_links_and_status_history_are_removed_with') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingProposalId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteProposal" data-test="confirm-delete-proposal">{{ __('proposals.delete_proposal') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
