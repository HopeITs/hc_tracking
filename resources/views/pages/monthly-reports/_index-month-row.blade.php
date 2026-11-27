{{-- One generated month on "My reports" (M-06 table row): where it belongs and which proposals it covers, the month, its state (and Overdue), its due date or why it cannot be submitted yet, and its view, matrix and Fill in buttons. $rowGroup: the template's group from myMonths(). --}}
@php
    $rowReport = $row['report'];
    $monthLabel = $rowReport->period->translatedFormat('F Y');
    $rowName = $rowGroup['title'].' · '.$rowGroup['place'];
@endphp
<tr
    class="[&>td]:px-3.5 [&>td]:py-3"
    data-mine-row="{{ $rowReport->id }}"
    data-template="{{ $rowGroup['template']->id }}"
    data-month="{{ $row['key'] }}" data-month-state="{{ $row['state'] }}"
    wire:key="month-{{ $rowReport->id }}"
>
    <td class="max-w-md">
        {{-- Two templates of one place share every month: the proposals tell their rows apart. --}}
        <p class="font-semibold text-secondary-dark dark:text-ink" dir="auto" data-row-place="{{ $rowGroup['template']->id }}">{{ $rowGroup['place'] }}</p>
        @include('pages.monthly-reports._covers-proposals', ['proposals' => $rowGroup['proposals']])
    </td>
    <td class="whitespace-nowrap font-semibold text-secondary-dark dark:text-ink">{{ $monthLabel }}</td>
    <td>
        <span class="flex flex-wrap items-center gap-1.5">
            <x-monthly-reports.state-chip :state="$row['state']" />
            @if ($row['overdue'])
                <span class="inline-flex items-center gap-1 rounded-md border border-primary bg-status-critical-soft px-1.5 py-0.5 text-xs font-semibold text-status-critical" data-overdue="{{ $row['key'] }}">
                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ __('monthly_reports.overdue') }}
                </span>
            @endif
        </span>
    </td>
    <td class="type-meta text-secondary-medium dark:text-ink-muted">
        @if ($row['due'] !== null)
            <span class="block tabular-nums">{{ __('monthly_reports.due_on', ['date' => $row['due']->translatedFormat(__('formats.date'))]) }}</span>
        @else
            <span class="block">{{ __('monthly_reports.no_indicators_this_month') }}</span>
        @endif
        @if ($row['blocked'] !== null)
            <span class="mt-0.5 inline-flex items-center gap-1" data-blocked="{{ $row['key'] }}">
                <flux:icon.lock-closed variant="micro" class="size-3" aria-hidden="true" />
                {{ $row['blocked'] }}
            </span>
        @endif
    </td>
    <td>
        <div class="flex items-center justify-end gap-1.5">
            {{-- Named with its proposals and place: two templates share every month, and "View September 2026" twice told a screen reader nothing. --}}
            <x-ui.button tone="ghost" size="sm" icon="eye" class="max-sm:size-11" :href="route('monthly-reports.show', $rowReport)" wire:navigate :aria-label="__('common.view_name', ['name' => $rowName.' · '.$monthLabel])" />
            {{-- Back to the monthly matrix for this template's place, with the list's proposal and window (Y-05). --}}
            <x-ui.button tone="ghost" size="sm" icon="table-cells" class="max-sm:size-11" :href="$this->matrixUrlFor($rowGroup['template'])" wire:navigate :aria-label="__('monthly_reports.open_in_matrix', ['name' => $rowGroup['place']])" data-matrix-link="{{ $rowReport->id }}" />
            @if ($row['can_fill'])
                <x-ui.button
                    size="sm"
                    tone="primary"
                    icon="pencil-square"
                    class="max-sm:h-11"
                    wire:click="fillMonth({{ $rowReport->id }})"
                    wire:loading.attr="disabled"
                    wire:target="fillMonth({{ $rowReport->id }})"
                    data-fill="{{ $rowReport->id }}"
                >{{ __('monthly_reports.fill') }}</x-ui.button>
            @endif
        </div>
    </td>
</tr>
