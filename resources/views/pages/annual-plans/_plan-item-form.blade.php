{{--
    The body of one planned activity. Editable: bound to $form (the open
    activity). Read-only ($readOnly, the MEAL review): the same rows and
    fields, every one disabled, filled from the stored item.
--}}
@use('App\Enums\CountUnit')

@php
    $readOnly ??= false;
    $data = $readOnly ? [
        'single_week' => $item->is_single_week,
        'unit' => $item->unit->value,
        'unit_other' => (string) $item->unit_other,
        'cities' => array_map('strval', $item->cityIds()),
        'expected_people' => $item->expected_people === null ? '' : (string) $item->expected_people,
        'note' => (string) $item->note,
        'batches' => $this->batchRows($item),
    ] : $form;
    $unit = CountUnit::tryFrom((string) $data['unit']) ?? CountUnit::Session;
    $single = (bool) $data['single_week'];
    $otherName = $data['unit_other'] !== '' ? $data['unit_other'] : null;
    $plural = $unit === CountUnit::Other ? ($otherName ?? __('annual_plans.unit_other_fallback')) : $unit->label();
    $timeline = $this->timeline($data['batches'], $single);
    $dis = $readOnly ? 'disabled' : '';
    $model = fn (string $path): string => $readOnly ? '' : 'wire:model.live="form.'.$path.'"';
    $plannedCities = $readOnly ? array_intersect_key($this->cityNames, array_flip(array_map('intval', $data['cities']))) : $this->cityNames;
@endphp

