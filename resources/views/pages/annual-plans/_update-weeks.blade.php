{{-- Part 1, step 1: what happened to each batch in each week of the month. --}}
@use('App\Enums\PlanWeekStatus')

@php
    $rows = $this->rows;
    $mondays = $this->mondays;
@endphp

<div class="ap-grp" data-update-weeks>
    <h3 class="ap-h2">1. {{ __('annual_plans.up.weeks_title') }}</h3>
    @unless ($readOnly)
        <p class="ap-sm ap-muted">{!! __('annual_plans.up.weeks_help') !!} <button type="button" class="dk-btn" wire:click="fillFromPlan" data-test="fill-from-plan">{{ __('annual_plans.up.fill_from_plan') }}</button></p>
    @endunless
    @forelse ($rows as $row)
        @php $item = $row['item']; $batch = $row['batch']; @endphp
        <div class="ap-act2" wire:key="wk-{{ $batch->id }}">
            <div class="ap-row"><b dir="auto">{{ $item->activity?->statement }}</b><span class="dk-pill">{{ $item->is_single_week ? __('annual_plans.wf.week_n', ['n' => $row['index'] + 1]) : __('annual_plans.wf.batch_n', ['n' => $row['index'] + 1]) }}</span><span class="ap-xs ap-faint">{{ __('annual_plans.up.planned_range', ['range' => $batch->start_week->translatedFormat('j M').($item->is_single_week ? '' : ' – '.$batch->end_week->translatedFormat('j M'))]) }}</span></div>
            <div class="ap-wk">
                @foreach ($mondays as $monday)
                    @php
                        $key = $batch->id.'|'.$monday->toDateString();
                        $entry = $draft['weeks'][$key] ?? ['status' => '', 'reason' => '', 'new_week' => ''];
                        $value = PlanWeekStatus::tryFrom((string) $entry['status']);
                        $planned = $monday->betweenIncluded($batch->start_week, $batch->end_week);
                    @endphp
                    <div class="ap-wkc {{ $planned ? 'is-planned' : '' }}" wire:key="wkc-{{ $key }}">
                        <small>{{ __('annual_plans.wf.week_of', ['date' => $monday->translatedFormat('j M')]) }}@if ($planned) · {{ __('annual_plans.up.planned') }}@endif</small>
                        <select class="ap-sel is-wide {{ $this->isMissing('week:'.$key) ? 'is-err' : '' }}" wire:model.live="draft.weeks.{{ $key }}.status" @disabled($readOnly) aria-label="{{ $item->activity?->statement }} · {{ $monday->translatedFormat('j M') }}">
                            <option value="">{{ $planned ? __('annual_plans.up.choose') : __('annual_plans.up.nothing') }}</option>
                            @foreach (PlanWeekStatus::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        @if ($value === PlanWeekStatus::Rescheduled)
                            <select class="ap-sel is-wide {{ $this->isMissing('new_week:'.$key) ? 'is-err' : '' }}" wire:model="draft.weeks.{{ $key }}.new_week" @disabled($readOnly) aria-label="{{ __('annual_plans.up.new_week') }}">
                                <option value="">{{ __('annual_plans.up.new_week') }}…</option>
                                @foreach ($this->laterWeeks($monday->toDateString()) as $optionValue => $optionLabel)
                                    <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if ($value?->needsReason() || filled($entry['reason'] ?? null))
                            <input class="ap-inp {{ $this->isMissing('reason:'.$key) ? 'is-err' : '' }}" style="height: 34px; font-size: .8rem" wire:model.blur="draft.weeks.{{ $key }}.reason" placeholder="{{ $value?->needsReason() ? __('annual_plans.up.why_required') : __('annual_plans.wf.note') }}" @disabled($readOnly) dir="auto">
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <p class="ap-sm ap-faint" data-no-batches>{{ __('annual_plans.up.no_batches') }}</p>
    @endforelse
</div>
