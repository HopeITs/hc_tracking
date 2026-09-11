<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery sites, each with a lifecycle, a period and a beneficiary target.
     *
     * `status` is NOT NULL and defaults to 'draft', so a project always has a
     * lifecycle state. The dates are nullable because a draft has no agreed
     * period yet.
     *
     * Beneficiary demographics are FIVE DISCRETE COLUMNS, not one JSON blob.
     * MariaDB implements $table->json() as longtext with a CHECK constraint
     * (see .ai/rules/feature.md), which cannot be indexed, aggregated or summed
     * without extracting it row by row. Donor reporting asks "how many women
     * did we reach across the portfolio" constantly, and that must be a SUM
     * over a column.
     *
     * `target_beneficiaries` is the headline total and is deliberately NOT a
     * generated column over the four breakdown columns: a proposal often
     * commits a total before the disaggregation is agreed, so the two are
     * allowed to disagree until it is.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 50)->default('draft');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('target_beneficiaries')->nullable();
            $table->unsignedInteger('target_men')->nullable();
            $table->unsignedInteger('target_women')->nullable();
            $table->unsignedInteger('target_boys')->nullable();
            $table->unsignedInteger('target_girls')->nullable();
            $table->foreignId('city_id')->constrained('cities')->restrictOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index(['start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
