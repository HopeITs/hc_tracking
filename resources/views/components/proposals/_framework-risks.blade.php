{{--
    The one "Risks" section under the whole logical framework (ManagesProposalRisks):
    risks copied from the program framework carry a "From program" tag and can only
    be removed; risks typed for this proposal can be edited and removed. Legacy rows
    that still carry a result_id are listed here too.
--}}
@php
    $frameworkRisks = $this->proposalRisks;
    $canManageRisks = $this->canManageRisks;
@endphp

<div class="mt-4 rounded-panel border border-panel-border bg-white px-4 py-3 dark:bg-surface-1" data-test="proposal-risks">
    <div class="flex flex-wrap items-center gap-2">
        <h3 class="type-label text-secondary-dark dark:text-ink">{{ __('results_framework.risks_heading') }} · {{ $frameworkRisks->count() }}</h3>
        @if ($canManageRisks)
            <x-ui.button tone="ghost" size="sm" icon="plus" class="ms-auto" wire:click="openRiskForm" data-test="add-proposal-risk">{{ __('results_framework.add_risk') }}</x-ui.button>
        @endif
    </div>

    @if ($frameworkRisks->isEmpty())
        <p class="type-meta mt-2 text-secondary-medium dark:text-ink-muted">{{ __('results_framework.no_proposal_risks') }}</p>
    @else
        <ul class="divide-y divide-panel-border">
            @foreach ($frameworkRisks as $risk)
                <x-risks.row :risk="$risk" :from-program="$risk->isCopied()" wire:key="proposal-risk-{{ $risk->id }}">
                    @if ($canManageRisks)
                        <x-slot:actions>
                            @unless ($risk->isCopied())
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" wire:click="openRiskForm({{ $risk->id }})" :aria-label="__('results_framework.edit_risk')" :title="__('results_framework.edit_risk')" data-test="edit-proposal-risk-{{ $risk->id }}" />
                            @endunless
                            <x-ui.button tone="ghost" size="sm" icon="trash" wire:click="removeRisk({{ $risk->id }})" wire:confirm="{{ __('results_framework.remove_proposal_risk_confirm') }}" :aria-label="__('results_framework.remove_risk')" :title="__('results_framework.remove_risk')" data-test="remove-proposal-risk-{{ $risk->id }}" />
                        </x-slot:actions>
                    @endif
                </x-risks.row>
            @endforeach
        </ul>
    @endif
</div>
