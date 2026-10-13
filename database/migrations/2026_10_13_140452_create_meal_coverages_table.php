<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which MEAL officer validates a project's monthly reports (contracts §12.3,
 * D-12/D-12a): one row per governorate (HR city) and one per project that
 * overrides its governorate. `city_id` and `employee_id` are HR ids, so they
 * have no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meal_coverages')) {
            return;
        }

        Schema::create('meal_coverages', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('city_id')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects', indexName: 'meal_coverages_project_id_foreign')->cascadeOnDelete();
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('assigned_by')->nullable()->constrained('users', indexName: 'meal_coverages_assigned_by_foreign')->nullOnDelete();
            $table->timestamps();

            $table->unique('city_id', 'meal_coverages_city_id_unique');
            $table->unique('project_id', 'meal_coverages_project_id_unique');
            $table->index('employee_id', 'meal_coverages_employee_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_coverages');
    }
};
