<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Models\Donor;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a donor that has never signed a funding agreement.
 *
 * donor_proposal.donor_id RESTRICTs, so a donor that has signed anything stays;
 * so does one named as Main Partner or Partner on a proposal.
 */
class DeleteDonor
{
    /**
     * @return bool false when the donor has agreements and nothing changed
     */
    public function handle(Donor $donor): bool
    {
        Gate::authorize('donors.delete');

        if ($donor->donorProposals()->exists() || $donor->mainPartnerProposals()->exists() || $donor->partnerProposals()->exists()) {
            return false;
        }

        $donor->delete();

        return true;
    }
}
