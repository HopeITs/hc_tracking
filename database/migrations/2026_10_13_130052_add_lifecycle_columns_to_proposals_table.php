<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every proposal gets exactly one donor, taken from its funding agreement.
     *
     * MySQL does not roll back DDL, so the data is checked before anything is
     * altered: a proposal with no agreement, or with several, has no single
     * donor to backfill and Finance must name one before this can run.
     */
    public function up(): void
    {
        $this->assertBackfillIsPossible();

        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedBigInteger('donor_id')->nullable()->after('name');
        });

        DB::transaction(function (): void {
            DB::table('proposals')->select('id')->lazyById()->each(function (object $proposal): void {
                DB::table('proposals')->where('id', $proposal->id)->update([
                    'donor_id' => DB::table('donor_proposal')->where('proposal_id', $proposal->id)->value('donor_id'),
                ]);
            });
        });

        if (DB::table('proposals')->whereNull('donor_id')->exists()) {
            throw new RuntimeException('Some proposals still have no donor after the backfill.');
        }

        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedBigInteger('donor_id')->nullable(false)->change();
            $table->foreign('donor_id', 'proposals_donor_id_foreign')->references('id')->on('donors')->restrictOnDelete();
            $table->unique(['id', 'donor_id'], 'proposals_id_donor_id_unique');

            $table->decimal('requested_budget', 14, 2)->nullable();
            $table->string('requested_currency', 10)->nullable();
            $table->date('status_remind_on')->nullable();
            $table->text('approval_note')->nullable();
            $table->date('final_report_accepted_on')->nullable();
            $table->unsignedBigInteger('final_report_accepted_by')->nullable();
            $table->unsignedBigInteger('previous_proposal_id')->nullable();

            $table->foreign('final_report_accepted_by', 'proposals_final_report_accepted_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('previous_proposal_id', 'proposals_previous_proposal_id_foreign')
                ->references('id')->on('proposals')->nullOnDelete();
            $table->index('previous_proposal_id', 'proposals_previous_proposal_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropForeign('proposals_previous_proposal_id_foreign');
            $table->dropIndex('proposals_previous_proposal_id_index');
            $table->dropForeign('proposals_final_report_accepted_by_foreign');
            $table->dropUnique('proposals_id_donor_id_unique');
            $table->dropForeign('proposals_donor_id_foreign');
            $table->dropColumn([
                'donor_id',
                'requested_budget',
                'requested_currency',
                'status_remind_on',
                'approval_note',
                'final_report_accepted_on',
                'final_report_accepted_by',
                'previous_proposal_id',
            ]);
        });
    }

    private function assertBackfillIsPossible(): void
    {
        $duplicates = DB::table('donor_proposal')
            ->select('proposal_id')
            ->groupBy('proposal_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('proposal_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Proposals with more than one funding agreement (ids: '.$duplicates->implode(', ').'). Finance must name the donor, then re-run.');
        }

        $orphans = DB::table('proposals')
            ->whereNotIn('id', DB::table('donor_proposal')->select('proposal_id'))
            ->pluck('id');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException('Proposals with no funding agreement (ids: '.$orphans->implode(', ').'). Finance must name the donor, then re-run.');
        }
    }
};
