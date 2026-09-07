<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('donor_proposal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donors')->restrictOnDelete();
            $table->decimal('budget', 14, 2);
            $table->string('currency', 10)->default('USD');
            $table->string('project_num', 50)->nullable();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donor_proposal');
    }
};
