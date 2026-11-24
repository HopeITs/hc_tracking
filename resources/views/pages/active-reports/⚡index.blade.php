<?php

use App\Enums\ActiveReportType;
use App\Models\Donor;
use App\Models\Program;
use App\Support\Reporting\ActiveReportRow;
use App\Support\Reporting\ActiveReports;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Active Reports (M-02): one table of every report still open, whatever its
| kind: monthly, donor and existing reports. "Needs me" lists what waits on
| the viewer; "All" lists everything they may open. Each row opens its own
| record; permissions are each record's own, none is added here.
*/
new #[Layout('layouts::desk'), Title('titles.active_reports')] class extends Component {
    use WithPagination;

    private const PerPage = 25;

    #[Url(except: 'needs')]
    public string $tab = 'needs';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $donor = '';

    /** The month a report covers, 'Y-m'. */
    #[Url(except: '')]
    public string $period = '';

    public function mount(): void
    {
        $this->normaliseFilters();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'type', 'status', 'program', 'donor', 'period'], true)) {
            $this->normaliseFilters();
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'status', 'program', 'donor', 'period']);
        $this->resetPage();
    }

    /**
     * Every open report the viewer may open, before any tab or filter.
     *
     * @return Collection<int, ActiveReportRow>
     */
    #[Computed]
    public function rows(): Collection
    {
        return app(ActiveReports::class)->for(auth()->user());
    }

    /**
     * The rows the filters keep, whichever tab: the tab counts read these.
     *
     * @return Collection<int, ActiveReportRow>
     */
    #[Computed]
    public function filtered(): Collection
    {
        $needle = mb_strtolower(trim($this->search));

        return $this->rows->filter(fn (ActiveReportRow $row): bool => ($this->type === '' || $row->type->value === $this->type)
            && ($this->status === '' || $row->status->value === $this->status)
            && ($this->program === '' || $row->programId === (int) $this->program)
            && ($this->donor === '' || $row->donorId === (int) $this->donor)
            && ($this->period === '' || $row->periodKey === $this->period)
            && ($needle === '' || str_contains($row->search, $needle)))->values();
    }

    /**
     * @return array{needs: int, all: int}
     */
    #[Computed]
    public function counts(): array
    {
        return ['needs' => $this->filtered->where('needsMe', true)->count(), 'all' => $this->filtered->count()];
    }

    /**
     * The open tab's page, cut in memory after filtering.
     *
     * @return LengthAwarePaginator<int, ActiveReportRow>
     */
    #[Computed]
    public function page(): LengthAwarePaginator
    {
        $rows = $this->tab === 'needs' ? $this->filtered->where('needsMe', true)->values() : $this->filtered;
        $current = max(1, $this->getPage());

        return new LengthAwarePaginator($rows->forPage($current, self::PerPage)->values(), $rows->count(), self::PerPage, $current, ['path' => route('active-reports.index')]);
    }

    /**
     * The statuses that exist among the viewer's open reports, for the filter.
     *
     * @return array<string, string> value => label
     */
    #[Computed]
    public function statusOptions(): array
    {
        return $this->rows->unique(fn (ActiveReportRow $row): string => $row->status->value)
            ->mapWithKeys(fn (ActiveReportRow $row): array => [$row->status->value => $row->status->label()])
            ->sort()
            ->all();
    }

    /**
     * The programs of the viewer's open reports, for the filter.
     *
     * @return array<int, string> id => name
     */
    #[Computed]
    public function programOptions(): array
    {
        $ids = $this->rows->pluck('programId')->filter()->unique()->all();

        return Program::options()->filter(fn (Program $program): bool => in_array($program->id, $ids, true))
            ->mapWithKeys(fn (Program $program): array => [$program->id => $program->name])
            ->all();
    }

    /**
     * The donors of the viewer's open reports, for the filter.
     *
     * @return Collection<int, Donor>
     */
    #[Computed]
    public function donorOptions(): Collection
    {
        $ids = $this->rows->pluck('donorId')->filter()->unique()->all();

        return $ids === [] ? new Collection : Donor::query()->whereKey($ids)->orderBy('name')->get(['id', 'name']);
    }

    private function normaliseFilters(): void
    {
        if (! in_array($this->tab, ['needs', 'all'], true)) {
            $this->tab = 'needs';
        }

        if (! in_array($this->type, ActiveReportType::values(), true)) {
            $this->type = '';
        }

        foreach (['program', 'donor'] as $filter) {
            if (! ctype_digit($this->{$filter})) {
                $this->{$filter} = '';
            }
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) !== 1) {
            $this->period = '';
        }
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $page = $this->page;
    $moreFiltersOn = $status !== '' || $program !== '' || $donor !== '' || $period !== '';
    $monthFieldClass = 'block h-9 w-full min-w-0 touch-manipulation rounded-[10px] border border-(--line-2) bg-(--paper) px-2.5 text-[12.5px] font-semibold text-(--ink) hover:border-(--ink-3) max-[720px]:h-11';
    $monthLabelClass = 'mb-1.5 block text-[11px] font-semibold uppercase leading-4 tracking-[0.1em] text-secondary-medium dark:text-ink-muted';
