{{-- One generated month on "My reports": its state, due date or why it cannot be submitted yet, and its view and Fill buttons. On a template's thread ($inThread) it carries the thread's dot; in "Needs you" it names its place and proposals instead, since that list mixes templates. $rowGroup: the template's group from myMonths(). $projectHasIndicators: whether any month of the template has a line. --}}
@php
    $rowReport = $row['report'];
    $monthLabel = $rowReport->period->translatedFormat('F Y');
    $rowName = $rowGroup['title'].' · '.$rowGroup['place'];
@endphp
<li
    @class([
        'relative flex flex-wrap items-center gap-x-4 gap-y-2',
        'py-3 ps-7' => $inThread,
        'px-4 py-3' => ! $inThread,
    ])
    @if ($inThread) data-month="{{ $row['key'] }}" data-month-state="{{ $row['state'] }}" @else data-needs-you-item="{{ $row['key'] }}" @endif
    wire:key="{{ $inThread ? 'month' : 'needs-you' }}-{{ $rowReport->id }}"
>
    @if ($inThread)
        <span @class([
            'absolute start-0 top-1/2 size-[11px] -translate-y-1/2 rounded-full ring-4 ring-white dark:ring-surface-0',
            'bg-status-good' => $row['state'] === 'validated',
            'bg-secondary-dark dark:bg-ink' => $row['state'] === 'submitted',
            'bg-status-warn' => in_array($row['state'], ['returned', 'reopen_pending'], true),
            'bg-primary' => $row['overdue'],
            'border-2 border-secondary-light bg-white dark:border-line-strong dark:bg-surface-0' => ! $row['overdue'] && in_array($row['state'], ['draft', 'upcoming'], true),
        ]) aria-hidden="true"></span>
    @endif

    <div class="min-w-36 flex-1">
        <p class="flex flex-wrap items-center gap-2">
            <span class="text-base font-bold">{{ $monthLabel }}</span>
            <x-monthly-reports.state-chip :state="$row['state']" />
            @if ($row['overdue'])
                <span class="inline-flex items-center gap-1 rounded-md border border-primary bg-status-critical-soft px-1.5 py-0.5 text-xs font-semibold text-status-critical" data-overdue="{{ $row['key'] }}">
                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />
                    {{ __('monthly_reports.overdue') }}
                </span>
            @endif
        </p>
        @unless ($inThread)
            {{-- "Needs you" mixes templates: each row says where and for which proposals, so two reports of one place and month are not confused. --}}
            <p class="mt-0.5 text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto" data-row-place="{{ $rowGroup['template']->id }}">{{ $rowGroup['place'] }}</p>
            @include('pages.monthly-reports._covers-proposals', ['proposals' => $rowGroup['proposals']])
        @endunless
        <p class="mt-0.5 flex flex-wrap gap-x-3 type-meta text-secondary-medium dark:text-ink-muted">
            @if ($row['due'] !== null)
                <span class="tabular-nums">{{ __('monthly_reports.due_on', ['date' => $row['due']->translatedFormat(__('formats.date'))]) }}</span>
            @elseif ($projectHasIndicators)
                {{-- Only worth saying for one month among others that have indicators; a project with none says it once, over its months. --}}
                <span>{{ __('monthly_reports.no_indicators_this_month') }}</span>
            @endif
            @if ($row['blocked'] !== null)
                <span class="inline-flex items-center gap-1" data-blocked="{{ $row['key'] }}">
                    <flux:icon.lock-closed variant="micro" class="size-3" aria-hidden="true" />
                    {{ $row['blocked'] }}
                </span>
            @endif
        </p>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        {{-- Named with its proposals and place: two templates share every month, and "View September 2026" twice told a screen reader nothing. --}}
        <x-ui.button tone="ghost" size="sm" icon="eye" class="max-sm:size-11" :href="route('monthly-reports.show', $rowReport)" wire:navigate :aria-label="__('common.view_name', ['name' => $rowName.' · '.$monthLabel])" />
        @if ($row['can_fill'])
            <x-ui.button
                size="sm"
                tone="primary"
                icon="pencil-square"
                wire:click="fillMonth({{ $rowReport->id }})"
                wire:loading.attr="disabled"
                wire:target="fillMonth({{ $rowReport->id }})"
                data-fill="{{ $rowReport->id }}"
            >{{ __('monthly_reports.fill') }}</x-ui.button>
        @endif
    </div>
</li>
