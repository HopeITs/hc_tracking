<?php

use App\Enums\Period;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Result;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.results_framework')] class extends Component {
    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $type = '';

    public bool $showResultModal = false;
    public ?int $editingResultId = null;
    public ?int $deletingResultId = null;

    public string $statement = '';
    public string $resultType = 'outcome';
    public string $checkoutPeriod = 'quarterly';
    public string $resultProgramId = '';
    public string $parentId = '';

    public function clearFilters(): void
    {
        $this->program = '';
        $this->type = '';
    }

    /**
     * Logframe roots, grouped by program name with an explicit Unassigned key.
     *
     * results.program_id is nullable and nullOnDelete, so a result can outlive
     * its program. Those must still be reachable, not silently dropped.
     *
     * @return Collection<string, Collection<int, Result>>
     */
    #[Computed]
    public function groupedRoots(): Collection
    {
        return Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->roots()
            ->when($this->program === 'none', fn ($query) => $query->whereNull('program_id'))
            ->when(
                $this->program !== '' && $this->program !== 'none',
                fn ($query) => $query->where('program_id', (int) $this->program)
            )
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->with(['program:id,name', 'childrenRecursive'])
            ->get()
            ->groupBy(fn (Result $result): string => $result->program?->name ?? __('common.unassigned'));
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Results this one may contribute to.
     *
     * Excludes the node and its descendants so ResultObserver's
     * InvalidParentException stays a backstop. Unlike milestones there is no
     * project constraint: any other result is a legal parent.
     *
     * @return Collection<int, Result>
     */
    #[Computed]
    public function parentOptions(): Collection
    {
        $excludedIds = [];

        if ($this->editingResultId !== null) {
            $result = Result::query()
                ->whereKey($this->editingResultId)
                ->with('childrenRecursive')
                ->first();

            if ($result !== null) {
                $excludedIds = $result->descendants()->pluck('id')->push($result->id)->all();
            }
        }

        return Result::query()
            ->select(['id', 'statement', 'type', 'program_id'])
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether this result may be deleted.
     *
     * Refused when it has children (parent_id cascades, taking the subtree) or
     * indicators (indicators cascade, and plan_indicators and reports cascade
     * from those — three tables lost behind one click).
     */
    public function canDelete(int $resultId): bool
    {
        $result = Result::query()
            ->select(['id'])
            ->withCount(['children', 'indicators'])
            ->find($resultId);

        return $result !== null && $result->children_count === 0 && $result->indicators_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetResultForm();
        $this->showResultModal = true;
    }

    public function openEditModal(int $resultId): void
    {
        $result = Result::query()
            ->select(['id', 'statement', 'parent_id', 'program_id', 'type', 'checkout_period'])
            ->findOrFail($resultId);

        $this->resetValidation();
        $this->editingResultId = $result->id;
        $this->statement = $result->statement;
        $this->resultType = $result->type->value;
        $this->checkoutPeriod = $result->checkout_period->value;
        $this->resultProgramId = (string) ($result->program_id ?? '');
        $this->parentId = (string) ($result->parent_id ?? '');
        $this->showResultModal = true;
    }

    public function saveResult(): void
    {
        $this->statement = trim($this->statement);

        $validated = $this->validate([
            'statement' => ['required', 'string'],
            'resultType' => ['required', new Enum(ResultType::class)],
            'checkoutPeriod' => ['required', new Enum(Period::class)],
            'resultProgramId' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'parentId' => ['nullable', 'integer', Rule::exists(Result::class, 'id')],
        ], [
            'statement.required' => __('results_framework.write_the_result_statement'),
            'resultType.required' => __('results_framework.choose_impact_outcome_or_output'),
            'checkoutPeriod.required' => __('common.choose_how_often_this_is_checked'),
        ]);

        $result = $this->editingResultId === null
            ? new Result
            : Result::query()->findOrFail($this->editingResultId);

        $result->fill([
            'statement' => $validated['statement'],
            'type' => $validated['resultType'],
            'checkout_period' => $validated['checkoutPeriod'],
            'program_id' => $validated['resultProgramId'] === '' || $validated['resultProgramId'] === null
                ? null
                : (int) $validated['resultProgramId'],
            'parent_id' => $validated['parentId'] === '' || $validated['parentId'] === null
                ? null
                : (int) $validated['parentId'],
        ])->save();

        $wasRecentlyCreated = $result->wasRecentlyCreated;

        $this->showResultModal = false;
        $this->resetResultForm();
        unset($this->groupedRoots);

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('results_framework.result_added') : __('results_framework.result_updated'),
        );
    }

    public function confirmDelete(int $resultId): void
    {
        $this->deletingResultId = $resultId;
    }

    public function deleteResult(): void
    {
        if ($this->deletingResultId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingResultId)) {
            $this->deletingResultId = null;

            Flux::toast(
                variant: 'danger',
                text: __('results_framework.this_result_still_has_nested_results_or_indicators_remove_th'),
            );

            return;
        }

        Result::query()->findOrFail($this->deletingResultId)->delete();

        $this->deletingResultId = null;
        unset($this->groupedRoots);

        Flux::toast(variant: 'success', text: __('results_framework.result_deleted'));
    }

    public function closeResultModal(): void
    {
        $this->showResultModal = false;
        $this->resetResultForm();
    }

    protected function resetResultForm(): void
    {
        $this->resetValidation();
        $this->editingResultId = null;
        $this->statement = '';
        $this->resultType = 'outcome';
        $this->checkoutPeriod = 'quarterly';
        $this->resultProgramId = '';
        $this->parentId = '';
    }
};
?>

