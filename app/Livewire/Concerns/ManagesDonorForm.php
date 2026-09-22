<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\DonorType;
use App\Models\Donor;
use Livewire\Attributes\Computed;

/**
 * The donor profile form, shared by the donor directory and the donor page so
 * a donor is recorded and edited by one set of rules.
 *
 * The using component renders resources/views/components/donors/form-modal
 * and decides what happens after a save through donorSaved(). A page that
 * belongs to one donor pins the form to it through donorFormScope(), so a
 * tampered payload cannot edit another donor or create a new one.
 */
trait ManagesDonorForm
{
    public bool $showDonorModal = false;

    public ?int $editingDonorId = null;

    public string $name = '';

    public string $donorType = '';

    public string $contactPerson = '';

    public string $email = '';

    public string $phone = '';

    public string $country = '';

    public string $notes = '';

    public string $lastContactedOn = '';

    public string $nextStep = '';

    public string $nextStepDueOn = '';

    /**
     * Called after a donor is recorded or edited.
     */
    abstract protected function donorSaved(Donor $donor, bool $isCreate): void;

    /**
     * The one donor this page may edit, or null when it may edit or create any.
     */
    protected function donorFormScope(): ?int
    {
        return null;
    }

    /**
     * The classification options offered in the donor form.
     *
     * @return array<int, DonorType>
     */
    #[Computed]
    public function donorTypeOptions(): array
    {
        return DonorType::cases();
    }

    public function openCreateModal(): void
    {
        if ($this->donorFormScope() !== null) {
            return;
        }

        $this->resetDonorForm();
        $this->showDonorModal = true;
    }

    public function openEditModal(int $donorId): void
    {
        $donorId = $this->donorFormScope() ?? $donorId;

        $donor = Donor::query()
            ->select(['id', 'name', 'type', 'contact_person', 'email', 'phone', 'country', 'notes', 'last_contacted_on', 'next_step', 'next_step_due_on'])
            ->findOrFail($donorId);

        $this->resetValidation();
        $this->editingDonorId = $donor->id;
        $this->name = $donor->name;
        $this->donorType = $donor->type?->value ?? '';
        $this->contactPerson = (string) $donor->contact_person;
        $this->email = (string) $donor->email;
        $this->phone = (string) $donor->phone;
        $this->country = (string) $donor->country;
        $this->notes = (string) $donor->notes;
        $this->lastContactedOn = (string) $donor->last_contacted_on?->toDateString();
        $this->nextStep = (string) $donor->next_step;
        $this->nextStepDueOn = (string) $donor->next_step_due_on?->toDateString();
        $this->showDonorModal = true;
    }

    public function saveDonor(): void
    {
        $this->name = trim($this->name);
        $this->editingDonorId = $this->donorFormScope() ?? $this->editingDonorId;

        // Deliberately no unique rule: donors.name is a plain non-unique
        // VARCHAR by standing decision.
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'donorType' => ['nullable', 'string', 'in:'.implode(',', DonorType::values())],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lastContactedOn' => ['nullable', 'date', 'before_or_equal:today'],
            'nextStep' => ['nullable', 'string', 'max:255'],
            'nextStepDueOn' => ['nullable', 'date', 'required_with:nextStep'],
        ], [
            'name.required' => __('donors.enter_a_donor_name'),
            'lastContactedOn.before_or_equal' => __('donors.last_contact_cannot_be_in_the_future'),
            'nextStepDueOn.required_with' => __('donors.give_the_next_step_a_due_date'),
        ]);

        $donor = $this->editingDonorId === null
            ? new Donor
            : Donor::query()->findOrFail($this->editingDonorId);

        $donor->fill([
            'name' => $validated['name'],
            'type' => filled($validated['donorType']) ? $validated['donorType'] : null,
            'contact_person' => filled($validated['contactPerson']) ? $validated['contactPerson'] : null,
            'email' => filled($validated['email']) ? $validated['email'] : null,
            'phone' => filled($validated['phone']) ? $validated['phone'] : null,
            'country' => filled($validated['country']) ? $validated['country'] : null,
            'notes' => filled($validated['notes']) ? $validated['notes'] : null,
            'last_contacted_on' => filled($validated['lastContactedOn']) ? $validated['lastContactedOn'] : null,
            'next_step' => filled($validated['nextStep']) ? $validated['nextStep'] : null,
            'next_step_due_on' => filled($validated['nextStepDueOn']) ? $validated['nextStepDueOn'] : null,
        ])->save();

        $isCreate = $donor->wasRecentlyCreated;

        $this->showDonorModal = false;
        $this->resetDonorForm();

        $this->donorSaved($donor, $isCreate);
    }

    public function closeDonorModal(): void
    {
        $this->showDonorModal = false;
        $this->resetDonorForm();
    }

    protected function resetDonorForm(): void
    {
        $this->resetValidation();
        $this->editingDonorId = null;
        $this->name = '';
        $this->donorType = '';
        $this->contactPerson = '';
        $this->email = '';
        $this->phone = '';
        $this->country = '';
        $this->notes = '';
        $this->lastContactedOn = '';
        $this->nextStep = '';
        $this->nextStepDueOn = '';
    }
}
