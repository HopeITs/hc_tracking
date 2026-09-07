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
        Schema::create('plan_indicators', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();
            $table->decimal('target', 12, 2);
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('period', 50)->default('quarterly');
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_indicators');
    }
};
