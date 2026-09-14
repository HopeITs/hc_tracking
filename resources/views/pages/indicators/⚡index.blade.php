<?php

use App\Enums\IndicatorMeasure;
use App\Enums\Period;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Project;
use App\Models\Result;
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

new #[Title('titles.indicators')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $result = '';

    #[Url(except: '')]
    public string $measure = '';

    public bool $showIndicatorModal = false;
    public ?int $editingIndicatorId = null;
    public ?int $deletingIndicatorId = null;
    public string $name = '';
    public string $indicatorResultId = '';
    public string $indicatorMeasure = 'numeric';
    public string $unit = '';
    public string $baseline = '';
    public string $target = '';
    public string $frequency = 'quarterly';
    public string $indicatorProjectId = '';

    public bool $showProgressModal = false;
    public ?int $progressIndicatorId = null;
    public string $progressDate = '';
    public string $progressValue = '';
    public string $progressNotes = '';
    public string $progressSourceOfVerification = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedResult(): void
    {
        $this->resetPage();
    }

    public function updatedMeasure(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->result = '';
        $this->measure = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Indicator> */
    #[Computed]
    public function indicators(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency', 'updated_at'])
            ->with([
                'result:id,statement,type,program_id',
                // program_id is nullable on results.
                'result.program:id,name',
                'project:id,name',
                'progress:id,indicator_id,date,value',
            ])
            ->withCount('planIndicators')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('result', fn ($query) => $query->where('statement', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->result !== '', fn ($query) => $query->where('result_id', (int) $this->result))
            ->when($this->measure !== '', fn ($query) => $query->where('measure', $this->measure))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, Result> */
    #[Computed]
    public function resultOptions(): Collection
    {
        return Result::query()->select(['id', 'statement', 'type'])->orderBy('id')->get();
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Whether this indicator may be deleted.
     *
     * Refused once any target exists: plan_indicators cascades from indicators,
     * and reports and report_files cascade from those.
     */
    public function canDelete(int $indicatorId): bool
    {
        $indicator = Indicator::query()->select(['id'])->withCount('planIndicators')->find($indicatorId);

        return $indicator !== null && $indicator->plan_indicators_count === 0;
    }

    public function openCreateModal(): void
    {
        $this->resetIndicatorForm();
        $this->showIndicatorModal = true;
    }

    public function openEditModal(int $indicatorId): void
    {
        $indicator = Indicator::query()
            ->select(['id', 'name', 'result_id', 'project_id', 'measure', 'unit', 'baseline', 'target', 'frequency'])
            ->findOrFail($indicatorId);

        $this->resetValidation();
        $this->editingIndicatorId = $indicator->id;
        $this->name = $indicator->name;
        $this->indicatorResultId = (string) $indicator->result_id;
        $this->indicatorMeasure = $indicator->measure->value;
        $this->unit = (string) ($indicator->unit ?? '');
        $this->baseline = $indicator->baseline === null ? '' : (string) $indicator->baseline;
        $this->target = $indicator->target === null ? '' : (string) $indicator->target;
        $this->frequency = $indicator->frequency->value;
        $this->indicatorProjectId = (string) ($indicator->project_id ?? '');
        $this->showIndicatorModal = true;
    }

    public function saveIndicator(): void
    {
        $this->name = trim($this->name);
        $this->unit = trim($this->unit);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'indicatorResultId' => ['required', 'integer', Rule::exists(Result::class, 'id')],
            'indicatorMeasure' => ['required', new Enum(IndicatorMeasure::class)],
            'unit' => ['nullable', 'string', 'max:50'],
            'baseline' => ['nullable', 'numeric', 'max:9999999999.99'],
            'target' => ['nullable', 'numeric', 'max:9999999999.99'],
            'frequency' => ['required', new Enum(Period::class)],
            'indicatorProjectId' => ['nullable', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'name.required' => __('indicators.enter_the_indicator_name'),
            'indicatorResultId.required' => __('indicators.select_the_result_this_indicator_measures'),
            'indicatorMeasure.required' => __('indicators.choose_whether_this_counts_or_is_a_percentage'),
            'frequency.required' => __('common.choose_how_often_this_is_checked'),
        ]);

        $indicator = $this->editingIndicatorId === null
            ? new Indicator
            : Indicator::query()->findOrFail($this->editingIndicatorId);

        $indicator->fill([
            'name' => $validated['name'],
            'result_id' => (int) $validated['indicatorResultId'],
            'measure' => $validated['indicatorMeasure'],
            'unit' => $validated['unit'] === '' || $validated['unit'] === null ? null : $validated['unit'],
            'baseline' => $validated['baseline'] === '' || $validated['baseline'] === null ? null : $validated['baseline'],
            'target' => $validated['target'] === '' || $validated['target'] === null ? null : $validated['target'],
            'frequency' => $validated['frequency'],
            'project_id' => $validated['indicatorProjectId'] === '' || $validated['indicatorProjectId'] === null
                ? null
                : (int) $validated['indicatorProjectId'],
        ])->save();

        $wasRecentlyCreated = $indicator->wasRecentlyCreated;

        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('indicators.indicator_created') : __('indicators.indicator_updated'),
        );
    }

    public function confirmDelete(int $indicatorId): void
    {
        $this->deletingIndicatorId = $indicatorId;
    }

    public function deleteIndicator(): void
    {
        if ($this->deletingIndicatorId === null) {
            return;
        }

        if (! $this->canDelete($this->deletingIndicatorId)) {
            $this->deletingIndicatorId = null;

            Flux::toast(
                variant: 'danger',
                text: __('indicators.this_indicator_carries_targets_remove_them_first_deleting_it'),
            );

            return;
        }

        Indicator::query()->findOrFail($this->deletingIndicatorId)->delete();

        $this->deletingIndicatorId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('indicators.indicator_deleted'));
    }

    public function closeIndicatorModal(): void
    {
        $this->showIndicatorModal = false;
        $this->resetIndicatorForm();
    }

    public function openProgressModal(int $indicatorId): void
    {
        $this->resetValidation();
        $this->progressIndicatorId = $indicatorId;
        $this->progressDate = now()->toDateString();
        $this->progressValue = '';
        $this->progressNotes = '';
        $this->progressSourceOfVerification = '';
        $this->showProgressModal = true;
    }

    public function saveProgress(): void
    {
        $validated = $this->validate([
            'progressDate' => ['required', 'date'],
            'progressValue' => ['required', 'numeric', 'max:9999999999.99'],
            'progressNotes' => ['nullable', 'string'],
            'progressSourceOfVerification' => ['nullable', 'string', 'max:255'],
        ], [
            'progressDate.required' => __('indicators.enter_the_date_of_this_reading'),
            'progressValue.required' => __('indicators.enter_the_value_recorded'),
        ]);

        if ($this->progressIndicatorId === null) {
            return;
        }

        IndicatorProgress::query()->create([
            'indicator_id' => $this->progressIndicatorId,
            'date' => $validated['progressDate'],
            'value' => $validated['progressValue'],
            'notes' => $validated['progressNotes'] === '' ? null : $validated['progressNotes'],
            'source_of_verification' => $validated['progressSourceOfVerification'] === '' ? null : $validated['progressSourceOfVerification'],
            'recorded_by' => auth()->id(),
        ]);

        $this->showProgressModal = false;
        $this->progressIndicatorId = null;
        unset($this->indicators);

        Flux::toast(variant: 'success', text: __('indicators.reading_logged'));
    }

    public function closeProgressModal(): void
    {
        $this->showProgressModal = false;
        $this->progressIndicatorId = null;
    }

    protected function resetIndicatorForm(): void
    {
        $this->resetValidation();
        $this->editingIndicatorId = null;
        $this->name = '';
        $this->indicatorResultId = '';
        $this->indicatorMeasure = 'numeric';
        $this->unit = '';
        $this->baseline = '';
        $this->target = '';
        $this->frequency = 'quarterly';
        $this->indicatorProjectId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $result !== '' || $measure !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="indicator-directory-heading">
    <x-ui.page-header
        heading-id="indicator-directory-heading"
        :title="__('common.indicators')"
        :count="$this->indicators->total()"
        :description="__('indicators.what_each_result_is_measured_by')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('indicators.search_indicators')" :placeholder="__('indicators.indicator_or_result_statement')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('indicators.result')" wire:model.live="result">
                <flux:select.option value="">{{ __('indicators.all_results') }}</flux:select.option>
                @foreach ($this->resultOptions as $resultOption)
                    <flux:select.option :value="$resultOption->id" :wire:key="'ind-result-'.$resultOption->id">{{ Str::limit($resultOption->statement, 50) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('indicators.measure')" wire:model.live="measure">
                <flux:select.option value="">{{ __('indicators.all_measures') }}</flux:select.option>
                @foreach (IndicatorMeasure::cases() as $measureCase)
                    <flux:select.option :value="$measureCase->value" :wire:key="'ind-measure-'.$measureCase->value">{{ $measureCase->label() }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-indicator-button">
                {{ __('indicators.add_indicator') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('indicators.count_matching_indicator', $this->indicators->total(), ['count' => $this->indicators->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->indicators->isEmpty())
            <x-ui.empty-state
                icon="chart-bar"
                :heading="$filtersAreActive ? __('indicators.no_matching_indicators') : __('indicators.no_indicators_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('indicators.add_the_first_indicator_against_a_result')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->indicators as $indicator)
                    @php
                        $percentAchieved = $indicator->percentAchieved();
                        $ragStatus = $indicator->ragStatus(now()->startOfYear(), now()->endOfYear(), now());
                        $values = $indicator->progress->pluck('value')->map(fn ($value) => (float) $value)->all();
                    @endphp
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="indicator-{{ $indicator->id }}">
                        <div class="min-w-0 me-auto">
                            <p class="text-sm font-semibold">{{ $indicator->name }}</p>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ Str::limit($indicator->result->statement, 70) }}
                                · {{ $indicator->result->program?->name ?? __('common.unassigned') }}
                                @if ($indicator->project)
                                    · {{ $indicator->project->name }}
                                @endif
                            </p>
                        </div>

                        @if (count($values) > 1)
                            @php
                                $min = min($values);
                                $max = max($values);
                                $span = $max - $min;
                                $points = collect($values)->values()->map(function (float $value, int $index) use ($values, $min, $span) {
                                    $x = count($values) > 1 ? ($index / (count($values) - 1)) * 60 : 0;
                                    $y = $span > 0 ? 20 - ((($value - $min) / $span) * 20) : 10;

                                    return round($x, 1).','.round($y, 1);
                                })->implode(' ');
                            @endphp
                            <svg viewBox="0 0 60 20" class="h-5 w-16 shrink-0 text-secondary-medium dark:text-secondary-soft" aria-hidden="true">
                                <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" />
                            </svg>
                        @endif

                        <span class="min-w-14 text-end text-xs font-semibold tabular-nums" title="{{ __('indicators.percent_achieved') }}">
                            {{ $percentAchieved === null ? '—' : number_format($percentAchieved, 1).'%' }}
                        </span>

                        <x-ui.enum-badge :case="$ragStatus" />
                        <x-ui.enum-badge :case="$indicator->frequency" />
                        <x-ui.enum-badge :case="$indicator->measure" />

                        <span class="inline-flex min-w-6 items-center justify-center rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-secondary-medium dark:text-secondary-soft" title="{{ __('common.targets') }}">
                            {{ $indicator->plan_indicators_count }}
                        </span>

                        <x-ui.button tone="ghost" size="sm" icon="plus-circle" class="size-9!" wire:click="openProgressModal({{ $indicator->id }})" :aria-label="__('indicators.log_a_reading_for_name', ['name' => $indicator->name])" data-test="log-reading-button-{{ $indicator->id }}" />

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $indicator->id }})" :aria-label="__('common.edit_name', ['name' => $indicator->name])" />

                        @if ($indicator->plan_indicators_count === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $indicator->id }})" :aria-label="__('common.delete_name', ['name' => $indicator->name])" />
                        @else
                            <flux:tooltip :content="__('indicators.indicators_carrying_targets_cannot_be_deleted')">
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
            <flux:pagination :paginator="$this->indicators" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#indicator-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showIndicatorModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showIndicatorModal)
        <form wire:submit="saveIndicator" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingIndicatorId === null ? __('indicators.add_indicator') : __('indicators.edit_indicator') }}</flux:heading>
                <flux:subheading>{{ __('indicators.an_indicator_measures_exactly_one_result') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('common.indicator_name')" type="text" maxlength="255" required autofocus />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="indicatorResultId" :label="__('indicators.result')" required>
                    <flux:select.option value="">{{ __('indicators.select_a_result') }}</flux:select.option>
                    @foreach ($this->resultOptions as $resultOption)
                        <flux:select.option :value="$resultOption->id" :wire:key="'ind-result-option-'.$resultOption->id">{{ Str::limit($resultOption->statement, 60) }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="indicatorMeasure" :label="__('indicators.measure')" required>
                    @foreach (IndicatorMeasure::cases() as $measureCase)
                        <flux:select.option :value="$measureCase->value" :wire:key="'ind-measure-option-'.$measureCase->value">{{ $measureCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="frequency" :label="__('indicators.frequency')" required>
                    @foreach (Period::cases() as $periodCase)
                        <flux:select.option :value="$periodCase->value" :wire:key="'ind-frequency-'.$periodCase->value">{{ $periodCase->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="indicatorProjectId" :label="__('common.project')">
                    <flux:select.option value="">{{ __('common.organisation_wide') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'ind-project-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="unit" :label="__('indicators.unit')" type="text" maxlength="50" />
                <x-ui.input wire:model="baseline" :label="__('indicators.baseline')" type="number" step="0.01" />
                <x-ui.input wire:model="target" :label="__('indicators.lifetime_target')" type="number" step="0.01" />
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeIndicatorModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-indicator-button">
                    {{ $editingIndicatorId === null ? __('indicators.create_indicator') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>

    <flux:modal :open="$deletingIndicatorId !== null" wire:close="$set('deletingIndicatorId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('indicators.delete_this_indicator') }}</flux:heading>
            <flux:subheading>{{ __('indicators.only_an_indicator_carrying_no_targets_can_be_deleted') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('deletingIndicatorId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="deleteIndicator" data-test="confirm-delete-indicator">{{ __('indicators.delete_indicator') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>

    <flux:modal :open="$showProgressModal" wire:close="closeProgressModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showProgressModal)
        <form wire:submit="saveProgress" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ __('indicators.log_a_reading') }}</flux:heading>
                <flux:subheading>{{ __('indicators.one_dated_number_with_the_evidence_it_came_from') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="progressDate" :label="__('indicators.date')" type="date" required />
                <x-ui.input wire:model="progressValue" :label="__('indicators.value')" type="number" step="0.01" required />
            </div>

            <x-ui.input wire:model="progressSourceOfVerification" :label="__('indicators.source_of_verification')" type="text" maxlength="255" />

            <flux:textarea wire:model="progressNotes" :label="__('common.notes')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeProgressModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-progress-button">
                    {{ __('indicators.log_reading') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>
</section>
