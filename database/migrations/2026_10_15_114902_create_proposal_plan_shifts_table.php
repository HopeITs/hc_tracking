<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger of approval rebases (contracts.md §12.2): one row per
     * approval note, so a proposal's plan shifts once per approval however
     * often the listener runs.
     */
    public function up(): void
    {
        Schema::create('proposal_plan_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedBigInteger('approval_note_id');
            $table->integer('delta_days');
            $table->integer('shifted');
            $table->timestamps();

            $table->foreign('proposal_id', 'proposal_plan_shifts_proposal_id_foreign')
                ->references('id')->on('proposals')->cascadeOnDelete();
            $table->foreign('approval_note_id', 'proposal_plan_shifts_approval_note_id_foreign')
                ->references('id')->on('proposal_status_notes')->cascadeOnDelete();
            $table->unique('approval_note_id', 'proposal_plan_shifts_approval_note_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_plan_shifts');
    }
};
