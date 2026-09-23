<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail: one row per meaningful thing that happened to a record.
 *
 * subject_type/subject_id is polymorphic (morph-map keys), so it has no
 * foreign key and outlives the record it describes on purpose — the Activity
 * tab and global log still read after a delete. program_id is denormalised for
 * the manager cascade and filters, and likewise carries no foreign key.
 * Rows are pruned after two years (Activity::prunable()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('program_id')->nullable();
            $table->string('module', 50);
            $table->string('event', 100);
            $table->json('changes');
            $table->json('properties');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['program_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
