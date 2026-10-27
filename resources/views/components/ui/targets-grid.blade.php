@props([
    'rows',
    'columns',
    'grid',
    'errorBag',
    'editable' => false,
])

@use('App\Enums\DonorAttribution')
@use('App\Enums\Period')

@php
    /*
     * The per-center targets grid of proposals/show (T13). Rows are the
     * indicator instances of the proposal's framework, grouped under their
     * node; columns are the linked projects, labelled center · city.
     *
     * Everything shown is prebuilt by the caller (rows[].indicators[].cells
     * carry the saved/reported flags, the percentage helper, suggestions and
     * the center total), so the grid runs no query. Inputs bind with deferred
     * wire:model and are sent only by the caller's Save. Arrow keys and Enter
     * move between value inputs; the sticky first column and header keep the
     * indicator and center in view while scrolling.
     */
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inkText = 'text-secondary-dark dark:text-ink';
    $stickyCell = 'sticky start-0 z-[2] bg-white dark:bg-surface-1';
    $periods = Period::cases();
    $rowIndex = 0;
@endphp

<div
    class="overflow-x-auto"
    data-targets-grid
    x-data="{
        isRtl() { return getComputedStyle(this.$root).direction === 'rtl'; },
        move(event, rows, cols) {
            const cell = event.target.closest('[data-cell]');
            let row = Number(cell.dataset.row);
            let col = Number(cell.dataset.col);
            for (let step = 0; step < 500; step++) {
                row += rows;
                col += cols;
                const next = this.$root.querySelector(`[data-cell][data-row='${row}'][data-col='${col}']`);
                if (! next) return;
                const input = next.querySelector('input[data-value]');
                if (input) { event.preventDefault(); input.focus(); input.select(); return; }
            }
        },
    }"
