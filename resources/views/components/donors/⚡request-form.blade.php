<?php

use App\Livewire\Concerns\ManagesDonorForm;
use App\Models\Donor;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * "Add donor" from the proposal form (W-04, D-57): the shared donor form in
 * a nested flow. A donor recorded by someone who cannot approve donors is a
 * pending request; the new donor is handed back to the proposal form, which
 * selects it and shows the pending banner. Lives beside the proposal modal
 * rather than inside it so the two forms never share properties. The button
 * that opened it says which field the new donor fills: the back donor, the
 * Main Partner or one of the other Partners (PR, 2026-11-24).
 */
new class extends Component {
    use ManagesDonorForm;

    /** Which proposal field the new donor goes to: `donor` (back donor), `main` (Main Partner) or `partner` (other Partners). */
    public string $requestTarget = 'donor';

    #[On('open-donor-request')]
    public function openRequest(string $target = 'donor'): void
    {
        Gate::authorize('donors.create');

        $this->requestTarget = in_array($target, ['donor', 'main', 'partner'], true) ? $target : 'donor';
        $this->openCreateModal();
    }

    protected function donorSaved(Donor $donor, bool $isCreate): void
    {
        $this->dispatch('donor-requested', donorId: $donor->id, target: $this->requestTarget);

        Flux::toast(
            variant: 'success',
            text: $donor->isPending() ? __('donors.donor_request_pending') : __('donors.donor_created'),
        );
    }
};
?>

<div data-donor-request-form>
    <x-donors.form-modal :open="$showDonorModal" :editing="false" :type-options="$this->donorTypeOptions" />
</div>
