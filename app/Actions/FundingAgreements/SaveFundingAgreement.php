<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorProposal;
use Illuminate\Support\Facades\Gate;

/**
 * Record a new funding agreement or update an existing one from
 * already-validated input.
 */
class SaveFundingAgreement
{
    /**
     * @param  array<string, mixed>  $validated  keyed by the agreement form's property names
     */
    public function handle(?DonorProposal $existing, array $validated): DonorProposal
    {
        Gate::authorize($existing === null ? 'funding-agreements.create' : 'funding-agreements.update');

        $agreement = $existing ?? new DonorProposal;

        $agreement->fill([
            'donor_id' => (int) $validated['agreementDonorId'],
            'proposal_id' => (int) $validated['agreementProposalId'],
            'budget' => $validated['budget'],
            'currency' => $validated['agreementCurrency'],
            'external_project_number' => $validated['externalProjectNumber'] === '' ? null : $validated['externalProjectNumber'],
        ])->save();

        return $agreement;
    }
}
