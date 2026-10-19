{{--
    The Activities tab of the Monthly Matrix (D-51): activity × month, grouped by
    output. Included by ⚡monthly-matrix.blade.php, whose top PHP block defines
    $board. Statuses are the validated entries (BuildsMatrixActivities); a
    status carried over from an earlier month is muted and italic; a planned
    month shows its weeks.
--}}
@use('App\Enums\ActivityStatus')

@php
    $headCell = 'type-label border-b border-secondary-soft bg-board-head px-3 py-2 text-start text-secondary-medium dark:border-line dark:bg-surface-2 dark:text-ink-muted';
    $bodyCell = 'border-b border-secondary-soft px-3 py-2 align-top dark:border-line';
    $stickyName = 'sm:sticky sm:start-0 sm:z-10 w-64 min-w-64 max-w-64 sm:border-e';
    $statusIcon = fn (ActivityStatus $status): string => match ($status) {
        ActivityStatus::NotStarted => 'clock',
        ActivityStatus::Started, ActivityStatus::Ongoing => 'play',
        ActivityStatus::Suspended => 'pause-circle',
        ActivityStatus::Cancelled => 'x-circle',
        ActivityStatus::Completed, ActivityStatus::Conducted => 'check-circle',
    };
@endphp

@if ($board['outputs'] === [])
    <x-ui.panel>
        <x-ui.empty-state
            icon="clipboard-document-list"
            :heading="$this->hasNoAssignedProjects ? __('reports.matrix.no_assigned_projects') : __('reports.matrix.no_activities')"
            :body="$this->hasNoAssignedProjects ? __('reports.matrix.no_assigned_projects_hint') : __('reports.matrix.no_activities_hint')"
        >
            <x-slot:action>
                @if ($filtersAreActive)
                    <x-ui.button tone="primary" icon="arrow-path" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                @endif
            </x-slot:action>
        </x-ui.empty-state>
    </x-ui.panel>
@else
    <p class="text-xs text-secondary-medium dark:text-ink-muted" data-matrix-legend>{{ __('reports.matrix.activities_legend') }}</p>

    <x-ui.panel class="min-w-0">
        <div class="relative max-h-[75vh] overflow-auto" data-matrix-table data-matrix-activities tabindex="0" role="region" aria-label="{{ __('reports.matrix.tab_activities') }}">
            <table class="w-max min-w-full table-fixed border-separate border-spacing-0 text-sm">
                <thead class="sticky top-0 z-20">
                    <tr>
                        <th scope="col" class="{{ $headCell }} {{ $stickyName }} z-30">{{ __('reports.matrix.activity') }}</th>
                        <th scope="col" class="{{ $headCell }} w-44">{{ __('reports.matrix.center') }}</th>
                        <th scope="col" class="{{ $headCell }} w-28">{{ __('reports.matrix.activity_kind') }}</th>
                        @foreach ($board['months'] as $month)
                            <th scope="col" class="{{ $headCell }} w-28 text-end whitespace-nowrap" data-matrix-month="{{ $month->format('Y-m') }}">{{ $month->translatedFormat(__('formats.month_year')) }}</th>
                        @endforeach
                    </tr>
                </thead>

                @foreach ($board['outputs'] as $group)
                    <tbody wire:key="output-{{ $group['output_id'] }}" class="bg-white dark:bg-surface-1">
                        <tr>
                            <th scope="rowgroup" colspan="{{ 15 }}" class="{{ $bodyCell }} bg-board-head text-start font-semibold dark:bg-surface-2">
                                <span class="me-2 text-xs font-normal text-secondary-medium dark:text-ink-muted">{{ __('reports.matrix.output') }}</span>
                                <span dir="auto">{{ $group['statement'] }}</span>
                            </th>
                        </tr>
                        @foreach ($group['activities'] as $row)
                            <tr wire:key="{{ $row['key'] }}" data-matrix-row="{{ $row['key'] }}">
                                <td class="{{ $bodyCell }} {{ $stickyName }} bg-white dark:bg-surface-1"><span dir="auto" class="block">{{ $row['statement'] }}</span></td>
                                <td class="{{ $bodyCell }}"><span dir="auto">{{ $row['center'] }}</span></td>
                                <td class="{{ $bodyCell }} text-xs">{{ $row['kind']->label() }}</td>
                                @foreach ($board['months'] as $month)
                                    @php $cell = $row['cells'][$month->format('Y-m')]; @endphp
                                    <td class="{{ $bodyCell }} text-end text-xs" data-matrix-cell="{{ $cell['kind'] }}">
                                        @switch($cell['kind'])
                                            @case('status')
                                                <span class="inline-flex items-center justify-end gap-1 font-semibold">
                                                    <flux:icon :name="$statusIcon($cell['status'])" variant="micro" class="size-4" aria-hidden="true" />
                                                    {{ $cell['status']->label() }}
                                                </span>
                                                @break
                                            @case('carried')
                                                <span class="inline-flex items-center justify-end gap-1 italic text-secondary-medium dark:text-ink-muted" title="{{ __('reports.matrix.carried') }}">
                                                    <flux:icon :name="$statusIcon($cell['status'])" variant="micro" class="size-4" aria-hidden="true" />
                                                    {{ $cell['status']->label() }}
                                                    <span class="sr-only">{{ __('reports.matrix.carried') }}</span>
                                                </span>
                                                @break
                                            @case('planned')
                                                <span class="inline-flex items-center justify-end gap-1 text-secondary-medium dark:text-ink-muted">
                                                    <flux:icon.calendar variant="micro" class="size-4" aria-hidden="true" />
                                                    @if ($cell['weeks'] === null)
                                                        {{ __('reports.matrix.planned') }}
                                                    @else
                                                        {{ __('reports.matrix.planned_weeks', ['weeks' => implode(', ', $cell['weeks'])]) }}
                                                    @endif
                                                </span>
                                                @break
                                            @default
                                                <span class="text-secondary-medium dark:text-ink-muted"><span aria-hidden="true">—</span><span class="sr-only">{{ __('reports.matrix.no_status') }}</span></span>
                                        @endswitch
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </x-ui.panel>
@endif
