<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The strategic pillars of a strategy (contracts §11.2, D-35). A strategy
     * with pillars cannot be deleted while they exist.
     */
    public function up(): void
    {
        if (Schema::hasTable('strategic_pillars')) {
            throw new RuntimeException('strategic_pillars already exists; this migration has partly run.');
        }

        Schema::create('strategic_pillars', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategy_id');
            $table->string('code', 20);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('goal_ar')->nullable();
            $table->text('goal_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('strategy_id', 'strategic_pillars_strategy_id_foreign')
                ->references('id')->on('strategies')->restrictOnDelete();
            $table->unique(['strategy_id', 'code'], 'strategic_pillars_strategy_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategic_pillars');
    }
};
