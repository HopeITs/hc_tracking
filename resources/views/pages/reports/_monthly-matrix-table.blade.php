{{--
    The Monthly Matrix grid: sticky code and name columns, twelve months, then
    target, total and achieved %. Included by ⚡monthly-matrix.blade.php, whose
    top PHP block defines $matrix and $paged. Every figure arrives computed by
    BuildsMonthlyMatrix: nothing here adds up cells.
--}}
@use('App\Enums\IndicatorMeasure')
@use('App\Enums\MatrixCellState')

@php
    $headCell = 'type-label border-b border-secondary-soft bg-board-head px-3 py-2 text-start text-secondary-medium dark:border-line dark:bg-surface-2 dark:text-ink-muted';
    $bodyCell = 'border-b border-secondary-soft px-3 py-2 align-top dark:border-line';
    $stickyCode = 'sm:sticky sm:start-0 sm:z-10 w-28 min-w-28 max-w-28';
    $stickyName = 'sm:sticky sm:start-28 sm:z-10 w-56 min-w-56 max-w-56 sm:border-e';
@endphp

<x-ui.panel class="min-w-0">
    <div class="relative max-h-[75vh] overflow-auto" data-matrix-table tabindex="0" role="region" aria-label="{{ __('titles.monthly_matrix') }}">
        <table class="w-max min-w-full table-fixed border-separate border-spacing-0 text-sm">
            <thead class="sticky top-0 z-20">
                <tr>
                    <th scope="col" class="{{ $headCell }} {{ $stickyCode }} z-30">{{ __('reports.matrix.code') }}</th>
                    <th scope="col" class="{{ $headCell }} {{ $stickyName }} z-30 border-secondary-soft dark:border-line">{{ __('reports.matrix.name_en') }}</th>
                    <th scope="col" class="{{ $headCell }} w-56">{{ __('reports.matrix.name_ar') }}</th>
                    <th scope="col" class="{{ $headCell }} w-44">{{ __('reports.matrix.center') }}</th>
                    <th scope="col" class="{{ $headCell }} w-52">{{ __('reports.matrix.source') }}</th>
                    <th scope="col" class="{{ $headCell }} w-24">{{ __('reports.matrix.measure') }}</th>
                    <th scope="col" class="{{ $headCell }} w-24 text-end">{{ __('reports.matrix.target') }}</th>
                    @foreach ($matrix['months'] as $month)
                        <th scope="col" class="{{ $headCell }} w-24 text-end whitespace-nowrap" data-matrix-month="{{ $month->format('Y-m') }}">{{ $month->translatedFormat(__('formats.month_year')) }}</th>
                    @endforeach
                    <th scope="col" class="{{ $headCell }} w-28 text-end">{{ __('reports.matrix.total') }}</th>
                    <th scope="col" class="{{ $headCell }} w-28 text-end">{{ __('reports.matrix.achieved') }}</th>
                </tr>
            </thead>

            @foreach ($paged as $row)
                    @php
                        $unit = $row['measure'] === IndicatorMeasure::Percentage ? '%' : ($row['unit'] ?? '');
                    @endphp
                    <tbody wire:key="{{ $row['key'] }}" class="bg-white dark:bg-surface-1" x-data="{ open: false }">
                    <tr data-matrix-row="{{ $row['key'] }}">
                        <td class="{{ $bodyCell }} {{ $stickyCode }} bg-white font-mono text-xs dark:bg-surface-1" dir="ltr">{{ $row['code'] }}</td>
                        <td class="{{ $bodyCell }} {{ $stickyName }} bg-white dark:bg-surface-1">
                            <span dir="auto" class="block font-medium">{{ $row['name_en'] }}</span>
                            @if ($row['breakdown'] !== null)
                                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-label="{{ __('reports.matrix.toggle_breakdown') }}" data-matrix-expand class="mt-1 inline-flex min-h-8 touch-manipulation items-center gap-1 text-xs text-secondary-medium underline decoration-secondary-light underline-offset-2 dark:text-ink-muted">
                                    <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
                                    {{ __('reports.matrix.breakdown') }}
                                </button>
                            @endif
                            @unless ($row['is_active'])
                                <span class="mt-0.5 inline-flex items-center gap-1 text-[11px] text-secondary-medium dark:text-ink-muted"><flux:icon.pause-circle variant="micro" class="size-3.5" aria-hidden="true" />{{ __('reports.matrix.inactive') }}</span>
                            @endunless
                        </td>
                        <td class="{{ $bodyCell }}"><span dir="auto" class="block">{{ $row['name_ar'] }}</span></td>
                        <td class="{{ $bodyCell }}">
                            @if ($row['is_group'])
                                <span>{{ __('reports.matrix.centers_count', ['count' => $row['centers_count']]) }}</span>
                            @else
                                @can('projects.view')
                                    <a href="{{ route('projects.show', $row['project_slug']) }}" wire:navigate dir="auto" class="underline decoration-secondary-light underline-offset-2">{{ $row['center'] }}</a>
                                @else
                                    <span dir="auto">{{ $row['center'] }}</span>
                                @endcan
                            @endif
                            @if ($row['city'] !== null)
                                <span dir="auto" class="block text-xs text-secondary-medium dark:text-ink-muted">{{ $row['city'] }}</span>
                            @endif
                        </td>
                        <td class="{{ $bodyCell }} text-xs">
                            @foreach ($row['sources'] as $source)
                                <div class="mb-1 last:mb-0">
                                    @if ($source['proposal'] !== null)
                                        @can('proposals.view')
                                            <a href="{{ route('proposals.show', $source['proposal_slug']) }}" wire:navigate dir="auto" class="underline decoration-secondary-light underline-offset-2">{{ $source['proposal'] }}</a>
                                        @else
                                            <span dir="auto">{{ $source['proposal'] }}</span>
                                        @endcan
                                    @else
                                        <span>{{ __('reports.matrix.annual_plan') }}</span>
                                    @endif
                                    @if ($source['donor'] !== null)
                                        <span class="block text-secondary-medium dark:text-ink-muted">
                                            @can('donors.view')
                                                <a href="{{ route('donors.show', $source['donor_slug']) }}" wire:navigate dir="auto" class="underline decoration-secondary-light underline-offset-2">{{ $source['donor'] }}</a>
                                            @else
                                                <span dir="auto">{{ $source['donor'] }}</span>
                                            @endcan
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td class="{{ $bodyCell }} text-xs">
                            {{ $row['measure']->label() }}
                            @if ($row['is_cumulative'])
                                <span class="block text-secondary-medium dark:text-ink-muted">{{ __('reports.matrix.cumulative') }}</span>
                                @if ($row['is_group'])
                                    <span class="block text-[11px] text-secondary-medium dark:text-ink-muted">{{ __('reports.matrix.cumulative_group_note') }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="{{ $bodyCell }} text-end tabular-nums" dir="ltr">
                            @if ($row['target'] === null)
                                <span aria-hidden="true">—</span><span class="sr-only">{{ __('reports.matrix.no_target') }}</span>
                            @else
                                {{ $this->matrixNumber($row['target']) }}{{ $unit === '%' ? '%' : '' }}
                            @endif
                        </td>

                        @foreach ($matrix['months'] as $month)
                            @php
                                $cell = $row['cells'][$month->format('Y-m')];
                            @endphp
                            <td class="{{ $bodyCell }} text-end tabular-nums" data-matrix-cell="{{ $cell['state']->value }}">
                                @switch($cell['state'])
                                    @case(MatrixCellState::Approved)
                                        <span class="inline-flex items-center justify-end gap-1 font-semibold" title="{{ $cell['state']->label() }}">
                                            <flux:icon.check-circle variant="micro" class="size-4 text-status-good" aria-hidden="true" />
                                            <span class="sr-only">{{ $cell['state']->label() }}</span>
                                            <span dir="ltr">{{ $cell['not_measured'] ? __('reports.matrix.not_measured') : $this->matrixNumber($cell['value']).($unit === '%' ? '%' : '') }}</span>
                                        </span>
                                        @if ($cell['numerator'] !== null && $row['measure'] === IndicatorMeasure::Percentage && $row['denominator_basis']?->value === 'fixed')
                                            <span class="block text-[11px] font-normal text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $this->matrixNumber($cell['numerator']) }}</span>
                                        @endif
                                        @break
                                    @case(MatrixCellState::Pending)
                                        <span class="inline-flex items-center justify-end gap-1 italic text-secondary-medium dark:text-ink-muted" title="{{ $cell['state']->label() }}">
                                            <flux:icon.clock variant="micro" class="size-4 text-status-warn" aria-hidden="true" />
                                            <span class="sr-only">{{ $cell['state']->label() }}</span>
                                            <span dir="ltr">{{ $cell['not_measured'] ? __('reports.matrix.not_measured') : $this->matrixNumber($cell['value']).($unit === '%' ? '%' : '') }}</span>
                                        </span>
                                        @break
                                    @case(MatrixCellState::NotSubmitted)
                                        <span class="inline-flex items-center justify-end text-secondary-medium dark:text-ink-muted" title="{{ $cell['state']->label() }}">
                                            <flux:icon.pencil-square variant="micro" class="size-4" aria-hidden="true" />
                                            <span class="sr-only">{{ $cell['state']->label() }}</span>
                                        </span>
                                        @break
                                    @case(MatrixCellState::Upcoming)
                                        <span class="inline-flex items-center justify-end text-secondary-light dark:text-ink-muted" title="{{ $cell['state']->label() }}">
                                            <flux:icon.calendar variant="micro" class="size-4" aria-hidden="true" />
                                            <span class="sr-only">{{ $cell['state']->label() }}</span>
                                        </span>
                                        @break
                                    @case(MatrixCellState::NotDue)
                                        <span class="text-secondary-medium dark:text-ink-muted" title="{{ $cell['state']->label() }}"><span aria-hidden="true">—</span><span class="sr-only">{{ $cell['state']->label() }}</span></span>
                                        @break
                                @endswitch
                            </td>
                        @endforeach

                        <td class="{{ $bodyCell }} text-end font-semibold tabular-nums" data-matrix-total>
                            @if ($row['total'] === null)
                                <span aria-hidden="true">—</span><span class="sr-only">{{ __('reports.matrix.no_total') }}</span>
                            @else
                                <span dir="ltr">{{ $this->matrixNumber($row['total']) }}{{ $unit === '%' ? '%' : '' }}</span>
                            @endif
                            @if ($row['excluded'] > 0)
                                <span class="block text-[11px] font-normal text-secondary-medium dark:text-ink-muted">{{ __('reports.matrix.not_poolable', ['count' => $row['excluded']]) }}</span>
                            @endif
                        </td>
                        <td class="{{ $bodyCell }} text-end tabular-nums" data-matrix-achieved>
                            @if ($row['achieved'] === null)
                                <span aria-hidden="true">—</span><span class="sr-only">{{ __('reports.matrix.no_achieved') }}</span>
                            @else
                                <span dir="ltr">{{ $this->matrixNumber($row['achieved']) }}%</span>
                            @endif
                        </td>
                    </tr>
                    @if ($row['breakdown'] !== null)
                        @include('pages.reports._monthly-matrix-breakdown')
                    @endif
                </tbody>
            @endforeach
        </table>
    </div>

    @if ($paged->hasPages())
        <div class="border-t border-secondary-soft px-4 py-2 dark:border-line">
            <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#monthly-matrix-heading" />
        </div>
    @endif
</x-ui.panel>
