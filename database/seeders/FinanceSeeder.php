<?php

namespace Database\Seeders;

use App\Enums\DonorReportType;
use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Models\Account;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use Illuminate\Database\Seeder;

/**
 * The finance layer: bank accounts, payment tranches, the reports owed to
 * donors, the proposal status audit trail and its concept-note reviewers.
 *
 * Depends on DemoDeliverySeeder.
 */
class FinanceSeeder extends Seeder
{
    /**
     * Seed the finance tables.
     */
    public function run(): void
    {
        $operations = Account::factory()->create(['account_name' => 'Operations USD']);
        Account::factory()->create(['account_name' => 'Programmes EUR']);
        Account::factory()->closed()->create(['account_name' => 'Legacy USD']);

        DonorProposal::query()->each(function (DonorProposal $agreement) use ($operations): void {
            DonorReport::factory()->submitted()->create([
                'donor_proposal_id' => $agreement->id,
                'title' => 'Interim narrative report',
                'due_on' => now()->subMonths(2)->toDateString(),
            ]);

            DonorReport::factory()->create([
                'donor_proposal_id' => $agreement->id,
                'title' => 'Interim financial report',
                'type' => DonorReportType::Financial,
                'due_on' => now()->addMonth()->toDateString(),
            ]);

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

            // The owners of its projects reviewed the concept note (T07).
            $proposal->projects()->pluck('user_id')->filter()->unique()->each(fn (int|string $ownerId) => ProposalReviewer::factory()->seen()->create([
                'proposal_id' => $proposal->id,
                'stage' => ReviewStage::ConceptNote,
                'employee_id' => (int) $ownerId,
                'note' => 'Read and agreed with the problem statement.',
            ]));
        });
    }
}
