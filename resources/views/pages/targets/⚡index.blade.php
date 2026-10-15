<?php

use App\Enums\Period;
use App\Enums\ResultType;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\Targets\FiltersTargets;
use App\Livewire\Concerns\Targets\ManagesTargetForm;
use App\Livewire\Concerns\Targets\NarrowsTargetFormOptions;
use App\Livewire\Concerns\Targets\ReadsTargets;
use App\Models\Indicator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.targets')] class extends Component {
    use ChecksFinanceVisibility;
    use FiltersTargets;
    use ManagesTargetForm;
    use NarrowsTargetFormOptions;
    use ReadsTargets;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sourceType = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $period = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $donor = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $year = '';

    /**
     * Narrows the page to one indicator, or to every indicator on one result.
     *
     * Set by deep links from the results framework and the indicator
     * directory rather than by a header picker, so it is shown as a removable
     * chip above the list.
     */
    #[Url(except: '')]
    public string $indicator = '';

    #[Url(except: '')]
    public string $result = '';

    /**
     * `?create=1` (with `?indicator=`) opens the commit form, already tied to
     * that indicator — the "Commit a target" step from the indicator chain.
     */
    public function mount(): void
    {
        if (request()->boolean('create') && Gate::allows('targets.create')) {
            $this->openCreateModal();

            if ($this->indicator !== '' && Indicator::query()->whereKey((int) $this->indicator)->exists()) {
                $this->targetIndicatorId = $this->indicator;
                $this->updatedTargetIndicatorId();
            }
        }
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $sourceType !== '' || $project !== '' || $period !== '' || $status !== '' || $donor !== '' || $program !== '' || $year !== '' || $indicator !== '' || $result !== '';
    $scopeLabel = match (true) {
        $indicator !== '' => __('targets.indicator_scope', ['name' => App\Models\Indicator::query()->whereKey((int) $indicator)->value('name') ?? '—']),
        $result !== '' => __('targets.result_scope', ['statement' => Str::limit(App\Models\Result::query()->whereKey((int) $result)->value('statement') ?? '—', 60)]),
        default => null,
    };
    $sourceTypeLabels = ['annual_plan' => __('common.annual_plan'), 'proposal' => __('common.proposal')];
    $donorRelation = $this->donorRelation();
    $canViewBudgets = $this->canViewAgreements();
    $canOpenProposals = auth()->user()->can('proposals.view');
    $canOpenDonors = auth()->user()->can('donors.view');
    $pulse = $this->pulse;
    $statusMeta = [
        'achieved' => ['label' => __('targets.status_achieved'), 'count' => $pulse['achieved'], 'fill' => 'bg-status-good', 'text' => 'text-status-good', 'dot' => 'bg-status-good'],
        'in_progress' => ['label' => __('targets.status_in_progress'), 'count' => $pulse['in_progress'], 'fill' => 'bg-secondary-dark dark:bg-ink-muted', 'text' => 'text-secondary-dark dark:text-ink', 'dot' => 'bg-secondary-dark dark:bg-ink-muted'],
        'not_started' => ['label' => __('targets.status_not_started'), 'count' => $pulse['not_started'], 'fill' => 'bg-secondary-light', 'text' => 'text-secondary-light', 'dot' => 'bg-secondary-light'],
    ];
    $shareOf = fn (int $part): float => $pulse['total'] > 0 ? round($part / $pulse['total'] * 100, 1) : 0;
    $formatValue = fn (float $value): string => fmod($value, 1.0) === 0.0 ? number_format($value) : number_format($value, 2);
    $resultMark = fn (?ResultType $type): string => match ($type) {
        ResultType::Impact => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome => 'bg-primary ring-primary',
        ResultType::Output, null => 'bg-white ring-secondary-light dark:bg-surface-1',
        ResultType::Activity => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    };
    $groups = $this->targets->groupBy(fn ($target) => $target->indicator->result_id);
@endphp

<section class="@container/directory admin-directory flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="target-directory-heading">
    <x-ui.page-header
        heading-id="target-directory-heading"
        :title="__('common.targets')"
        :count="$this->matchingCount"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('targets.search_targets')" :placeholder="__('common.indicator_name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('targets.committed_in')" wire:model.live="sourceType" :value="$sourceType">
                <option value="">{{ __('targets.any_source') }}</option>
                @foreach ($sourceTypeLabels as $sourceKey => $sourceLabel)
                    <option value="{{ $sourceKey }}">{{ $sourceLabel }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.project')" wire:model.live="project" :value="$project">
                <option value="">{{ __('targets.any_project') }}</option>
                <option value="none">{{ __('common.organisation_wide') }}</option>
                @foreach ($this->projectOptions as $projectOption)
                    <option value="{{ $projectOption->id }}">{{ $projectOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.period')" wire:model.live="period" :value="$period">
                <option value="">{{ __('targets.all_periods') }}</option>
                @foreach (Period::cases() as $periodCase)
                    <option value="{{ $periodCase->value }}">{{ $periodCase->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <option value="">{{ __('targets.any_program') }}</option>
                @foreach ($this->programOptions as $programOption)
                    <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('targets.donor')" wire:model.live="donor" :value="$donor">
                <option value="">{{ __('targets.any_donor') }}</option>
                @foreach ($this->donorOptions as $donorOption)
                    <option value="{{ $donorOption->id }}">{{ $donorOption->name }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('targets.year')" wire:model.live="year" :value="$year">
                <option value="">{{ __('targets.all_years') }}</option>
                @foreach ($this->yearOptions as $yearOption)
                    <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            @can('targets.create')<x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-target-button">
                {{ __('targets.commit_target') }}
            </x-ui.button>@endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Pulse: the filtered portfolio as one sentence and one proportional bar. --}}
    @include('pages.targets._index-pulse')

    @include('pages.targets._index-list')

    @include('pages.targets._index-target-modal')

    <x-ui.confirm-modal
        wire:model="showDeleteModal"
        close="cancelDelete"
        :title="__('targets.delete_this_target')"
        :message="__('targets.only_a_target_with_no_reports_can_be_deleted_otherwise_every')"
        confirm="deleteTarget"
        :confirm-label="__('targets.delete_target')"
        confirm-test="confirm-delete-target"
    />
</section>
