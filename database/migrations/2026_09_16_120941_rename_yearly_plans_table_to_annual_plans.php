<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('yearly_plans')) {
            Schema::rename('yearly_plans', 'annual_plans');
        }

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->dropForeign('yearly_plans_program_id_foreign');
            $table->dropUnique('yearly_plans_program_id_year_unique');
            $table->dropIndex('yearly_plans_manager_id_foreign');
        });

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->foreign('program_id')->references('id')->on('programs')->cascadeOnDelete();
            $table->unique(['program_id', 'year']);
            $table->index('manager_id');
        });

        DB::table('plan_indicators')->where('source_type', 'yearly_plan')->update(['source_type' => 'annual_plan']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('plan_indicators')->where('source_type', 'annual_plan')->update(['source_type' => 'yearly_plan']);

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->dropForeign('annual_plans_program_id_foreign');
            $table->dropUnique('annual_plans_program_id_year_unique');
            $table->dropIndex('annual_plans_manager_id_index');
        });

        Schema::table('annual_plans', function (Blueprint $table) {
            $table->foreign('program_id')->references('id')->on('programs')->cascadeOnDelete();
            $table->unique(['program_id', 'year']);
            $table->index('manager_id', 'yearly_plans_manager_id_foreign');
        });

        Schema::rename('annual_plans', 'yearly_plans');
    }
};
