<?php

use App\Enums\ReportTemplateStatus;
use App\Enums\TemplatePlacementStatus;
use App\Models\ReportTemplate;
use App\Models\ReportTemplatePlacement;
use App\Models\PlanIndicator;
use App\Actions\ReportTemplates\JoinReportTemplate;
use App\Actions\ReportTemplates\CreateReportTemplateForProposal;
use App\Support\ReportTemplatePrefiller;
use Flux\Flux;
use Livewire\Attributes\Locked;
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
| Placement decisions are authorized on the server; no money is queried.
*/
new #[Layout('layouts::desk'), Title('titles.report_templates')] class extends Component {
    /** The governorate filter (HR city id); '' shows every governorate. */
    #[Url(except: '')]
    public string $city = '';

    /** The status button pressed: pending (new template), lines (lines added), or placement (MEAL choice). Empty shows all. */
    #[Url(except: '')]
    public string $focus = '';

    #[Url(except: '')]
    public string $search = '';

    #[Locked]
    public ?int $placementId = null;

    public bool $showPlacementModal = false;

    public bool $showPlacementConfirm = false;

    #[Locked]
    public string $placementMode = 'join';

    public string $selectedPlacementTemplateId = '';

    /** @var list<int|string> */
    public array $selectedPlacementTargets = [];

    #[Computed]
    public function placement(): ?ReportTemplatePlacement
    {
        if ($this->placementId === null) {
            return null;
        }
        $placement = ReportTemplatePlacement::query()->with(['proposal', 'city', 'program'])->findOrFail($this->placementId);
        Gate::authorize('manage', $placement);

        return $placement;
    }

    #[Computed]
    public function placementContext(): string
    {
        if ($this->placement === null) {
            return '';
        }
        $placement = $this->placement;
        $center = $placement->city_wide ? __('report_templates.queue.field_work')
            : \App\Models\HrCenter::remembered($placement->center_id)?->name;

        return collect([$placement->proposal->name, $placement->city?->name,
            $placement->program->name, $center])->filter()->implode(' · ');
    }

    #[Computed]
    public function placementTemplates(): \Illuminate\Support\Collection
    {
        if ($this->placement === null) {
            return collect();
        }

        return ReportTemplate::query()->atPlace($this->placement->place())
            ->whereHas('proposals', fn ($query) => $query->whereKeyNot($this->placement->proposal_id))
            ->with('proposals:id,name')->withCount(['lines as confirmed_count' => fn ($query) => $query->where('status', 'confirmed')])
            ->orderBy('id')->get();
    }

    #[Computed]
    public function placementTargets(): array
    {
        if ($this->placement === null) {
            return [];
        }

        $planned = app(ReportTemplatePrefiller::class)->plannedLines($this->placement->proposal, $this->placement->place())['lines'];
        $template = $this->placementTemplates->firstWhere('id', (int) $this->selectedPlacementTemplateId);
        $shared = $template === null ? [] : $template->lines()->where('status', '!=', 'retired')
            ->join('plan_indicators as p', 'p.id', '=', 'report_template_lines.plan_indicator_id')
            ->join('indicators as i', 'i.id', '=', 'p.indicator_id')->pluck('i.library_indicator_id')->all();

        return PlanIndicator::query()->whereIn('id', array_keys($planned))
            ->where('source_type', 'proposal')->where('source_id', $this->placement->proposal_id)
            ->with('indicator')->get()->map(fn ($target): array => [
                'id' => $target->id, 'name' => $target->indicator->name,
                'shared' => in_array($target->indicator->library_indicator_id, $shared, true),
            ])->all();
    }

    public function openPlacement(int $id): void
    {
        Gate::authorize('report-templates.manage');
        $this->placementId = $id;
        unset($this->placement, $this->placementTemplates, $this->placementTargets);
        Gate::authorize('manage', $this->placement);
        $this->resetValidation();
        $this->selectedPlacementTemplateId = '';
        $this->selectedPlacementTargets = array_column($this->placementTargets, 'id');
        $this->showPlacementModal = true;
    }

    public function updatedSelectedPlacementTemplateId(): void
    {
        unset($this->placementTargets);
        $this->selectedPlacementTargets = array_column($this->placementTargets, 'id');
    }

    public function askJoinPlacement(): void
    {
        Gate::authorize('manage', $this->placement);
        $this->validate(['selectedPlacementTemplateId' => ['required', 'integer']]);
        $this->placementTemplates->firstWhere('id', (int) $this->selectedPlacementTemplateId) ?? abort(404);
        $this->placementMode = 'join';
        $this->showPlacementModal = false;
        $this->showPlacementConfirm = true;
    }

    public function askCreatePlacement(int $id): void
    {
        $this->openPlacement($id);
        $this->placementMode = 'create';
        $this->showPlacementModal = false;
        $this->showPlacementConfirm = true;
    }

    public function decidePlacement(): void
    {
        Gate::authorize('manage', $this->placement);
        $placement = $this->placement;
        $this->placementMode === 'join'
            ? app(JoinReportTemplate::class)->handle($placement->proposal,
                $this->placementTemplates->firstWhere('id', (int) $this->selectedPlacementTemplateId) ?? abort(404),
                array_map(intval(...), $this->selectedPlacementTargets))
            : app(CreateReportTemplateForProposal::class)->handle($placement->proposal, $placement->place());

        $this->showPlacementConfirm = false;
        $this->placementId = null;
        unset($this->rows, $this->matchingRows, $this->visibleRows, $this->focusCounts, $this->cityOptions,
            $this->placement, $this->placementTemplates, $this->placementTargets);
        Flux::toast(variant: 'success', text: __('report_templates.placement_saved'));
    }

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
        return [
            ...array_map(fn (array $row): array => [...$row, 'category' => $row['status'] === ReportTemplateStatus::Pending ? 'pending' : 'lines'], ReportTemplateQueue::rowsFor(auth()->user())),
            ...ReportTemplateQueue::placementRowsFor(auth()->user()),
        ];
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
        $this->focus = $this->focus === $focus || ! in_array($focus, ['pending', 'lines', 'placement'], true) ? '' : $focus;
    }

    public function clearFilters(): void
    {
        $this->focus = '';
        $this->search = '';
        $this->city = '';
    }

    /**
     * The rows that match the city and the search, whatever button is pressed.
     * The three buttons count these, so a click shows exactly that many.
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
     * New templates, added lines and pending choices add up to the page count.
     *
     * @return array{pending: int, lines: int, placement: int, total: int}
     */
    #[Computed]
    public function focusCounts(): array
    {
        $counts = ['pending' => 0, 'lines' => 0, 'placement' => 0, 'total' => count($this->matchingRows)];
        foreach ($this->matchingRows as $row) {
            $counts[$row['category']]++;
        }

        return $counts;
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

        return array_values(array_filter($this->matchingRows, fn (array $row): bool => $row['category'] === $this->focus));
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $rows = $this->visibleRows;
    $focusCounts = $this->focusCounts;
    $tabs = [
        ['key' => 'placement', 'label' => __('report_templates.placement_choose'), 'tone' => 'text-status-warn'],
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
                    @if ($focus === $tab['key'])
                        <x-ui.focus-clear-mark :label="__('report_templates.queue.focus_press_again')" data-test="focus-tab-clear-{{ $tab['key'] }}" />
                    @endif
                </button>
            @endforeach
        </div>

        @if ($focus !== '' && isset($focusCounts[$focus]))
            <x-ui.focus-showing
                class="-mt-2 mb-4"
                :text="__('report_templates.queue.focus_showing', ['shown' => $focusCounts[$focus], 'total' => $focusCounts['total']])"
                action="setFocus('{{ $focus }}')"
                :label="__('report_templates.queue.focus_show_all')"
                data-test="focus-showing"
            />
        @endif
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
                @if ($row['category'] === 'placement')
                    <tr wire:key="placement-{{ $row['placement']->id }}" data-placement-row="{{ $row['placement']->id }}">
                        <x-ui.data-table.cell :label="__('report_templates.queue.project')">
                            <span class="block font-semibold" dir="auto">{{ $row['name'] }}</span>
                            <span class="block text-xs {{ $muted }}" dir="auto">{{ $row['cityName'] }} · {{ $row['programName'] }} · {{ $row['centerName'] }}</span>
                        </x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('common.state')">{{ __('report_templates.placement_choose') }}</x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('report_templates.queue.pending_lines')">—</x-ui.data-table.cell>
                        <x-ui.data-table.cell :label="__('report_templates.queue.proposals')"><span dir="auto">{{ $row['name'] }}</span></x-ui.data-table.cell>
                        <x-ui.data-table.cell>
                            <div class="flex flex-wrap gap-2">
                                <x-ui.button tone="primary" size="sm" wire:click="openPlacement({{ $row['placement']->id }})" wire:loading.attr="disabled">{{ __('report_templates.placement_join') }}</x-ui.button>
                                <x-ui.button size="sm" wire:click="askCreatePlacement({{ $row['placement']->id }})" wire:loading.attr="disabled">{{ __('report_templates.placement_create') }}</x-ui.button>
                            </div>
                        </x-ui.data-table.cell>
                    </tr>
                    @continue
                @endif
                @php
                    $project = $row['project'];
                    $openProposal = $row['templateProposals'][0]['id'] ?? null;
                    $place = collect([$row['cityName'], $row['centerName']])->filter()->implode(' · ');
                @endphp
                <tr wire:key="queue-{{ $row['template']->id }}" data-queue-row="{{ $project?->id ?? 't'.$row['template']->id }}">
                    <x-ui.data-table.cell :label="__('report_templates.queue.project')">
                        <span class="block font-semibold" dir="auto">{{ $row['name'] }}</span>
                        <span class="block text-xs {{ $muted }}" dir="auto">{{ $place !== '' ? $place : __('common.unknown_city') }}</span>
                        @if ($row['template']->place()->isUnset())
                            <span class="block text-xs {{ $muted }}" data-center-unset>{{ __('report_templates.placement_center_missing') }}</span>
                            @if ($openProposal !== null && auth()->user()->can('proposals.update'))
                                <a href="{{ route('proposals.show', $openProposal) }}" wire:navigate class="text-xs underline">{{ __('report_templates.placement_center_missing') }}</a>
                            @endif
                        @endif
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
                                :href="$project !== null ? route('projects.show', [$project, 'tab' => 'template', 'template' => $row['template']->id]) : route('proposals.show', [$openProposal])"
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
    @if ($this->placement !== null)
        <x-ui.modal wire:model="showPlacementModal" :title="__('report_templates.placement_join')" :description="$this->placementContext" submit="askJoinPlacement">
            <div class="flex flex-col gap-4">
                <p class="text-sm text-secondary-medium dark:text-ink-muted">{{ __('report_templates.placement_join_help') }}</p>
                <x-ui.form-select wire:model.live="selectedPlacementTemplateId" :label="__('report_templates.placement_template')" :placeholder="__('report_templates.placement_template')" required>
                    @foreach ($this->placementTemplates as $option)
                        <option value="{{ $option->id }}">{{ $option->proposals->pluck('name')->implode(' · ') }} · {{ __('report_templates.placement_confirmed_lines', ['count' => $option->confirmed_count]) }}</option>
                    @endforeach
                </x-ui.form-select>
                <fieldset class="flex flex-col gap-3">
                    <legend class="mb-2 text-sm font-semibold">{{ __('report_templates.placement_targets') }}</legend>
                    @foreach ($this->placementTargets as $target)
                        <label class="flex items-start gap-2" wire:key="placement-target-{{ $target['id'] }}">
                            <input type="checkbox" wire:model="selectedPlacementTargets" value="{{ $target['id'] }}" @disabled($target['shared']) class="mt-1 shrink-0" />
                            <span dir="auto">{{ $target['name'] }} @if ($target['shared'])<span class="block text-xs text-secondary-medium dark:text-ink-muted">{{ __('report_templates.placement_shared') }}</span>@endif</span>
                        </label>
                    @endforeach
                </fieldset>
                <flux:error name="selectedPlacementTargets" />
                <flux:error name="placement" />
            </div>
            <x-slot:actions><x-ui.button type="submit" tone="primary">{{ __('common.next') }}</x-ui.button></x-slot:actions>
        </x-ui.modal>
        <x-ui.confirm-modal wire:model="showPlacementConfirm" tone="neutral"
            :title="$this->placementContext"
            :message="$placementMode === 'join' ? __('report_templates.placement_join_help') : __('report_templates.placement_create_help')"
            confirm="decidePlacement"
            :confirm-label="$placementMode === 'join' ? __('report_templates.placement_join') : __('report_templates.placement_create')">
            <flux:error name="placement" />
            <flux:error name="selectedPlacementTargets" />
        </x-ui.confirm-modal>
        <flux:error name="placement" />
        <flux:error name="selectedPlacementTargets" />
    @endif
</x-desk.register>
