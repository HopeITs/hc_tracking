<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The scheduling layer behind the project Gantt: links between milestones and
 * tasks, saved baselines, and each project's working week.
 *
 * Schedule items are milestones or tasks, addressed by a short type key
 * ('milestone' / 'task') plus id. There is no foreign key on the item columns
 * (they are polymorphic); ScheduleItemObserver deletes a milestone's or task's
 * links and baseline rows when it is deleted through Eloquent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('predecessor_type', 20);
            $table->unsignedBigInteger('predecessor_id');
            $table->string('successor_type', 20);
            $table->unsignedBigInteger('successor_id');
            $table->string('type', 2)->default('fs');
            $table->integer('lag_days')->default(0);
            $table->timestamps();

            $table->unique(['predecessor_type', 'predecessor_id', 'successor_type', 'successor_id'], 'schedule_dependencies_pair_unique');
            $table->index(['successor_type', 'successor_id']);
        });

        Schema::create('schedule_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('schedule_baseline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_baseline_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->unsignedBigInteger('item_id');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamps();

            $table->unique(['schedule_baseline_id', 'item_type', 'item_id'], 'schedule_baseline_items_item_unique');
            $table->index(['item_type', 'item_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('schedule_weekend', 10)->default('sat-sun')->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('schedule_weekend');
        });

        Schema::dropIfExists('schedule_baseline_items');
        Schema::dropIfExists('schedule_baselines');
        Schema::dropIfExists('schedule_dependencies');
    }
};
