@props([
    'row',
    'prefix',
    'state' => [],
    'fixedDenominator' => null,
    'computed' => '—',
    'computedKey',
    'editable' => false,
])

@use('App\Enums\Gender')
@use('App\Enums\MonthlyReportEntryKind')

@php
    /*
     * The figure inputs of one line (shared) or one donor share (split) in
     * x-monthly-reports.lines-grid. What appears follows the entry kind
     * (contracts §13 Entry): a count; a fixed percentage's count over the
     * proposal's beneficiaries; an entered ratio's numerator and denominator
     * with Not measured; by sex with persons with disabilities when the
     * library entry is disaggregated (D-50). Computed values are the
     * server's, shown read-only. Inputs bind wire:model.blur under $prefix.
     */
    $kind = $row['kind'];
    $fields = match ($kind) {
        MonthlyReportEntryKind::Count => ['value'],
        MonthlyReportEntryKind::FixedRatio => ['numerator'],
        MonthlyReportEntryKind::EnteredRatio => ['numerator', 'denominator'],
    };
    $fieldLabels = [
        'value' => __('monthly_reports.value'),
        'numerator' => $row['numerator_label'],
        'denominator' => $row['denominator_label'],
    ];
    $notMeasured = (bool) ($state['not_measured'] ?? false);
    $inputClasses = 'h-8 w-24 rounded-md border bg-white px-2 text-end tabular-nums text-secondary-dark outline-none focus:border-primary focus:ring-2 focus:ring-primary/30 disabled:bg-board disabled:text-secondary-light dark:bg-surface-2 dark:text-ink dark:focus:border-brand dark:disabled:bg-surface-1 dark:disabled:text-ink-faint';
    $readClasses = 'inline-block min-w-12 text-end tabular-nums font-semibold';
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $showsComputed = $kind !== MonthlyReportEntryKind::Count || $row['disaggregated'];
@endphp

<div class="flex flex-wrap items-start gap-x-4 gap-y-2">
    @if ($row['disaggregated'])
        <div @class(['grid gap-x-3 gap-y-1.5', 'grid-cols-[auto_repeat(2,auto)]' => count($fields) === 1, 'grid-cols-[auto_repeat(4,auto)]' => count($fields) === 2])>
            <span></span>
            @foreach ($fields as $field)
                <span class="text-[11px] font-semibold {{ $mutedText }}">{{ $fieldLabels[$field] }}</span>
                <span class="text-[11px] font-semibold {{ $mutedText }}">{{ __('monthly_reports.of_whom_disabled') }}</span>
            @endforeach

            @foreach (Gender::cases() as $gender)
                <span class="self-center text-xs font-semibold">{{ $gender->label() }}</span>
                @foreach ($fields as $field)
                    @foreach ([$field, 'disabled_'.$field] as $breakdownField)
                        @php
                            $model = $prefix.'.breakdowns.'.$gender->value.'.'.$breakdownField;
                            $columnLabel = $gender->label().' · '.($breakdownField === $field ? $fieldLabels[$field] : __('monthly_reports.of_whom_disabled'));
                        @endphp
                        <div>
                            @if ($editable)
                                <input
                                    type="text"
                                    inputmode="decimal"
                                    dir="ltr"
                                    data-nav
                                    wire:model.blur="{{ $model }}"
                                    aria-label="{{ $row['name'] }} — {{ $columnLabel }}"
                                    @error($model) aria-invalid="true" @enderror
                                    @disabled($notMeasured)
                                    @class([$inputClasses, 'border-status-critical' => $errors->has($model), 'border-secondary-light dark:border-field-line' => ! $errors->has($model)])
                                >
                                @error($model)
                                    <p class="mt-1 max-w-48 text-xs text-status-critical">{{ $message }}</p>
                                @enderror
                            @else
                                <bdi dir="ltr" class="{{ $readClasses }}">{{ ($state['breakdowns'][$gender->value][$breakdownField] ?? '') !== '' ? $state['breakdowns'][$gender->value][$breakdownField] : '—' }}</bdi>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            @endforeach
        </div>
    @else
        @foreach ($fields as $field)
            @php
                $model = $prefix.'.'.$field;
            @endphp
            <label class="flex flex-col gap-1">
                <span class="text-[11px] font-semibold {{ $mutedText }}">{{ $fieldLabels[$field] }}</span>
                @if ($editable)
                    <input
                        type="text"
                        inputmode="decimal"
                        dir="ltr"
                        data-nav
                        wire:model.blur="{{ $model }}"
                        aria-label="{{ $row['name'] }} — {{ $fieldLabels[$field] }}"
                        @error($model) aria-invalid="true" @enderror
                        @disabled($notMeasured)
                        @class([$inputClasses, 'border-status-critical' => $errors->has($model), 'border-secondary-light dark:border-field-line' => ! $errors->has($model)])
                    >
                @else
                    <bdi dir="ltr" class="{{ $readClasses }}">{{ ($state[$field] ?? '') !== '' ? $state[$field] : '—' }}</bdi>
                @endif
                @error($model)
                    <span class="max-w-56 text-xs text-status-critical">{{ $message }}</span>
                @enderror
            </label>
        @endforeach
    @endif

    @if ($kind === MonthlyReportEntryKind::FixedRatio)
        <p class="self-end pb-1.5 text-xs {{ $mutedText }}">
            @if ($fixedDenominator !== null)
                {{ __('monthly_reports.out_of_beneficiaries', ['count' => number_format($fixedDenominator)]) }}
            @else
                {{ __('monthly_reports.no_fixed_denominator') }}
            @endif
        </p>
    @endif

    @if ($showsComputed)
        <div class="flex flex-col gap-1">
            <span class="text-[11px] font-semibold {{ $mutedText }}">{{ $kind === MonthlyReportEntryKind::Count ? __('monthly_reports.total') : __('monthly_reports.computed_value') }}</span>
            <bdi dir="ltr" class="{{ $readClasses }} pt-1.5" data-computed="{{ $computedKey }}">{{ $computed }}</bdi>
        </div>
    @endif

    @if ($kind === MonthlyReportEntryKind::EnteredRatio)
        <label class="flex items-center gap-2 self-end pb-1.5 text-xs font-semibold">
            <input
                type="checkbox"
                wire:model.live="{{ $prefix }}.not_measured"
                @disabled(! $editable)
                class="size-4 rounded border-secondary-light text-primary focus:ring-primary/30 dark:border-field-line dark:bg-surface-2"
            >
            {{ __('monthly_reports.not_measured') }}
        </label>
    @endif
</div>
