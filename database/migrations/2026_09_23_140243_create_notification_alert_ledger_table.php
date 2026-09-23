<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which scheduled alert stage already went to which user, so every alert
 * fires once per stage. The name is the spec's (a ledger, not a list of
 * ledgers). Rows are deleted to re-arm a rule for a record that recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_alert_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('rule', 50);
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('stage', 100);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('fired_at');
            $table->timestamps();

            $table->unique(['rule', 'subject_type', 'subject_id', 'stage', 'user_id'], 'notification_alert_ledger_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_alert_ledger');
    }
};
