{{-- Beneficiaries Reached: one row for the centre; "with a disability" is counted inside male and female, never more than the total. --}}
<div class="ap-grp" data-beneficiaries>
    <span class="ap-lbl">{{ __('annual_plans.up.section_beneficiaries') }}</span>
    @unless ($readOnly)<p class="ap-xs ap-faint">{{ __('annual_plans.up.beneficiaries_help') }}</p>@endunless
    <div class="ap-tw">
        <table class="ap-t">
            <thead><tr><th>{{ __('annual_plans.up.col_centre') }}</th><th>{{ __('annual_plans.up.male') }}</th><th>{{ __('annual_plans.up.female') }}</th><th>{{ __('annual_plans.up.total') }}</th><th>{{ __('annual_plans.up.with_disability') }}</th></tr></thead>
            <tbody><tr>
                <td><b dir="auto">{{ $this->project->name }}</b></td>
                @foreach (['males', 'females'] as $field)
                    <td><input class="ap-inp is-n {{ $this->isMissing($field) ? 'is-err' : '' }}" type="number" min="0" wire:model.live.debounce.400ms="draft.{{ $field }}" @disabled($readOnly) aria-label="{{ __('annual_plans.up.'.($field === 'males' ? 'male' : 'female')) }}"></td>
                @endforeach
                <td class="ap-num"><b>{{ $total }}</b></td>
                <td><input class="ap-inp is-n {{ $this->isMissing('with_disability') || $this->isMissing('disability_total') ? 'is-err' : '' }}" type="number" min="0" wire:model.live.debounce.400ms="draft.with_disability" @disabled($readOnly) aria-label="{{ __('annual_plans.up.with_disability') }}">
                    @if ($this->isMissing('disability_total'))<span class="ap-err" style="display: block">{{ __('annual_plans.up.disability_over_total') }}</span>@endif</td>
            </tr></tbody>
        </table>
    </div>
</div>
