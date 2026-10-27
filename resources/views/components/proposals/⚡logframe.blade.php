<?php

use App\Livewire\Concerns\Proposals\CopiesProgramFramework;
use App\Livewire\Concerns\Results\BuildsResultsFramework;
use App\Livewire\Concerns\Results\BuildsResultsTree;
use App\Livewire\Concerns\Results\FiltersResultsChain;
use App\Livewire\Concerns\Results\ManagesResultForm;
use App\Enums\ResultType;
use App\Models\Proposal;
use App\Models\Result;
use App\Support\ProposalFramework;
use App\Support\ResultsChain;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The Logical Framework tab of proposals/show (T12): the proposal's own
 * results framework, impact → outcome → output → activity, with
 * cross-cutting results as a group below the tree.
 *
 * Mounted from pages/proposals/_tab-logframe.blade.php, so the show page's
 * own lines stay untouched. Reads and writes go through the same concerns as
 * results/index, pinned to this proposal (fixedProposalId()), so every id is
 * loaded through `Result::proposalFramework($proposalId)` and every write
 * goes through SaveResult / DeleteResult with the proposal. Visible to
 * whoever can open the page; no money is read.
 *
 * The Chain view (T16) renders the same framework read-only through
 * App\Support\ResultsChain: indicators, per-center targets, achievement,
 * gaps and filters. forgetFramework() clears it after a write.
 */
new class extends Component {
    use BuildsResultsFramework;
    use CopiesProgramFramework;
    use BuildsResultsTree;
    use FiltersResultsChain;
    use ManagesResultForm;

    #[Locked]
    public int $proposalId;

    /** `structure` (the editable tree) or `chain` (the read-only chain view). */
    #[Url(as: 'logframe_view', except: 'structure')]
    public string $view = 'structure';

    public function mount(int $proposalId): void
    {
        $this->proposalId = $proposalId;
        $this->formProposalId = $proposalId;
    }

    protected function fixedProposalId(): ?int
    {
        return $this->proposalId;
    }

    /**
     * The tab shows the whole framework: there are no filters to apply.
     */
    protected function matchesFilters(Result $result, ?string $focus = null): bool
    {
        return true;
    }

    #[Computed]
    public function proposal(): Proposal
    {
        return Proposal::query()->select(['id', 'name', 'status'])->findOrFail($this->proposalId);
    }

    /**
     * Whether the proposal's projects span programs, so its roots carry no
     * program ("Multi-program", D-24).
     */
    #[Computed]
    public function isMultiProgram(): bool
    {
        return count(ProposalFramework::programIds($this->proposal)) > 1;
    }

    /**
     * Whether the structure is closed to this user: the proposal reached
     * `submitted` and they do not hold `results.update` (contracts §11.2).
     * The actions refuse the write either way; this only shapes the page.
     */
    #[Computed]
    public function isLockedForMe(): bool
    {
        return ProposalFramework::isStructureLocked($this->proposal) && ! Gate::allows('results.update');
    }

    /**
     * A short fingerprint of the framework's activities (ids, statements,
     * kinds, parents), read from the results already loaded.
     */
    #[Computed]
    public function activitiesFingerprint(): string
    {
        return substr(sha1($this->allResults
            ->filter(fn (Result $result): bool => $result->type === ResultType::Activity)
            ->map(fn (Result $result): string => $result->id.':'.$result->parent_id.':'.$result->activity_kind?->value.':'.$result->statement)
            ->implode('|')), 0, 12);
    }

    /**
     * Whether the read-only chain view is shown; any other value is the tree.
     */
    #[Computed]
    public function showsChain(): bool
    {
        return $this->view === 'chain';
    }

    /**
     * The read-only results chain of this proposal (T16).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        return ResultsChain::forProposal($this->proposal, $this->chainFilters());
    }

    /**
     * The tree roots and the cross-cutting roots, each as prebuilt node arrays.
     *
     * @return array{tree: list<array<string, mixed>>, crossCutting: list<array<string, mixed>>}
     */
    #[Computed]
    public function frameworkRoots(): array
    {
        $roots = collect($this->rootNodes());
        [$crossCutting, $tree] = $roots->partition(fn (array $node): bool => $node['result']->type === ResultType::CrossCutting);

        return ['tree' => $tree->values()->all(), 'crossCutting' => $crossCutting->values()->all()];
    }
};
?>

@php
    $roots = $this->frameworkRoots;
    $editable = ! $this->isLockedForMe;
    $canCreate = $editable && auth()->user()->can('results.create');
@endphp

