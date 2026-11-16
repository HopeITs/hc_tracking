<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A program indicator a strategic plan year is measured by, with the
     * figure to reach that year (null: linked, no figure set yet).
     */
    public function up(): void
    {
        Schema::create('strategic_plan_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategic_plan_year_id');
            $table->unsignedBigInteger('indicator_id');
            $table->decimal('target', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['strategic_plan_year_id', 'indicator_id'], 'strategic_plan_targets_year_indicator_unique');

            $table->foreign('strategic_plan_year_id', 'strategic_plan_targets_strategic_plan_year_id_foreign')
                ->references('id')->on('strategic_plan_years')->cascadeOnDelete();
            $table->foreign('indicator_id', 'strategic_plan_targets_indicator_id_foreign')
                ->references('id')->on('indicators')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_plan_targets');
    }
};
