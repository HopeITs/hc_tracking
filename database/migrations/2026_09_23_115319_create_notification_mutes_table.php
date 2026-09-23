<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules a user has muted. A mute silences watcher, cascade and top-level
 * notifications for that module; direct notifications still arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50);
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_mutes');
    }
};