<div class="@container/directory flex flex-col gap-5" data-test="proposal-logframe" data-tour="proposal-logframe">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div class="me-auto min-w-0">
            <h2 class="text-base font-bold text-secondary-dark dark:text-ink">{{ __('proposals.tab_logframe') }}</h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('results_framework.logframe_tab_intro') }}</p>
        </div>

        @if ($this->isMultiProgram)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-secondary-soft px-2.5 py-1 type-meta font-semibold text-secondary-dark dark:bg-surface-2 dark:text-ink" data-test="logframe-multi-program">
                <flux:icon.squares-2x2 variant="micro" class="size-3.5" aria-hidden="true" />
                {{ __('results_framework.multi_program') }}
            </span>
        @endif

        @if ($canCreate)
            <x-ui.button icon="document-duplicate" wire:click="openCopyModal" wire:loading.attr="disabled" wire:target="openCopyModal" data-test="start-from-program-framework" data-tour="logframe-copy-framework">
                {{ __('results_framework.start_from_program_framework') }}
            </x-ui.button>
            <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" wire:loading.attr="disabled" wire:target="openCreateModal" data-test="create-logframe-result">
                {{ __('results_framework.add_result') }}
            </x-ui.button>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-1 self-start rounded-lg border border-secondary-soft bg-ground p-1 print:hidden dark:border-line dark:bg-surface-2" role="group" aria-label="{{ __('results_framework.chain_view_label') }}" data-chain-view-toggle>
        @foreach (['structure' => __('results_framework.chain_view_structure'), 'chain' => __('results_framework.chain_view_chain')] as $mode => $label)
            <button
                type="button"
                wire:click="$set('view', '{{ $mode }}')"
                wire:loading.attr="disabled"
                wire:target="view"
                aria-pressed="{{ ($mode === 'chain') === $this->showsChain ? 'true' : 'false' }}"
                @class([
                    'cursor-pointer rounded-md px-3 py-1.5 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                    'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:text-ink' => ($mode === 'chain') === $this->showsChain,
                    'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ($mode === 'chain') !== $this->showsChain,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    @if ($this->isLockedForMe)
        <p class="flex items-start gap-2 rounded-lg border border-secondary-soft bg-ground px-3 py-2.5 type-meta text-secondary-dark dark:border-line dark:bg-surface-2 dark:text-ink" data-test="logframe-locked">
            <flux:icon.lock-closed variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ __('results_framework.locked_read_only') }}</span>
        </p>
    @endif

    @if ($this->showsChain)
        <div class="@container/detail">
            <x-ui.logframe :chain="$this->chain" :heading="__('results_framework.chain_heading')" heading-id="proposal-chain-heading">
                @if ($canCreate)
                    <x-slot:start>
                        <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" wire:loading.attr="disabled" wire:target="openCreateModal">{{ __('results_framework.chain_start') }}</x-ui.button>
                    </x-slot:start>
                @endif
            </x-ui.logframe>
        </div>
    @elseif ($roots['tree'] === [] && $roots['crossCutting'] === [])
        <x-ui.panel>
            <x-ui.empty-state
                icon="share"
                :heading="__('results_framework.logframe_empty_heading')"
                :body="$canCreate ? __('results_framework.logframe_empty_body_editor') : __('results_framework.logframe_empty_body_viewer')"
            >
                <x-slot:action>
                    @if ($canCreate)<x-ui.button tone="primary" icon="plus" wire:click="openCreateModal">{{ __('results_framework.add_result') }}</x-ui.button>@endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        <div class="space-y-5" data-test="logframe-tree">
            @foreach ($roots['tree'] as $node)
                <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" :editable="$editable" wire:key="logframe-root-{{ $node['result']->id }}" />
            @endforeach
        </div>

        @if ($roots['crossCutting'] !== [])
            <section class="space-y-3" aria-labelledby="logframe-cross-cutting-heading" data-test="logframe-cross-cutting">
                <h3 id="logframe-cross-cutting-heading" class="type-label text-secondary-medium dark:text-ink-muted">{{ __('results_framework.cross_cutting_group') }}</h3>
                @foreach ($roots['crossCutting'] as $node)
                    <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" :editable="$editable" wire:key="logframe-cross-cutting-{{ $node['result']->id }}" />
                @endforeach
            </section>
        @endif

        {{-- Re-mounted when an activity is added, renamed, retyped or removed above, so its rows follow the tree. --}}
        <livewire:proposals.activity-calendar :proposal-id="$proposalId" wire:key="activity-calendar-{{ $this->activitiesFingerprint }}" />
    @endif

    @include('pages.results._index-result-modal')

    @if ($canCreate)
        @include('components.proposals._logframe-copy-modal')
    @endif

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('results_framework.delete_this_result')"
        :message="__('results_framework.only_a_result_with_no_nested_results_and_no_indicators_can_b')"
        confirm="deleteResult"
        :confirm-label="__('results_framework.delete_result')"
        confirm-test="confirm-delete-result"
    />
</div>
