<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger of the one approval shift of a proposal's activity calendar
     * (T12 slice 3): a row means ShiftActivitySchedulesOnApproval already ran,
     * so a re-run is a no-op. Same shape as T31's proposal_plan_shifts.
     */
    public function up(): void
    {
        if (Schema::hasTable('activity_schedule_shifts')) {
            throw new RuntimeException('activity_schedule_shifts already exists; this migration has partly run.');
        }

        Schema::create('activity_schedule_shifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proposal_id');
            $table->integer('delta_months');
            $table->unsignedInteger('shifted');
            $table->timestamps();

            $table->foreign('proposal_id', 'activity_schedule_shifts_proposal_id_foreign')
                ->references('id')->on('proposals')->cascadeOnDelete();
            $table->unique('proposal_id', 'activity_schedule_shifts_proposal_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_schedule_shifts');
    }
};