@php
    $filtersAreActive = $program !== '' || $type !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="results-framework-heading">
    <x-ui.page-header
        heading-id="results-framework-heading"
        :title="__('common.results_framework')"
        :description="__('common.impact_outcome_and_output_statements_nested_as_recorded')"
    >
        <x-slot:filters>
            <x-ui.select :label="__('common.program')" wire:model.live="program">
                <flux:select.option value="">{{ __('common.all_programs') }}</flux:select.option>
                <flux:select.option value="none">{{ __('common.unassigned') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'result-program-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('common.type')" wire:model.live="type">
                <flux:select.option value="">{{ __('results_framework.all_types') }}</flux:select.option>
                @foreach (ResultType::cases() as $typeCase)
                    <flux:select.option :value="$typeCase->value" :wire:key="'result-type-'.$typeCase->value">{{ $typeCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-result-button">
                {{ __('results_framework.add_result') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->groupedRoots->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('results_framework.no_matching_results') : __('common.no_results_yet')"
                :body="$filtersAreActive ? __('common.change_the_filters_and_try_again') : __('results_framework.add_the_first_impact_statement_to_start_the_logframe')"
            />
        </x-ui.panel>
    @else
        @foreach ($this->groupedRoots as $programName => $roots)
            <x-ui.panel :title="$programName" :count="$roots->count()" wire:key="result-group-{{ Str::slug($programName) }}">
                <x-ui.tree-rows
                    :nodes="$roots"
                    :label="fn (\App\Models\Result $result): string => $result->statement"
                    :badge="fn (\App\Models\Result $result) => $result->type"
                />
            </x-ui.panel>
        @endforeach
    @endif

    <flux:modal wire:model="showResultModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showResultModal)
        <form wire:submit="saveResult" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingResultId === null ? __('results_framework.add_result') : __('results_framework.edit_result') }}</flux:heading>
                <flux:subheading>{{ __('results_framework.an_output_contributes_to_an_outcome_which_contributes_to_an') }}</flux:subheading>
            </div>

            <flux:textarea wire:model="statement" :label="__('results_framework.statement')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="resultType" :label="__('common.type')" required>
                    @foreach (ResultType::cases() as $typeCase)
                        <flux:select.option :value="$typeCase->value" :wire:key="'result-type-option-'.$typeCase->value">{{ $typeCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="checkoutPeriod" :label="__('results_framework.checked')" required>
                    @foreach (Period::cases() as $periodCase)
                        <flux:select.option :value="$periodCase->value" :wire:key="'result-period-'.$periodCase->value">{{ $periodCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="resultProgramId" :label="__('common.program')">
                    <flux:select.option value="">{{ __('common.unassigned') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'result-program-option-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="parentId" :label="__('results_framework.contributes_to')">
                    <flux:select.option value="">{{ __('common.top_level') }}</flux:select.option>
                    @foreach ($this->parentOptions as $parentOption)
                        <flux:select.option :value="$parentOption->id" :wire:key="'result-parent-'.$parentOption->id">
                            {{ Str::limit($parentOption->statement, 60) }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeResultModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-result-button">
                    {{ $editingResultId === null ? __('results_framework.add_result') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>

    <flux:modal :open="$deletingResultId !== null" wire:close="$set('deletingResultId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('results_framework.delete_this_result') }}</flux:heading>
            <flux:subheading>{{ __('results_framework.only_a_result_with_no_nested_results_and_no_indicators_can_b') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingResultId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteResult" data-test="confirm-delete-result">{{ __('results_framework.delete_result') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
