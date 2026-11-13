{{--
    The project manager's monthly update: Part 1, activity tracking (1. weeks,
    2. numbers and supporting files); Part 2, monthly report (3. narrative and
    beneficiaries, 4. check and send). The check step shows everything read
    only. Sending opens after the month ends and once last month is approved.
--}}
@php
    $steps = [__('annual_plans.up.step_weeks'), __('annual_plans.up.step_numbers'), __('annual_plans.up.step_narrative'), __('annual_plans.up.step_send')];
    $stepEl = fn (int $i): string => '<div class="ap-st '.($i < $step ? 'is-done' : ($i === $step ? 'is-now' : '')).'"><i></i>'.($i + 1).'. '.e($steps[$i]).'</div>';
    $readOnly = $step === 3;
    $blockers = $this->blockers;
@endphp

@if ($record?->status === \App\Enums\PlanUpdateStatus::Returned)
    <div class="ap-info is-bad" data-returned-banner><b>{{ __('annual_plans.up.returned_by', ['name' => $record->returner?->name ?? '—']) }}</b> “<span dir="auto">{{ $record->return_reason }}</span>” {{ __('annual_plans.up.returned_keeps') }}</div>
@endif
@if ($record !== null && $record->questions->isNotEmpty())
    <div class="ap-info is-warn" data-questions-banner><b>{{ __('annual_plans.up.questions_on_update') }}</b>
        @foreach ($record->questions as $q) <span dir="auto">{{ $q->user?->name }}: “{{ $q->body }}”</span>@if (! $loop->last) · @endif @endforeach
    </div>
@endif

<div class="ap-rail2">
    <div class="ap-part"><span class="ap-plab">{{ __('annual_plans.up.part1') }}</span><div class="ap-rail" style="--ap-steps: 2">{!! $stepEl(0).$stepEl(1) !!}</div></div>
    <div class="ap-part"><span class="ap-plab">{{ __('annual_plans.up.part2') }}</span><div class="ap-rail" style="--ap-steps: 2">{!! $stepEl(2).$stepEl(3) !!}</div></div>
</div>

<section class="ap-card" data-update-step="{{ $step }}">
    @if ($readOnly)<div class="ap-partH">{{ __('annual_plans.up.part1') }}</div>@endif
    @if ($step === 0 || $readOnly)
        @include('pages.annual-plans._update-weeks', ['readOnly' => $readOnly])
    @endif
    @if ($step === 1 || $readOnly)
        @include('pages.annual-plans._update-numbers', ['readOnly' => $readOnly])
    @endif
    @if ($readOnly)<div class="ap-partH">{{ __('annual_plans.up.part2') }}</div>@endif
    @if ($step === 2 || $readOnly)
        @include('pages.annual-plans._update-narrative', ['readOnly' => $readOnly])
    @endif
    @if ($readOnly)
        <div class="ap-info is-blue">{{ __('annual_plans.up.send_info') }}</div>
    @endif
</section>

<div class="ap-foot">
    @if ($step > 0)<button type="button" class="dk-btn" wire:click="goToStep({{ $step - 1 }})">‹ {{ __('annual_plans.wf.back') }}</button>@endif
    <button type="button" class="dk-btn" wire:click="saveDraft" data-test="save-draft">{{ __('annual_plans.up.save_draft') }}</button>
    <span class="ap-sp"></span>
    @if ($missing !== [])<span class="ap-err" data-missing>{{ trans_choice('annual_plans.up.fields_need_attention', count($missing), ['count' => count($missing)]) }}</span>@endif
    @if ($readOnly)
        @if ($blockers !== [])
            <span class="ap-xs" style="color: var(--warn); font-weight: 700; max-width: 440px" data-send-blocked>{{ implode(' ', $blockers) }} {{ __('annual_plans.up.save_until') }}</span>
            <button type="button" class="dk-btn dk-primary" disabled>{{ __('annual_plans.up.send') }} ✓</button>
        @else
            <button type="button" class="dk-btn dk-primary" wire:click="sendUpdate" data-test="send-update">{{ $record?->status === \App\Enums\PlanUpdateStatus::Returned ? __('annual_plans.up.send_again') : __('annual_plans.up.send') }} ✓</button>
        @endif
    @else
        <button type="button" class="dk-btn dk-primary" wire:click="goToStep({{ $step + 1 }})" data-test="update-next">{{ __('annual_plans.up.next', ['step' => $steps[$step + 1]]) }} ›</button>
    @endif
</div>