@endphp

<x-desk.register
    class="@container"
    heading-id="active-reports-heading"
    :title="__('titles.active_reports')"
    :count="$this->counts[$tab]"
    :view="$tab"
    view-model="tab"
    :views="[
        'needs' => ['icon' => 'check', 'label' => __('active_reports.tab_needs').' · '.$this->counts['needs'], 'test' => 'active-reports-tab-needs'],
        'all' => ['icon' => 'table', 'label' => __('active_reports.tab_all').' · '.$this->counts['all'], 'test' => 'active-reports-tab-all'],
    ]"
    data-active-reports
>
    <x-slot:search>
        <x-desk.register-search :label="__('active_reports.search_reports')" :placeholder="__('active_reports.search_placeholder')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>
    <x-slot:filters>
        <x-ui.header-filter-select :label="__('active_reports.type')" wire:model.live="type" :value="$type">
            <option value="">{{ __('active_reports.all_types') }}</option>
            @foreach (ActiveReportType::cases() as $typeOption)
                <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
            @endforeach
        </x-ui.header-filter-select>

        <div class="contents" x-data="{ open: @js($moreFiltersOn) }">
            <button type="button" class="dk-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" data-test="more-filters-button">
                <flux:icon.adjustments-horizontal variant="micro" class="size-4" />
                {{ __('active_reports.more_filters') }}
            </button>

            <div class="contents" x-show="open" x-cloak>
                <x-ui.header-filter-select :label="__('active_reports.status')" wire:model.live="status" :value="$status">
                    <option value="">{{ __('active_reports.all_statuses') }}</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('active_reports.program')" wire:model.live="program" :value="$program">
                    <option value="">{{ __('active_reports.all_programs') }}</option>
                    @foreach ($this->programOptions as $programId => $programName)
                        <option value="{{ $programId }}">{{ $programName }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('active_reports.donor')" wire:model.live="donor" :value="$donor">
                    <option value="">{{ __('active_reports.all_donors') }}</option>
                    @foreach ($this->donorOptions as $donorOption)
                        <option value="{{ $donorOption->id }}">{{ $donorOption->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <div class="min-w-0">
                    <label for="active-reports-period" class="{{ $monthLabelClass }}">{{ __('active_reports.period') }}</label>
                    <input id="active-reports-period" type="month" wire:model.live="period" class="{{ $monthFieldClass }}" dir="ltr" />
                </div>
            </div>
        </div>
    </x-slot:filters>

    @if ($this->rows->isEmpty())
        <x-ui.empty-state icon="document-check" :heading="__('active_reports.none')" :body="__('active_reports.none_body')" />
    @elseif ($page->isEmpty())
        @if ($tab === 'needs' && $this->counts['needs'] === 0 && $this->filtered->isNotEmpty())
            <x-ui.empty-state icon="check-circle" :heading="__('active_reports.none_needs')" :body="__('active_reports.none_needs_body')">
                <x-ui.button size="sm" wire:click="$set('tab', 'all')">{{ __('active_reports.show_all') }}</x-ui.button>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state icon="document-check" :heading="__('active_reports.no_matching')" :body="__('common.change_the_search_or_filters_and_try_again')" />
        @endif
    @else
        <x-ui.data-table :caption="__('titles.active_reports')" data-tour="active-reports-list">
            <x-slot:head>
                <th class="w-36!">{{ __('active_reports.type') }}</th>
                <th>{{ __('active_reports.report') }}</th>
                <th>{{ __('active_reports.context') }}</th>
                <th class="w-28!">{{ __('active_reports.period') }}</th>
                <th class="w-36!">{{ __('active_reports.status') }}</th>
                <th class="w-40!">{{ __('active_reports.due') }}</th>
                <th class="w-44!">{{ __('active_reports.owner') }}</th>
                <th class="w-16!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($page as $row)
                <tr wire:key="active-report-{{ $row->key() }}" data-active-report="{{ $row->key() }}">
                    <td>{{ $row->type->label() }}</td>
                    <td class="font-semibold" dir="auto">{{ $row->name }}</td>
                    <td dir="auto">{{ $row->context }}</td>
                    <td class="tabular-nums" dir="ltr">{{ $row->periodKey ?? '—' }}</td>
                    <td><x-ui.enum-badge :case="$row->status" /></td>
                    <td>
                        {{ $row->due?->translatedFormat(__('formats.date')) ?? '—' }}
                        @if ($row->overdue)
                            <span class="block text-xs font-semibold text-status-critical" title="{{ __('active_reports.overdue_by') }}">{{ __('active_reports.overdue') }}</span>
                        @endif
                    </td>
                    <td dir="auto">
                        @if ($row->owner !== null)
                            {{ $row->owner }}
                        @else
                            <span class="{{ $muted }}">{{ __('active_reports.not_assigned') }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="flex items-center justify-end">
                            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="$row->url" wire:navigate :aria-label="__('common.view_name', ['name' => $row->name])" />
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-ui.data-table>

        <div class="mt-4">
            <flux:pagination :paginator="$page" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#active-reports-heading" />
        </div>
    @endif
</x-desk.register>
