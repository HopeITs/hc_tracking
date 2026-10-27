<?php

use App\Enums\ReportTemplateStatus;
use App\Support\ReportTemplateQueue;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
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
new #[Title('titles.report_templates')] class extends Component {
    /** The governorate filter (HR city id); '' shows every governorate. */
    #[Url(except: '')]
    public string $city = '';

    public function mount(): void
    {
        Gate::authorize('report-templates.manage');
    }

    /**
     * Every queue row of this user, at a fixed query count.
     *
     * @return list<array{template: \App\Models\ReportTemplate, project: \App\Models\Project, status: ReportTemplateStatus, pendingLineCount: int, annualPlanLineCount: int, proposals: list<array{id: int, name: string, number: string}>, cityId: int|null, cityName: string|null, centerName: string|null}>
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

    /**
     * @return list<array{template: \App\Models\ReportTemplate, project: \App\Models\Project, status: ReportTemplateStatus, pendingLineCount: int, annualPlanLineCount: int, proposals: list<array{id: int, name: string, number: string}>, cityId: int|null, cityName: string|null, centerName: string|null}>
     */
    #[Computed]
    public function visibleRows(): array
    {
        if ($this->selectedCity === '') {
            return $this->rows;
        }

        return array_values(array_filter($this->rows, fn (array $row): bool => $row['cityId'] === (int) $this->selectedCity));
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $rows = $this->visibleRows;
@endphp

<section class="@container flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="report-templates-heading" data-report-template-queue>
    <x-ui.page-header heading-id="report-templates-heading" :title="__('titles.report_templates')" :count="count($rows)">
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
    </x-ui.page-header>

    @if ($this->rows === [])
        <x-ui.empty-state icon="clipboard-document-check" :heading="__('report_templates.queue.empty_heading')" :body="__('report_templates.queue.empty_body')" />
    @else
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
                <th class="w-44!">{{ __('report_templates.queue.template') }}</th>
                <th class="w-32! text-end!">{{ __('report_templates.queue.pending_lines') }}</th>
                <th class="w-72!">{{ __('report_templates.queue.proposals') }}</th>
                <th class="w-16!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($rows as $row)
                @php
                    $project = $row['project'];
                    $place = collect([$row['cityName'], $row['centerName']])->filter()->implode(' · ');
                @endphp
                <tr wire:key="queue-{{ $row['template']->id }}" data-queue-row="{{ $project->id }}">
                    <x-ui.data-table.cell :label="__('report_templates.queue.project')">
                        <span class="block font-semibold" dir="auto">{{ $project->name }}</span>
                        <span class="block text-xs {{ $muted }}" dir="auto">{{ $place !== '' ? $place : __('common.unknown_city') }}</span>
                    </x-ui.data-table.cell>
                    <x-ui.data-table.cell :label="__('report_templates.queue.template')">
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
                                tone="ghost"
                                size="sm"
                                icon="eye"
                                class="size-8!"
                                :href="route('projects.show', [$project, 'tab' => 'template'])"
                                wire:navigate
                                data-queue-open
                                :aria-label="__('report_templates.queue.open', ['name' => $project->name])"
                            />
                        </span>
                    </x-ui.data-table.cell>
                </tr>
            @endforeach
        </x-ui.data-table>
    @endif
</section>
