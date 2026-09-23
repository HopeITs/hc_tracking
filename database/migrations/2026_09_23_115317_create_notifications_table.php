<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database-notification table plus the columns the inbox filters,
 * groups and pins on.
 *
 * `data` holds the translation key, its params, the merge count and the
 * optional link / quick actions; the sentence is rendered in the reader's
 * language at display time. A row whose subject was deleted stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type', 50);
            $table->unsignedBigInteger('notifiable_id');
            $table->json('data');
            $table->string('priority', 20)->default('info');
            $table->string('module', 50);
            $table->string('kind', 20)->default('action');
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('group_key', 120)->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['notifiable_id', 'group_key', 'read_at']);
            $table->index(['notifiable_id', 'subject_type', 'subject_id', 'read_at'], 'notifications_subject_unread_index');
            $table->index('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
