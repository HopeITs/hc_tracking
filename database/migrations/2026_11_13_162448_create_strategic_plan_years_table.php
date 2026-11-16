<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One year of a strategic plan: what must be achieved that year, in the
     * MEAL manager's words.
     */
    public function up(): void
    {
        Schema::create('strategic_plan_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategic_plan_id');
            $table->unsignedSmallInteger('year');
            $table->text('goals')->nullable();
            $table->timestamps();

            $table->unique(['strategic_plan_id', 'year'], 'strategic_plan_years_plan_year_unique');

            $table->foreign('strategic_plan_id', 'strategic_plan_years_strategic_plan_id_foreign')
                ->references('id')->on('strategic_plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_plan_years');
    }
};
