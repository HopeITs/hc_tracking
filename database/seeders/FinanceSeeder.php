<?php

namespace Database\Seeders;

use App\Enums\ProposalStatus;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use Illuminate\Database\Seeder;

/**
 * The finance layer: bank accounts, payment tranches and the proposal status
 * audit trail.
 *
 * Depends on ProgrammeSeeder.
 */
class FinanceSeeder extends Seeder
{
    /**
     * Seed the finance tables.
     */
    public function run(): void
    {
        $operations = Account::factory()->create(['name' => 'Operations USD']);
        Account::factory()->create(['name' => 'Programmes EUR']);
        Account::factory()->closed()->create(['name' => 'Legacy USD']);

        DonorProposal::query()->each(function (DonorProposal $agreement) use ($operations): void {
            TransfersTracking::factory()->received($operations)->create([
                'donor_proposal_id' => $agreement->id,
                'payment_amount' => round((float) $agreement->budget * 0.4, 2),
                'amount_received' => round((float) $agreement->budget * 0.4, 2),
            ]);

            TransfersTracking::factory()->create([
                'donor_proposal_id' => $agreement->id,
                'payment_amount' => round((float) $agreement->budget * 0.6, 2),
                'amount_received' => round((float) $agreement->budget * 0.6, 2),
                'net_amount_received' => round((float) $agreement->budget * 0.6, 2),
            ]);
        });

        Proposal::query()->each(function (Proposal $proposal): void {
            foreach ([ProposalStatus::ConceptNote, ProposalStatus::Submitted, ProposalStatus::Approved] as $status) {
                ProposalStatusNote::factory()->create([
                    'proposal_id' => $proposal->id,
                    'status' => $status,
                    'context' => 'Moved to '.$status->value.'.',
                ]);
            }
        });
    }
}