<div class="ap-act-b" data-item-form="{{ $item->id }}">
    <div class="ap-grp">
        <span class="ap-lbl">{{ __('annual_plans.wf.how_it_runs') }}</span>
        <div class="ap-seg">
            @if ($readOnly)
                <button type="button" aria-pressed="true" disabled>{{ $single ? __('annual_plans.wf.runs_single') : __('annual_plans.wf.runs_weeks') }}</button>
            @else
                <button type="button" wire:click="setRunsOnSingleWeek(false)" aria-pressed="{{ $single ? 'false' : 'true' }}">{{ __('annual_plans.wf.runs_weeks') }}</button>
                <button type="button" wire:click="setRunsOnSingleWeek(true)" aria-pressed="{{ $single ? 'true' : 'false' }}" data-test="runs-single">{{ __('annual_plans.wf.runs_single') }}</button>
            @endif
        </div>
    </div>

    <div class="ap-grp">
        <span class="ap-lbl">{{ __('annual_plans.wf.what_you_count') }}</span>
        <div class="ap-row">
            <select class="ap-sel" {!! $model('unit') !!} {{ $dis }} aria-label="{{ __('annual_plans.wf.what_you_count') }}" data-test="item-unit">
                @foreach (CountUnit::cases() as $case)
                    <option value="{{ $case->value }}" @selected($case === $unit)>{{ __('enums.count_unit.'.$case->value, [], 'en') }} · {{ __('enums.count_unit.'.$case->value, [], 'ar') }}</option>
                @endforeach
            </select>
            @if ($unit === CountUnit::Other)
                <input class="ap-inp {{ ! $readOnly && $otherName === null ? 'is-err' : '' }}" style="max-width: 240px" {!! $readOnly ? '' : 'wire:model.blur="form.unit_other"' !!} value="{{ $data['unit_other'] }}" placeholder="{{ __('annual_plans.wf.unit_other_placeholder') }}" {{ $dis }} aria-label="{{ __('annual_plans.wf.unit_other_label') }}">
            @endif
            <span class="ap-faint ap-xs">{{ $unit->needsHours() ? __('annual_plans.wf.hours_needed') : __('annual_plans.wf.no_hours') }}</span>
        </div>
    </div>

    <div class="ap-grp">
        <span class="ap-lbl">{{ $single ? __('annual_plans.wf.which_weeks') : __('annual_plans.wf.from_to') }}</span>
        @foreach ($data['batches'] as $index => $batch)
            @php
                $endBefore = ! $single && filled($batch['end'] ?? null) && $batch['end'] < $batch['start'];
                $span = $single || blank($batch['end'] ?? null) ? 1 : intdiv((int) \Carbon\CarbonImmutable::parse($batch['start'])->diffInDays(\Carbon\CarbonImmutable::parse($batch['end'])), 7) + 1;
            @endphp
            <div class="ap-bt" wire:key="batch-{{ $item->id }}-{{ $index }}" data-batch="{{ $index }}">
                <b>{{ $single ? __('annual_plans.wf.week_n', ['n' => $index + 1]) : __('annual_plans.wf.batch_n', ['n' => $index + 1]) }}</b>
                <select class="ap-sel @error('batches.'.$index) is-err @enderror {{ $endBefore ? 'is-err' : '' }}" {!! $model('batches.'.$index.'.start') !!} {{ $dis }} aria-label="{{ __('annual_plans.wf.start_week') }}">
                    @foreach ($this->weekOptions as $value => $label)
                        <option value="{{ $value }}" @selected($value === $batch['start'])>{{ $label }}</option>
                    @endforeach
                </select>
                @unless ($single)
                    <span class="ap-faint ap-sm">{{ __('annual_plans.wf.until') }}</span>
                    <select class="ap-sel {{ $endBefore ? 'is-err' : '' }}" {!! $model('batches.'.$index.'.end') !!} {{ $dis }} aria-label="{{ __('annual_plans.wf.end_week') }}">
                        @foreach ($this->weekOptions as $value => $label)
                            <option value="{{ $value }}" @selected($value === $batch['end'])>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($endBefore)
                        <span class="ap-faint ap-xs">{{ __('annual_plans.end_before_start') }}</span>
                    @else
                        <label class="ap-mini">{{ __('annual_plans.wf.weeks_label') }}
                            <input class="ap-inp is-n" type="number" min="1" step="1" value="{{ $span }}" @if (! $readOnly) wire:change="setBatchWeeks({{ $index }}, $event.target.value)" @endif {{ $dis }} aria-label="{{ __('annual_plans.wf.weeks_label') }}" data-test="batch-weeks">
                        </label>
                    @endif
                @endunless
                <span class="ap-sp"></span>
                <label class="ap-mini">{{ $plural }}
                    <input class="ap-inp is-n {{ ! $readOnly && blank($batch['count'] ?? null) ? 'is-err' : '' }}" type="number" min="1" step="1" {!! $model('batches.'.$index.'.count') !!} value="{{ $batch['count'] }}" {{ $dis }} aria-label="{{ __('annual_plans.wf.how_many', ['unit' => $plural]) }}" data-test="batch-count">
                </label>
                @if ($unit->needsHours())
                    <label class="ap-mini">{{ __('annual_plans.wf.hours_each') }}
                        <input class="ap-inp is-n {{ ! $readOnly && blank($batch['hours'] ?? null) ? 'is-err' : '' }}" type="number" min="0.5" step="0.5" {!! $model('batches.'.$index.'.hours') !!} value="{{ $batch['hours'] }}" {{ $dis }} aria-label="{{ __('annual_plans.wf.hours_each') }}" data-test="batch-hours">
                    </label>
                @endif
                @if (! $readOnly && count($data['batches']) > 1)
                    <button type="button" class="dk-btn dk-ghost" wire:click="removeBatch({{ $index }})">{{ __('annual_plans.wf.remove') }}</button>
                @endif
            </div>
            @error('batches.'.$index) <p class="ap-err">{{ $message }}</p> @enderror
        @endforeach
        @unless ($readOnly)
            <div class="ap-row">
                <button type="button" class="dk-btn" wire:click="addBatch" data-test="add-batch">+ {{ $single ? __('annual_plans.wf.add_week') : __('annual_plans.wf.add_batch') }}</button>
                @unless ($single)<span class="ap-faint ap-xs">{{ __('annual_plans.wf.batches_may_overlap') }}</span>@endunless
            </div>
        @endunless
        <div class="ap-sm"><b data-item-totals>{{ $this->totalsLine($unit, $otherName, $data['batches']) }}</b></div>
        <div class="ap-tl" aria-hidden="true">
            @foreach ($timeline['months'] as $month)<span class="ap-m" style="inset-inline-start: {{ $month['left'] }}%">{{ $month['label'] }}</span>@endforeach
            @foreach ($timeline['bars'] as $bar)
                @if ($bar['single'])<span class="ap-o" style="inset-inline-start: {{ $bar['left'] }}%" title="{{ $bar['title'] }}"></span>
                @else<span class="ap-b {{ $bar['alt'] ? 'is-alt' : '' }}" style="inset-inline-start: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%" title="{{ $bar['title'] }}"></span>@endif
            @endforeach
        </div>
    </div>

    <div class="ap-grp">
        <span class="ap-lbl">{{ __('annual_plans.wf.where') }}</span>
        <div class="ap-row">
            @forelse ($plannedCities as $cityId => $cityName)
                <label class="ap-chk"><input type="checkbox" value="{{ $cityId }}" @if (! $readOnly) wire:model.live="form.cities" @endif @checked(in_array((string) $cityId, $data['cities'], true)) {{ $dis }}>{{ $cityName }}</label>
            @empty
                <span class="ap-faint ap-sm">{{ __('annual_plans.wf.no_centres') }}</span>
            @endforelse
        </div>
    </div>

    <div class="ap-row" style="align-items: flex-start">
        <label class="ap-grp" style="flex: 1; min-width: 180px"><span class="ap-lbl">{{ __('annual_plans.wf.expected_people') }}</span>
            <input class="ap-inp" type="number" min="0" {!! $readOnly ? '' : 'wire:model.blur="form.expected_people"' !!} value="{{ $data['expected_people'] }}" placeholder="{{ $readOnly ? __('annual_plans.wf.not_set') : __('annual_plans.wf.whole_activity') }}" {{ $dis }}></label>
        <label class="ap-grp" style="flex: 2; min-width: 220px"><span class="ap-lbl">{{ __('annual_plans.wf.note') }}</span>
            <input class="ap-inp" {!! $readOnly ? '' : 'wire:model.blur="form.note"' !!} value="{{ $data['note'] }}" placeholder="{{ $readOnly ? __('annual_plans.wf.no_note') : __('annual_plans.wf.note_placeholder') }}" {{ $dis }} dir="auto"></label>
    </div>

    @unless ($readOnly)
        @if ($plan->items->count() > 1)
            <details class="ap-copy">
                <summary>{{ __('annual_plans.wf.copy_title') }}</summary>
                <p class="ap-xs ap-faint">{{ __('annual_plans.wf.copy_help') }}</p>
                <div class="ap-row">
                    @foreach ($plan->items->where('id', '!=', $item->id) as $other)
                        <label class="ap-chk" wire:key="copy-{{ $other->id }}"><input type="checkbox" value="{{ $other->id }}" wire:model="copyTargets"><span dir="auto">{{ $other->activity?->statement }}</span></label>
                    @endforeach
                </div>
                <div><button type="button" class="dk-btn" wire:click="copySettings" data-test="copy-settings">{{ __('annual_plans.wf.copy_button') }}</button></div>
            </details>
        @endif
        @error('item') <p class="ap-err">{{ $message }}</p> @enderror
        <div class="ap-row">
            <span class="ap-xs ap-faint">{{ $unit->needsHours() ? __('annual_plans.wf.needs_sessions_hours', ['each' => $single ? __('annual_plans.wf.every_week') : __('annual_plans.wf.every_batch')]) : __('annual_plans.wf.needs_count', ['unit' => $plural, 'each' => $single ? __('annual_plans.wf.every_week') : __('annual_plans.wf.every_batch')]) }}</span>
            <span class="ap-sp"></span>
            <button type="button" class="dk-btn dk-primary" wire:click="finishItem" data-test="item-done">{{ $plan->items->last()?->id !== $item->id ? __('annual_plans.wf.done_next') : __('annual_plans.wf.done') }}</button>
        </div>
    @endunless
</div>
