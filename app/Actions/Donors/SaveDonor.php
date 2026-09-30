<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Models\Donor;
use Illuminate\Support\Facades\Gate;

/**
 * Record a new donor or update an existing one from already-validated input.
 */
class SaveDonor
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the donor form's property names
     */
    public function handle(?Donor $existing, array $validated): Donor
    {
        Gate::authorize($existing === null ? 'donors.create' : 'donors.update');

        $donor = $existing ?? new Donor;

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

        return $donor;
    }
}
