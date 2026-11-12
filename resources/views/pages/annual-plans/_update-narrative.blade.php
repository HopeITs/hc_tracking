{{-- Part 2, step 3: the five narrative sections (the same for every program) and one beneficiaries row for the centre. --}}
@php
    $total = (int) ($draft['males'] ?: 0) + (int) ($draft['females'] ?: 0);
@endphp

<div class="ap-grp" data-update-narrative>
    <h3 class="ap-h2">3. {{ __('annual_plans.up.narrative_title') }}</h3>
    @unless ($readOnly)<p class="ap-sm ap-muted">{{ __('annual_plans.up.narrative_help') }}</p>@endunless

    @foreach (['activities_implemented' => 'done', 'challenges' => 'challenges'] as $field => $key)
        @if ($field === 'challenges')
            @include('pages.annual-plans._update-beneficiaries', ['readOnly' => $readOnly, 'total' => $total])
        @endif
        <div class="ap-grp">
            <span class="ap-lbl">{{ __('annual_plans.up.section_'.$key) }}</span>
            <textarea class="ap-inp {{ $this->isMissing($field) ? 'is-err' : '' }}" wire:model.blur="draft.{{ $field }}" @disabled($readOnly) placeholder="{{ __('annual_plans.up.section_'.$key.'_hint') }}" dir="auto"></textarea>
        </div>
    @endforeach
    <div class="ap-grp">
        <span class="ap-lbl">{{ __('annual_plans.up.section_next') }}</span>
        <textarea class="ap-inp {{ $this->isMissing('next_month') ? 'is-err' : '' }}" wire:model.blur="draft.next_month" @disabled($readOnly) placeholder="{{ __('annual_plans.up.section_next_hint') }}" dir="auto"></textarea>
    </div>
    <div class="ap-grp">
        <span class="ap-lbl">{{ __('annual_plans.up.section_accountability') }}</span>
        <div class="ap-info">{{ __('annual_plans.up.accountability_locked') }}@if (filled($record?->accountability)) <span dir="auto" style="display: block; margin-top: 6px; color: var(--ink)">{{ $record->accountability }}</span>@endif</div>
    </div>
</div>
