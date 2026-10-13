<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One agreement per proposal, and the agreement's donor is the proposal's.
     *
     * The composite key restricts on both delete and update (never cascade):
     * changing a proposal's donor while an agreement exists must be refused.
     * The existing single-column foreign keys stay.
     */
    public function up(): void
    {
        $duplicates = DB::table('donor_proposal')
            ->select('proposal_id')
            ->groupBy('proposal_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('proposal_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Proposals with more than one funding agreement (ids: '.$duplicates->implode(', ').').');
        }

        $mismatched = DB::table('donor_proposal')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->whereColumn('proposals.donor_id', '!=', 'donor_proposal.donor_id')
            ->pluck('donor_proposal.id');

        if ($mismatched->isNotEmpty()) {
            throw new RuntimeException('Agreements whose donor differs from their proposal donor (ids: '.$mismatched->implode(', ').').');
        }

        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->unique('proposal_id', 'donor_proposal_proposal_id_unique');
            $table->foreign(['proposal_id', 'donor_id'], 'donor_proposal_proposal_donor_foreign')
                ->references(['id', 'donor_id'])->on('proposals')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->dropForeign('donor_proposal_proposal_donor_foreign');
            $table->dropUnique('donor_proposal_proposal_id_unique');
        });
    }
};
