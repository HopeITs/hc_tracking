<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reports a donor is owed under one funding agreement: what, by when, and
 * whether it went out.
 *
 * Deleting the agreement cascades, exactly as its tranches do: acting on the
 * agreement itself is the deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_proposal_id')->constrained('donor_proposal')->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 50)->default('narrative');
            $table->date('due_on');
            $table->date('submitted_on')->nullable();
            $table->timestamps();

            $table->index(['donor_proposal_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_reports');
    }
};