>
    <table class="min-w-full border-separate border-spacing-0 text-sm">
        <thead>
            <tr>
                <th scope="col" class="{{ $stickyCell }} top-0 z-[3] min-w-36 sm:min-w-56 border-b border-e border-secondary-soft px-3 py-2 text-start text-xs font-semibold {{ $mutedText }} dark:border-line">
                    {{ __('targets.indicator_column') }}
                </th>
                @foreach ($columns as $column)
                    <th scope="col" class="sticky top-0 z-[1] min-w-40 border-b border-secondary-soft bg-white px-3 py-2 text-start align-bottom dark:border-line dark:bg-surface-1" data-column="{{ $column['id'] }}">
                        <span class="block text-sm font-semibold {{ $inkText }}" dir="auto">{{ $column['label'] }}</span>
                        @if ($column['city'] !== null)
                            <span class="block text-xs font-normal {{ $mutedText }}" dir="auto">{{ $column['city'] }}</span>
                        @endif
                        <span class="mt-0.5 block text-[11px] font-normal tabular-nums {{ $mutedText }}">
                            @if ($column['beneficiaries'] === null)
                                {{ __('targets.beneficiaries_unknown') }}
                            @else
                                {{ trans_choice('targets.beneficiaries_count', $column['beneficiaries'], ['count' => number_format($column['beneficiaries'])]) }}
                            @endif
                        </span>
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @foreach ($rows as $group)
                <tr wire:key="grid-node-{{ $group['result']->id }}">
                    <th scope="colgroup" colspan="{{ count($columns) + 1 }}" class="border-b border-secondary-soft bg-board px-3 py-1.5 text-start dark:border-line dark:bg-surface-2">
                        <span class="sticky start-3 inline-flex max-w-[min(100%,40rem)] items-center gap-2">
                            <x-ui.enum-badge :case="$group['result']->type" />
                            <span class="truncate text-xs font-semibold {{ $inkText }}" dir="auto">{{ $group['result']->statement }}</span>
                        </span>
                    </th>
                </tr>

                @foreach ($group['indicators'] as $row)
                    @php
                        $indicator = $row['indicator'];
                        $indicatorName = $indicator->libraryIndicator?->localizedName() ?? $indicator->name;
                        $unit = $indicator->libraryIndicator?->unit ?? $indicator->unit;
                        $currentRow = $rowIndex++;
                    @endphp
                    <tr wire:key="grid-row-{{ $indicator->id }}" data-grid-row="{{ $indicator->id }}">
                        <th scope="row" class="{{ $stickyCell }} max-w-40 border-b sm:max-w-none border-e border-secondary-soft px-3 py-2 text-start align-top font-normal dark:border-line">
                            @if ($indicator->code !== null)
                                <bdi dir="ltr" class="block font-mono text-[11px] {{ $mutedText }}">{{ $indicator->code }}</bdi>
                            @endif
                            <span class="block text-sm {{ $inkText }}" dir="auto">{{ $indicatorName }}</span>
                            <span class="mt-0.5 block text-[11px] {{ $mutedText }}">
                                {{ $indicator->measure->label() }}@if ($unit !== null && ! $row['isPercentage']) · {{ $unit }}@endif
                            </span>
                            @if (! $row['linked'])
                                <span class="mt-1 inline-flex items-center gap-1 rounded-full border border-status-critical px-2 py-0.5 text-[11px] font-semibold text-status-critical" data-badge="not-linked">
                                    <flux:icon.link-slash variant="micro" class="size-3" aria-hidden="true" />
                                    {{ __('targets.not_linked_to_library') }}
                                </span>
                            @endif
                        </th>

                        @foreach ($columns as $columnIndex => $column)
                            @php
                                $facts = $row['cells'][$column['id']];
                                $key = $facts['key'];
                                $cell = $grid[$key] ?? ['value' => '', 'period' => '', 'population' => ''];
                                $cellErrors = array_values(array_unique(array_merge(
                                    $errorBag->get("grid.{$key}"),
                                    $errorBag->get("grid.{$key}.value"),
                                    $errorBag->get("grid.{$key}.period"),
                                    $errorBag->get("grid.{$key}.population"),
                                )));
                                $hasError = $cellErrors !== [];
                                $inputId = 'target-cell-'.$key;
                                $cellLabel = __('targets.cell_label', ['indicator' => $indicatorName, 'center' => $column['label']]);
                            @endphp
                            <td
                                @class([
                                    'border-b border-secondary-soft px-3 py-2 align-top dark:border-line',
                                    'bg-status-critical-soft' => $hasError,
                                ])
                                data-cell
                                data-row="{{ $currentRow }}"
                                data-col="{{ $columnIndex }}"
                                data-cell-key="{{ $key }}"
                                wire:key="grid-cell-{{ $key }}"
                            >
                                @if (! $row['linked'])
                                    <span class="{{ $mutedText }}" aria-label="{{ __('targets.no_target_without_library') }}">—</span>
                                @elseif ($editable)
                                    <div class="flex items-center gap-1.5">
                                        <label for="{{ $inputId }}" class="sr-only">{{ $cellLabel }}</label>
                                        <input
                                            id="{{ $inputId }}"
                                            name="grid[{{ $key }}][value]"
                                            type="text"
                                            inputmode="decimal"
                                            dir="ltr"
                                            autocomplete="off"
                                            wire:model="grid.{{ $key }}.value"
                                            data-value
                                            @if ($hasError) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
                                            x-on:keydown.enter.prevent="move($event, 1, 0)"
                                            x-on:keydown.arrow-down.prevent="move($event, 1, 0)"
                                            x-on:keydown.arrow-up.prevent="move($event, -1, 0)"
                                            x-on:keydown.arrow-right="if ($event.target.selectionEnd === $event.target.value.length) move($event, 0, isRtl() ? -1 : 1)"
                                            x-on:keydown.arrow-left="if ($event.target.selectionStart === 0) move($event, 0, isRtl() ? 1 : -1)"
                                            @class([
                                                'h-8 w-24 rounded-md border bg-white px-2 text-end tabular-nums text-secondary-dark outline-none focus:border-secondary-medium focus:ring-2 focus:ring-secondary-light dark:bg-surface-2 dark:text-ink',
                                                'border-status-critical' => $hasError,
                                                'border-secondary-light dark:border-line' => ! $hasError,
                                            ])
                                        >
                                        @if ($row['isPercentage'])
                                            <span class="text-xs {{ $mutedText }}" aria-hidden="true">%</span>
                                        @endif
                                        <select
                                            name="grid[{{ $key }}][period]"
                                            wire:model="grid.{{ $key }}.period"
                                            aria-label="{{ __('targets.period_for', ['cell' => $cellLabel]) }}"
                                            class="h-8 rounded-md border border-secondary-light bg-white px-1.5 text-xs text-secondary-dark dark:border-line dark:bg-surface-2 dark:text-ink"
                                        >
                                            <option value="">{{ __('targets.period_placeholder') }}</option>
                                            @foreach ($periods as $period)
                                                <option value="{{ $period->value }}">{{ $period->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    @if ($row['isPercentage'])
                                        <div class="mt-1.5 flex items-center gap-1.5 text-[11px] {{ $mutedText }}">
                                            <label for="{{ $inputId }}-population">{{ __('targets.population_base') }}</label>
                                            <input
                                                id="{{ $inputId }}-population"
                                                name="grid[{{ $key }}][population]"
                                                type="text"
                                                inputmode="decimal"
                                                dir="ltr"
                                                autocomplete="off"
                                                wire:model="grid.{{ $key }}.population"
                                                placeholder="{{ $facts['beneficiaries'] ?? '' }}"
                                                class="h-7 w-20 rounded-md border border-secondary-light bg-white px-1.5 text-end tabular-nums text-secondary-dark dark:border-line dark:bg-surface-2 dark:text-ink"
                                            >
                                        </div>
                                    @endif
                                @else
                                    @php
                                        $periodLabel = Period::tryFrom((string) $cell['period'])?->label();
                                    @endphp
                                    @if (trim((string) $cell['value']) === '')
                                        <span class="{{ $mutedText }}">{{ __('targets.no_target') }}</span>
                                    @else
                                        <span class="font-semibold tabular-nums {{ $inkText }}" dir="ltr">{{ $cell['value'] }}@if ($row['isPercentage'])%@endif</span>
                                        @if ($periodLabel !== null)
                                            <span class="ms-1 text-xs {{ $mutedText }}">{{ $periodLabel }}</span>
                                        @endif
                                    @endif
                                @endif

                                @if ($row['linked'])
                                    <div class="mt-1 flex flex-col items-start gap-1">
                                        @if ($facts['derived'] !== null)
                                            <bdi dir="ltr" class="text-[11px] tabular-nums {{ $mutedText }}">{{ $facts['derived'] }}</bdi>
                                        @endif

                                        @if ($row['isPercentage'] && $facts['beneficiaries'] === null)
                                            <span class="inline-flex items-center gap-1 rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold {{ $inkText }}" data-badge="beneficiaries-missing">
                                                <flux:icon.users variant="micro" class="size-3" aria-hidden="true" />
                                                {{ __('targets.beneficiaries_missing') }}
                                            </span>
                                        @endif

                                        @if ($facts['reported'])
                                            <span class="inline-flex items-center gap-1 text-[11px] {{ $mutedText }}" data-badge="reported">
                                                <flux:icon.lock-closed variant="micro" class="size-3" aria-hidden="true" />
                                                {{ __('targets.cell_reported') }}
                                            </span>
                                        @endif

                                        @if ($facts['suggestion'] !== null)
                                            @if ($editable)
                                                <button
                                                    type="button"
                                                    wire:click="acceptSuggestion('{{ $key }}')"
                                                    class="inline-flex items-center gap-1 rounded-md border border-dashed border-secondary-light px-1.5 py-0.5 text-[11px] italic {{ $mutedText }} hover:border-primary hover:text-primary"
                                                    data-suggestion="{{ $key }}"
                                                    aria-label="{{ __('targets.use_suggestion_for', ['value' => $facts['suggestion'], 'cell' => $cellLabel]) }}"
                                                >
                                                    <flux:icon.light-bulb variant="micro" class="size-3" aria-hidden="true" />
                                                    {{ __('targets.suggestion_last_year', ['value' => $facts['suggestion']]) }}
                                                </button>
                                            @else
                                                <span class="inline-flex items-center gap-1 text-[11px] italic {{ $mutedText }}" data-suggestion="{{ $key }}">
                                                    <flux:icon.light-bulb variant="micro" class="size-3" aria-hidden="true" />
                                                    {{ __('targets.suggestion_last_year', ['value' => $facts['suggestion']]) }}
                                                </span>
                                            @endif
                                        @endif

                                        @if ($facts['centerTotal'] !== null)
                                            <span class="text-[11px] {{ $mutedText }}" data-center-total>
                                                {{ __($facts['attribution'] === DonorAttribution::Split ? 'targets.center_total_split' : 'targets.center_total_shared', ['value' => $facts['centerTotal']]) }}
                                            </span>
                                        @endif

                                        @foreach ($cellErrors as $cellError)
                                            <p id="{{ $inputId }}-error" class="flex items-start gap-1 text-[11px] font-semibold text-status-critical" role="alert">
                                                <flux:icon.exclamation-triangle variant="micro" class="mt-px size-3 shrink-0" aria-hidden="true" />
                                                {{ $cellError }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
