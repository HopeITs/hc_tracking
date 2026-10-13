@props(['employee', 'compact' => false])

{{-- An HR employee shown by name in the interface language, with the other language beside it
     and, unless compact, their current position · job level · department · center from HR. --}}
@if ($employee === null)
    <div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
        <p class="truncate text-sm text-secondary-medium dark:text-ink-muted">{{ __('common.not_assigned') }}</p>
    </div>
@else
    @php
        $positionSummary = null;

        if (! $compact) {
            try {
                $positionSummary = \App\Models\HrEmployee::positionSummary($employee->id) ?? __('common.no_active_position');
            } catch (\Illuminate\Database\QueryException) {
                $positionSummary = '#'.$employee->id.' · '.__('common.hr_unavailable');
            }
        }
    @endphp
    <div {{ $attributes->class('flex min-w-0 items-center gap-2.5') }}>
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-secondary-dark dark:text-ink">{{ $employee->name }}</p>
            @unless ($compact)
                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted" dir="auto" lang="{{ \App\Enums\Locale::current()->other()->value }}">{{ $employee->secondary_name }}</p>
                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted" data-employee-position>{{ $positionSummary }}</p>
            @endunless
        </div>
    </div>
@endif
