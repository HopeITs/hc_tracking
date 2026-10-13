<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central Indicator Library MEAL owns (contracts.md §11.1, D-10).
 *
 * `code` is server-generated as {program code}.{level_ref}.{serial}
 * (App\Support\IndicatorCode) and never typed. `program_id` is an HR program
 * id with no foreign key (the HR database is read-only); null means an
 * institutional (HC) entry. The (program_id, level_ref, serial) index is what
 * IndicatorCode locks to hand out the next serial, so two saves at the same
 * program and level wait for each other instead of reading the same maximum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('library_indicators')) {
            return;
        }

        Schema::create('library_indicators', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50);
            $table->unsignedBigInteger('program_id')->nullable();
            $table->string('level_ref', 20);
            $table->unsignedSmallInteger('serial');
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('definition')->nullable();
            $table->text('calculation_method')->nullable();
            $table->string('measure', 50)->default('numeric');
            $table->string('unit', 50)->nullable();
            $table->string('numerator_label')->nullable();
            $table->string('denominator_label')->nullable();
            $table->string('denominator_basis', 20)->nullable();
            $table->boolean('is_disaggregated')->default(false);
            $table->string('collected_by', 20)->default('project_team');
            $table->boolean('is_cumulative')->default(false);
            $table->string('frequency', 50);
            $table->text('frequency_note')->nullable();
            $table->unsignedSmallInteger('follow_up_months')->nullable();
            $table->string('suggested_level', 50)->nullable();
            $table->text('data_source')->nullable();
            $table->text('means_of_verification')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code', 'library_indicators_code_unique');
            $table->index(['program_id', 'level_ref', 'serial'], 'library_indicators_program_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_indicators');
    }
};
