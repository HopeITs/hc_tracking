@props([
    'show' => 'showRiskModal',
    'form' => 'riskForm',
    'submit' => 'saveRisk',
    'close' => 'closeRiskForm',
    'open' => false,
    'editing' => false,
    'suggestions' => [],
])

{{--
    Create / edit form for a typed framework risk (Arabic and English title,
    likelihood and severity 1–3, mitigation). The wire bindings come in as
    props so the results framework page and the proposal logframe share it.
    Both titles offer the risk library's titles; picking one fills the other
    title too.
--}}
@php
    $pairs = collect($suggestions)->map(fn ($entry): array => ['ar' => $entry->title_ar, 'en' => $entry->title_en])->values()->all();
    $ratingOptions = [
        ['value' => '1', 'label' => '1 · '.__('risks.rating_low')],
        ['value' => '2', 'label' => '2 · '.__('risks.rating_medium')],
        ['value' => '3', 'label' => '3 · '.__('risks.rating_high')],
    ];
    $listId = 'risk-suggestions-'.substr(md5($form), 0, 8);
@endphp

<x-ui.modal
    wire:model="{{ $show }}"
    size="lg"
    :title="$editing ? __('results_framework.edit_risk') : __('results_framework.add_risk')"
    :description="__('results_framework.risk_form_description')"
    :submit="$submit"
    :close="$close"
    required-note
>
    @if ($open)
    <div
        class="grid gap-x-4 gap-y-3"
        x-data="{ pairs: @js($pairs) }"
        x-on:change="
            const value = $event.target.value;
            if ($event.target.dataset.riskTitle === 'ar') {
                const pair = pairs.find((p) => p.ar === value);
                if (pair) { $wire.$set(@js($form.'.name_en'), pair.en, false); }
            } else if ($event.target.dataset.riskTitle === 'en') {
                const pair = pairs.find((p) => p.en === value);
                if (pair) { $wire.$set(@js($form.'.name'), pair.ar, false); }
            }
        "
        data-test="risk-form"
    >
        <datalist id="{{ $listId }}-ar">
            @foreach ($pairs as $pair)
                <option value="{{ $pair['ar'] }}"></option>
            @endforeach
        </datalist>
        <datalist id="{{ $listId }}-en">
            @foreach ($pairs as $pair)
                <option value="{{ $pair['en'] }}"></option>
            @endforeach
        </datalist>

        <x-ui.form-input wire:model="{{ $form }}.name" :label="__('risks.title_ar')" required autofocus dir="rtl" list="{{ $listId }}-ar" data-risk-title="ar" maxlength="255" autocomplete="off" :help="__('results_framework.risk_suggestion_help')" />
        <x-ui.form-input wire:model="{{ $form }}.name_en" :label="__('risks.title_en')" required dir="ltr" list="{{ $listId }}-en" data-risk-title="en" maxlength="255" autocomplete="off" />

        <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-2">
            <x-ui.form-segmented wire:model="{{ $form }}.likelihood" :label="__('risks.likelihood')" required :options="$ratingOptions" />
            <x-ui.form-segmented wire:model="{{ $form }}.severity" :label="__('risks.severity')" required :options="$ratingOptions" />
        </div>

        <x-ui.form-textarea wire:model="{{ $form }}.solution" :label="__('results_framework.risk_mitigation')" maxlength="5000" />
    </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-risk-button">
            {{ $editing ? __('common.save_changes') : __('results_framework.add_risk') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
