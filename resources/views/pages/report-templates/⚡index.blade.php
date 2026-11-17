<?php

use App\Enums\ReportTemplateStatus;
use App\Support\ReportTemplateQueue;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
| MEAL's "Templates to confirm" queue (contracts.md §12.4, T33 slice 3): the
| pending templates and the confirmed templates with new pending lines of the
| projects this user covers (all for the MEAL Manager slot holder). Each row
| opens the project's Report template tab, where the template is confirmed.
| Read-only, no money.
*/
new #[Layout('layouts::desk'), Title('titles.report_templates')] class extends Component {
    /** The governorate filter (HR city id); '' shows every governorate. */
    #[Url(except: '')]
    public string $city = '';

    /** The status button pressed: pending (new template) or lines (lines added). Empty shows both. */
    #[Url(except: '')]
    public string $focus = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('report-templates.manage');
    }

    /**
     * Every queue row of this user, at a fixed query count.
     *
     * @return list<array{template: \App\Models\ReportTemplate, project: object|null, name: string, status: ReportTemplateStatus, pendingLineCount: int, annualPlanLineCount: int, proposals: list<array{id: int, name: string, number: string}>, cityId: int|null, cityName: string|null, centerName: string|null}>
     */
    #[Computed]
    public function rows(): array
    {
        return ReportTemplateQueue::rowsFor(auth()->user());
    }

    /**
     * The governorates present in the queue, by name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function cityOptions(): array
    {
        $options = [];

        foreach ($this->rows as $row) {
            if ($row['cityId'] !== null) {
                $options[$row['cityId']] = $row['cityName'] ?? __('common.unknown_city');
            }
        }

        asort($options);

        return $options;
    }

    /** The filter as applied: an id not in the queue reads as every governorate. */
    #[Computed]
    public function selectedCity(): string
    {
        return array_key_exists((int) $this->city, $this->cityOptions) ? $this->city : '';
    }

    /** Press a status button to narrow the list; press it again to show everything. */
    public function setFocus(string $focus): void
    {
        $this->focus = $this->focus === $focus || ! in_array($focus, ['pending', 'lines'], true) ? '' : $focus;
    }

    public function clearFilters(): void
    {
        $this->focus = '';
        $this->search = '';
        $this->city = '';
    }

    /**
     * The rows that match the city and the search, whatever button is pressed.
     * The two buttons count these, so a click shows exactly that many.
     *
     * @return list<array{template: \App\Models\ReportTemplate, project: object|null, name: string, status: ReportTemplateStatus, pendingLineCount: int, annualPlanLineCount: int, proposals: list<array{id: int, name: string, number: string}>, cityId: int|null, cityName: string|null, centerName: string|null}>
     */
    #[Computed]
    public function matchingRows(): array
    {
        $term = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->rows, function (array $row) use ($term): bool {
            if ($this->selectedCity !== '' && $row['cityId'] !== (int) $this->selectedCity) {
                return false;
            }

            if ($term === '') {
                return true;
            }

            $haystack = collect([$row['name'], $row['cityName'], $row['centerName'], ...array_column($row['proposals'], 'name')])->filter()->implode(' ');

            return str_contains(mb_strtolower($haystack), $term);
        }));
    }

    /**
     * New templates and templates that gained lines. Disjoint, they add up to the page count.
     *
     * @return array{pending: int, lines: int, total: int}
     */
    #[Computed]
    public function focusCounts(): array
    {
        $pending = count(array_filter($this->matchingRows, fn (array $row): bool => $row['status'] === ReportTemplateStatus::Pending));

        return ['pending' => $pending, 'lines' => count($this->matchingRows) - $pending, 'total' => count($this->matchingRows)];
    }

    /**
     * @return list<array{template: \App\Models\ReportTemplate, project: object|null, name: string, status: ReportTemplateStatus, pendingLineCount: int, annualPlanLineCount: int, proposals: list<array{id: int, name: string, number: string}>, cityId: int|null, cityName: string|null, centerName: string|null}>
     */
    #[Computed]
    public function visibleRows(): array
    {
        if ($this->focus === '') {
            return $this->matchingRows;
        }

        $wantsPending = $this->focus === 'pending';

        return array_values(array_filter($this->matchingRows, fn (array $row): bool => ($row['status'] === ReportTemplateStatus::Pending) === $wantsPending));
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $rows = $this->visibleRows;
    $focusCounts = $this->focusCounts;
    $tabs = [
        ['key' => 'pending', 'label' => __('report_templates.queue.new_templates'), 'tone' => 'text-status-warn'],
        ['key' => 'lines', 'label' => __('report_templates.queue.lines_added'), 'tone' => 'text-status-active'],
    ];
@endphp

<x-desk.register class="@container" heading-id="report-templates-heading" :title="__('titles.report_templates')" :count="$focusCounts['total']" data-report-template-queue>
    <x-slot:search>
        <x-desk.register-search :label="__('report_templates.queue.search')" :placeholder="__('report_templates.queue.search_placeholder')" wire:model.live.debounce.300ms="search" data-test="queue-search" />
    </x-slot:search>

    @if ($this->cityOptions !== [])
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="$this->selectedCity">
                <option value="">{{ __('report_templates.queue.all_cities') }}</option>
                @foreach ($this->cityOptions as $cityId => $cityName)
                    <option value="{{ $cityId }}">{{ $cityName }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>
    @endif

    @if ($this->rows === [])
        <x-ui.empty-state icon="clipboard-document-check" :heading="__('report_templates.queue.empty_heading')" :body="__('report_templates.queue.empty_body')" />
    @else
        <div class="mb-4 flex flex-wrap items-stretch gap-2" data-test="queue-focus-tabs">
            @foreach ($tabs as $tab)
                <button
                    type="button"
                    wire:click="setFocus('{{ $tab['key'] }}')"
                    wire:key="queue-tab-{{ $tab['key'] }}"
                    aria-pressed="{{ $focus === $tab['key'] ? 'true' : 'false' }}"
                    data-test="queue-tab-{{ $tab['key'] }}"
                    @class([
                        'flex min-h-14 flex-1 basis-40 cursor-pointer items-center gap-3 rounded-xl border px-4 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                        'border-secondary-dark bg-white shadow-card ring-1 ring-secondary-dark dark:border-ink dark:bg-surface-2 dark:ring-ink' => $focus === $tab['key'],
                        'border-secondary-soft bg-white hover:border-secondary-light dark:border-line dark:bg-surface-1' => $focus !== $tab['key'],
                    ])
                >
                    <span class="text-2xl font-bold leading-none tabular-nums {{ $tab['tone'] }}">{{ $focusCounts[$tab['key']] }}</span>
                    <span class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $tab['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($this->rows !== [] && $rows === [])
        <x-ui.empty-state icon="clipboard-document-check" :heading="__('desk.register.nothing_matches')" :body="__('report_templates.queue.empty_body')">
            <x-slot:action>
                <x-ui.button tone="ghost" size="sm" wire:click="clearFilters" data-test="reset-filters">{{ __('common.reset_filters') }}</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    @elseif ($rows !== [])
        <x-ui.data-table
            :caption="__('titles.report_templates')"
            wire:loading.class="opacity-60"
            wire:target="city"
            x-data="{ move(step) { const links = [...$el.querySelectorAll('[data-queue-open]')]; const index = links.indexOf(document.activeElement); if (index !== -1) { links[Math.min(Math.max(index + step, 0), links.length - 1)].focus(); } } }"
            x-on:keydown.down.prevent="move(1)"
            x-on:keydown.up.prevent="move(-1)"
            data-tour="report-template-queue-list"
        >
            <x-slot:head>
                <th>{{ __('report_templates.queue.project') }}</th>
                <th class="w-44!">{{ __('common.state') }}</th>
                <th class="w-32! text-end!">{{ __('report_templates.queue.pending_lines') }}</th>
                <th class="w-72!">{{ __('report_templates.queue.proposals') }}</th>
                <th class="w-36!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($rows as $row)
                @php
                    $project = $row['project'];
                    $openProposal = $row['templateProposals'][0]['id'] ?? null;
                    $place = collect([$row['cityName'], $row['centerName']])->filter()->implode(' · ');
                @endphp
                <tr wire:key="queue-{{ $row['template']->id }}" data-queue-row="{{ $project?->id ?? 't'.$row['template']->id }}">
                    <x-ui.data-table.cell :label="__('report_templates.queue.project')">
                        <span class="block font-semibold" dir="auto">{{ $row['name'] }}</span>
                        <span class="block text-xs {{ $muted }}" dir="auto">{{ $place !== '' ? $place : __('common.unknown_city') }}</span>
                    </x-ui.data-table.cell>
                    <x-ui.data-table.cell :label="__('common.state')">
                        @if ($row['status'] === ReportTemplateStatus::Pending)
                            <span class="inline-flex items-center gap-1 rounded-full bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn" data-queue-status="pending">
                                <flux:icon.clock variant="micro" class="size-4" />
                                {{ $row['status']->label() }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-status-active-soft px-2 py-0.5 text-xs font-semibold text-status-active" data-queue-status="lines_added">
                                <flux:icon.plus-circle variant="micro" class="size-4" />
                                {{ __('report_templates.queue.lines_added') }}
                            </span>
                        @endif
                    </x-ui.data-table.cell>
                    <x-ui.data-table.cell :label="__('report_templates.queue.pending_lines')" numeric>
                        <bdi dir="ltr" data-pending-lines="{{ $row['pendingLineCount'] }}">{{ $row['pendingLineCount'] }}</bdi>
                    </x-ui.data-table.cell>
                    <x-ui.data-table.cell :label="__('report_templates.queue.proposals')">
                        <span class="flex flex-col gap-1">
                            @foreach ($row['proposals'] as $proposal)
                                <span class="flex flex-wrap items-center gap-x-1.5" data-queue-proposal="{{ $proposal['id'] }}">
                                    <span dir="auto">{{ $proposal['name'] }}</span>
                                    <x-ui.project-number :number="$proposal['number']" class="text-xs {{ $muted }}" />
                                </span>
                            @endforeach
                            @if ($row['annualPlanLineCount'] > 0)
                                <span class="text-xs {{ $muted }}">{{ trans_choice('report_templates.queue.annual_plan_lines', $row['annualPlanLineCount'], ['count' => $row['annualPlanLineCount']]) }}</span>
                            @endif
                            @if ($row['proposals'] === [] && $row['annualPlanLineCount'] === 0)
                                <span class="{{ $muted }}">—</span>
                            @endif
                        </span>
                    </x-ui.data-table.cell>
                    <x-ui.data-table.cell>
                        <span class="flex items-center justify-end">
                            <x-ui.button
                                size="sm"
                                icon="eye"
                                class="min-h-8 touch-manipulation"
                                :href="$project !== null ? route('projects.show', [$project, 'tab' => 'template']) : route('proposals.show', [$openProposal])"
                                wire:navigate
                                data-queue-open
                                :aria-label="__('report_templates.queue.open', ['name' => $row['name']])"
                            >{{ __('report_templates.queue.review') }}</x-ui.button>
                        </span>
                    </x-ui.data-table.cell>
                </tr>
            @endforeach
        </x-ui.data-table>
    @endif
</x-desk.register>
