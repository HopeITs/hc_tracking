<?php

use App\Enums\Period;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\YearlyPlan;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Targets')] class extends Component {
    use WithPagination;

    /**
     * The only two sources a target may be committed in.
     *
     * These are the morph map keys registered in AppServiceProvider, not class
     * names: plan_indicators.source_type is VARCHAR(50) and
     * Relation::enforceMorphMap() is global.
     */
    private const SOURCE_TYPES = ['yearly_plan', 'proposal'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sourceType = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $period = '';

    public bool $showTargetModal = false;
    public ?int $editingTargetId = null;
    public ?int $deletingTargetId = null;

    public string $targetSourceType = 'yearly_plan';
    public string $targetSourceId = '';
    public string $targetIndicatorId = '';
    public string $targetValue = '';
    public string $targetProjectId = '';
    public string $targetPeriod = 'quarterly';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSourceType(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    /**
     * A source chosen under one type is meaningless under the other, so
     * switching type clears it rather than carrying an id across.
     */
    public function updatedTargetSourceType(): void
    {
        $this->targetSourceId = '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->sourceType = '';
        $this->project = '';
        $this->period = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, PlanIndicator> */
    #[Computed]
    public function targets(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period', 'updated_at'])
            ->with([
                // morphTo resolves to YearlyPlan or Proposal — or to null, when
                // the source was removed by a write that fired no model events.
                'source',
                'indicator:id,name,result_id,measure',
                'indicator.result:id,statement,type',
                'project:id,name,city_id',
                'project.city:id,name_en',
            ])
            ->withCount('reports')
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('indicator', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
            })
            ->when($this->sourceType !== '', fn ($query) => $query->where('source_type', $this->sourceType))
            ->when($this->project === 'none', fn ($query) => $query->whereNull('project_id'))
            ->when(
                $this->project !== '' && $this->project !== 'none',
                fn ($query) => $query->where('project_id', (int) $this->project)
            )
            ->when($this->period !== '', fn ($query) => $query->where('period', $this->period))
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * The sources selectable under the currently chosen source type.
     *
     * @return array<int, array{id: int, label: string}>
     */
    #[Computed]
    public function sourceOptions(): array
    {
        if ($this->targetSourceType === 'proposal') {
            return Proposal::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'label' => $proposal->name])
                ->all();
        }

        return YearlyPlan::query()
            ->select(['id', 'program_id', 'year'])
            ->with('program:id,name')
            ->orderByDesc('year')
            ->get()
            ->map(fn (YearlyPlan $plan): array => [
                'id' => $plan->id,
                'label' => __('FY :year · :program', ['year' => $plan->year, 'program' => $plan->program->name]),
            ])
            ->all();
    }

    /** @return Collection<int, Indicator> */
    #[Computed]
    public function indicatorOptions(): Collection
    {
        return Indicator::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether this target may be deleted.
     *
     * Refused once reported: reports cascade from plan_indicators, and
     * report_files cascade from reports. A measurement is not discarded to tidy
     * up a target.
     */
    public function canDelete(int $targetId): bool
    {
        $target = PlanIndicator::query()->select(['id'])->withCount('reports')->find($targetId);

        return $target !== null && $target->reports_count === 0;
    }

    /**
     * A human label for a target's source.
     *
     * Returns null when the source no longer exists. source_id carries no
     * foreign key, so a raw delete of a plan or proposal leaves this dangling
     * and the caller must render an explicit fallback.
     */
    public function sourceLabel(PlanIndicator $target): ?string
    {
        $source = $target->source;

        if ($source === null) {
            return null;
        }

        if ($source instanceof YearlyPlan) {
            return __('FY :year', ['year' => $source->year]);
        }

        if ($source instanceof Proposal) {
            return $source->name;
        }

        return null;
    }

    public function openCreateModal(): void
    {
        $this->resetTargetForm();
        $this->showTargetModal = true;
    }

    public function openEditModal(int $targetId): void
    {
        $target = PlanIndicator::query()
            ->select(['id', 'source_type', 'source_id', 'indicator_id', 'target', 'project_id', 'period'])
            ->findOrFail($targetId);

        $this->resetValidation();
        $this->editingTargetId = $target->id;
        $this->targetSourceType = $target->source_type;
        $this->targetSourceId = (string) $target->source_id;
        $this->targetIndicatorId = (string) $target->indicator_id;
        $this->targetValue = (string) $target->target;
        $this->targetProjectId = (string) ($target->project_id ?? '');
        $this->targetPeriod = $target->period->value;
        $this->showTargetModal = true;
    }

    public function saveTarget(): void
    {
        $validated = $this->validate([
            'targetSourceType' => ['required', Rule::in(self::SOURCE_TYPES)],
            'targetSourceId' => ['required', 'integer'],
            'targetIndicatorId' => ['required', 'integer', Rule::exists(Indicator::class, 'id')],
            'targetValue' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'targetProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
            'targetPeriod' => ['required', new Enum(Period::class)],
        ], [
            'targetSourceType.required' => __('Choose where this target was committed.'),
            'targetSourceType.in' => __('A target can only be committed in an annual plan or a proposal.'),
            'targetSourceId.required' => __('Select the annual plan or proposal.'),
            'targetIndicatorId.required' => __('Select the indicator being targeted.'),
            'targetValue.required' => __('Enter the target value.'),
            'targetValue.min' => __('A target cannot be negative.'),
            'targetValue.max' => __('The target value is too large to record.'),
        ]);

        // The chosen source must actually exist under the chosen type. A single
        // exists rule cannot express this, because the table depends on the type.
        $sourceExists = $validated['targetSourceType'] === 'proposal'
            ? Proposal::query()->whereKey((int) $validated['targetSourceId'])->exists()
            : YearlyPlan::query()->whereKey((int) $validated['targetSourceId'])->exists();

        if (! $sourceExists) {
            $this->addError('targetSourceId', __('The selected source is no longer available.'));

            return;
        }

        $target = $this->editingTargetId === null
            ? new PlanIndicator
            : PlanIndicator::query()->findOrFail($this->editingTargetId);

        $target->fill([
            // The map key, never the class name.
            'source_type' => $validated['targetSourceType'],
            'source_id' => (int) $validated['targetSourceId'],
            'indicator_id' => (int) $validated['targetIndicatorId'],
            'target' => $validated['targetValue'],
            'project_id' => $validated['targetProjectId'] === '' || $validated['targetProjectId'] === null
                ? null
                : (int) $validated['targetProjectId'],
            'period' => $validated['targetPeriod'],
        ])->save();

        $wasRecentlyCreated = $target->wasRecentlyCreated;

        $this->showTargetModal = false;
        $this->resetTargetForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Target committed.') : __('Target updated.'),
        );
    }

    public function confirmDelete(int $targetId): void
    {
        $this->deletingTargetId = $targetId;
    }

    public function deleteTarget(): void
    {
        if ($this->deletingTargetId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingTargetId)) {
            $this->deletingTargetId = null;

            Flux::toast(
                variant: 'danger',
                text: __('This target has been reported against. Deleting it would remove those measurements.'),
            );

            return;
        }

        PlanIndicator::query()->findOrFail($this->deletingTargetId)->delete();

        $this->deletingTargetId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Target deleted.'));
    }

    public function closeTargetModal(): void
    {
        $this->showTargetModal = false;
        $this->resetTargetForm();
    }

    protected function resetTargetForm(): void
    {
        $this->resetValidation();
        $this->editingTargetId = null;
        $this->targetSourceType = 'yearly_plan';
        $this->targetSourceId = '';
        $this->targetIndicatorId = '';
        $this->targetValue = '';
        $this->targetProjectId = '';
        $this->targetPeriod = 'quarterly';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $sourceType !== '' || $project !== '' || $period !== '';
    $sourceTypeLabels = ['yearly_plan' => __('Annual plan'), 'proposal' => __('Proposal')];
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="target-directory-heading">
    <x-ui.page-header
        heading-id="target-directory-heading"
        :title="__('Targets')"
        :count="$this->targets->total()"
        :description="__('What was committed, where it was committed, and against which project.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search targets')" :placeholder="__('Indicator name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Committed in')" wire:model.live="sourceType">
                <flux:select.option value="">{{ __('Any source') }}</flux:select.option>
                @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                    <flux:select.option :value="$sourceKey" :wire:key="'tgt-source-'.$sourceKey">{{ $sourceLabel }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Project')" wire:model.live="project">
                <flux:select.option value="">{{ __('Any project') }}</flux:select.option>
                <flux:select.option value="none">{{ __('Organisation-wide') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'tgt-project-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Period')" wire:model.live="period">
                <flux:select.option value="">{{ __('All periods') }}</flux:select.option>
                @foreach (Period::cases() as $periodCase)
                    <flux:select.option :value="$periodCase->value" :wire:key="'tgt-period-'.$periodCase->value">{{ Str::title($periodCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-target-button">
                {{ __('Commit target') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching target|[2,*] :count matching targets', $this->targets->total(), ['count' => $this->targets->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->targets->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('No matching targets') : __('No targets yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Commit the first target in an annual plan or a proposal.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->targets as $target)
                    @php $sourceLabel = $this->sourceLabel($target); @endphp
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="target-{{ $target->id }}">
                        <div class="min-w-0 me-auto">
                            <p class="text-sm font-semibold">{{ $target->indicator->name }}</p>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ Str::limit($target->indicator->result->statement, 60) }}
                                ·
                                @if ($target->project === null)
                                    {{ __('Organisation-wide') }}
                                @else
                                    {{ $target->project->name }} · {{ $target->project->city?->name_en ?? __('Unknown city') }}
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-1.5">
                            {{-- Rendered from the morph map key, never a class name. --}}
                            <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">
                                {{ $sourceTypeLabels[$target->source_type] ?? $target->source_type }}
                            </span>

                            @if ($sourceLabel === null)
                                {{-- source_id has no foreign key: the plan or proposal can be
                                     removed out from under this row. --}}
                                <span class="rounded-md border border-primary px-2 py-0.5 text-[11px] font-semibold text-primary">
                                    {{ __('Source no longer available') }}
                                </span>
                            @else
                                <span class="text-xs font-medium">{{ $sourceLabel }}</span>
                            @endif
                        </div>

                        <x-ui.enum-badge :case="$target->period" />
                        <span class="text-sm font-semibold tabular-nums">{{ number_format((float) $target->target, 2) }}</span>

                        <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft" title="{{ __('Reports') }}">
                            {{ $target->reports_count }}
                        </span>

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $target->id }})" :aria-label="__('Edit target')" />

                        @if ($target->reports_count === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $target->id }})" :aria-label="__('Delete target')" />
                        @else
                            <flux:tooltip :content="__('Targets that have been reported against cannot be deleted.')">
                                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                    <flux:icon.lock-closed class="size-4" />
                                </span>
                            </flux:tooltip>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->targets" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#target-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showTargetModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showTargetModal)
        <form wire:submit="saveTarget" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingTargetId === null ? __('Commit target') : __('Edit target') }}</flux:heading>
                <flux:subheading>{{ __('A target is committed either in an annual operational plan or in a donor proposal.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model.live="targetSourceType" :label="__('Committed in')" required>
                    @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                        <flux:select.option :value="$sourceKey" :wire:key="'tgt-source-option-'.$sourceKey">{{ $sourceLabel }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="targetSourceId" :label="$targetSourceType === 'proposal' ? __('Proposal') : __('Annual plan')" required>
                    <flux:select.option value="">{{ __('Select a source') }}</flux:select.option>
                    @foreach ($this->sourceOptions as $sourceOption)
                        <flux:select.option :value="$sourceOption['id']" :wire:key="'tgt-src-'.$targetSourceType.'-'.$sourceOption['id']">
                            {{ $sourceOption['label'] }}
                        </flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <x-ui.select wire:model="targetIndicatorId" :label="__('Indicator')" required>
                <flux:select.option value="">{{ __('Select an indicator') }}</flux:select.option>
                @foreach ($this->indicatorOptions as $indicatorOption)
                    <flux:select.option :value="$indicatorOption->id" :wire:key="'tgt-indicator-'.$indicatorOption->id">{{ $indicatorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="targetValue" :label="__('Target value')" type="number" step="0.01" min="0" required />

                <x-ui.select wire:model="targetPeriod" :label="__('Reporting period')" required>
                    @foreach (Period::cases() as $periodCase)
                        <flux:select.option :value="$periodCase->value" :wire:key="'tgt-period-option-'.$periodCase->value">{{ Str::title($periodCase->value) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="targetProjectId" :label="__('Project')">
                    <flux:select.option value="">{{ __('Organisation-wide') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'tgt-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeTargetModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-target-button">
                    {{ $editingTargetId === null ? __('Commit target') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>

    <flux:modal :open="$deletingTargetId !== null" wire:close="$set('deletingTargetId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        @if ($deletingTargetId !== null)
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Delete this target?') }}</flux:heading>
            <flux:subheading>{{ __('Only a target with no reports can be deleted. Otherwise every measurement recorded against it would go too.') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingTargetId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteTarget" data-test="confirm-delete-target">{{ __('Delete target') }}</x-ui.button>
            </div>
        </div>
        @endif
    </flux:modal>
</section>
