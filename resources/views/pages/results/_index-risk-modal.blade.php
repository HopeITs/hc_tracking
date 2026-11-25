{{-- The program risk form (ManagesProgramRisks). The form itself is components/risks/form-modal, shared with the proposal logframe. --}}
<x-risks.form-modal
    show="showRiskModal"
    form="riskForm"
    submit="saveRisk"
    close="closeRiskForm"
    :open="$showRiskModal"
    :editing="$editingRiskId !== null"
    :suggestions="$this->riskSuggestions"
/>
