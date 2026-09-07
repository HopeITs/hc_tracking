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
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('planned_date');
            $table->date('actual_date')->nullable();
            $table->string('status', 50)->default('pending');
            $table->unsignedInteger('sequence')->default(1);
            $table->decimal('weight_percentage', 5, 2)->default(0);
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_milestone_id')->nullable()->constrained('milestones')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
