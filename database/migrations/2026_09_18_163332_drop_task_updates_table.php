<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The task-updates comment log was removed from the product entirely —
     * no view, model, or query reads from this table any more.
     */
    public function up(): void
    {
        Schema::dropIfExists('task_updates');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('task_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('comment');
            $table->timestamp('date')->useCurrent();
            $table->timestamps();

            $table->index(['task_id', 'date']);
        });
    }
};
