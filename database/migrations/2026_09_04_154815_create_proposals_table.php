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
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('planned_date_begin');
            $table->date('planned_date_end');
            $table->date('actual_date_begin')->nullable();
            $table->date('actual_date_end')->nullable();
            $table->string('status', 50)->default('concept note');
            $table->string('phase', 50)->default('planning');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
