{{-- The filters bar of ⚡index.blade.php (M-06): Proposal stays visible beside the search; City, Program, Center, From / To (D-79, read through ReportExtraction) and the queue's Status sit behind "More filters", open by itself while one of them is set or From / To is refused. Clear is the one way back. --}}
@use('App\Enums\MonthlyReportStatus')
@php
    $listFilter = $this->extractionFilter;
    $centerValue = match (true) {
        $listFilter->cityWide && $listFilter->cityId !== null => 'field-'.$listFilter->cityId,
        $listFilter->centerUnset => 'unset',
        $listFilter->centerId !== null => 'c'.$listFilter->centerId,
        default => '',
    };
    $moreOpen = $this->moreFiltersAreActive || $errors->has('from');
    $monthFieldClass = 'block h-9 w-full min-w-0 touch-manipulation rounded-[10px] border border-(--line-2) bg-(--paper) px-2.5 text-[12.5px] font-semibold text-(--ink) hover:border-(--ink-3) max-[720px]:h-11 aria-invalid:border-status-critical';
    $monthLabelClass = 'mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-ink-muted';
@endphp

<div class="contents" data-list-filters>
    <x-ui.header-filter-select :label="__('reports.matrix.proposal')" wire:model.live="proposal" :value="(string) ($listFilter->proposalId ?? '')">
        <option value="">{{ __('reports.matrix.all_proposals') }}</option>
        @foreach ($this->proposalOptions as $option)
            <option value="{{ $option->id }}">{{ $option->name }}</option>
        @endforeach
    </x-ui.header-filter-select>

    <div class="contents" x-data="{ open: @js($moreOpen) }" data-more-open="{{ $moreOpen ? 'true' : 'false' }}">
        <div class="flex items-end">
            <button type="button" class="dk-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="monthly-reports-more-filters" data-more-filters-button>
                <flux:icon.adjustments-horizontal variant="micro" class="size-4" aria-hidden="true" />
                {{ __('monthly_reports.more_filters') }}
            </button>
        </div>

        <div id="monthly-reports-more-filters" class="contents" x-show="open" @unless ($moreOpen) x-cloak @endunless data-more-filters-panel>
            <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="(string) ($listFilter->cityId ?? '')">
                <option value="">{{ __('monthly_reports.any_city') }}</option>
                @foreach ($this->cityOptions as $cityId => $cityName)
                    <option value="{{ $cityId }}">{{ $cityName }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('reports.matrix.program')" wire:model.live="program" :value="(string) ($listFilter->programId ?? '')">
                <option value="">{{ __('reports.matrix.all_programs') }}</option>
                @foreach ($this->programOptions as $programId => $programName)
                    <option value="{{ $programId }}">{{ $programName }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('reports.matrix.center')" wire:model.live="center" :value="$centerValue">
                <option value="">{{ __('reports.matrix.all_centers') }}</option>
                @foreach ($this->centerOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </x-ui.header-filter-select>

            <div class="min-w-0" data-list-from>
                <label for="monthly-reports-from" class="{{ $monthLabelClass }}">{{ __('reports.matrix.from') }}</label>
                <input id="monthly-reports-from" type="month" wire:model.live="from" class="{{ $monthFieldClass }}" dir="ltr" @error('from') aria-invalid="true" aria-describedby="monthly-reports-from-error" @enderror />
                @error('from')
                    <p id="monthly-reports-from-error" class="mt-1 flex items-center gap-1 text-xs text-status-critical" data-list-from-error>
                        <flux:icon.exclamation-triangle variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                        <span>{{ $message }}</span>
                    </p>
                @enderror
            </div>

            <div class="min-w-0" data-list-to>
                <label for="monthly-reports-to" class="{{ $monthLabelClass }}">{{ __('reports.matrix.to') }}</label>
                <input id="monthly-reports-to" type="month" wire:model.live="to" class="{{ $monthFieldClass }}" dir="ltr" />
            </div>

            @if ($tab === 'validate')
                <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$this->selectedStatus?->value ?? 'all'">
                    <option value="submitted">{{ __('monthly_reports.status_to_validate') }}</option>
                    <option value="validated">{{ MonthlyReportStatus::Validated->label() }}</option>
                    <option value="all">{{ __('monthly_reports.status_all') }}</option>
                </x-ui.header-filter-select>
            @endif
        </div>
    </div>

    @if ($filtersAreActive)
        <div class="flex items-end">
            <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="clearFilters"><x-desk.icon name="x" />{{ __('desk.register.clear') }}</button>
        </div>
    @endif
</div>
