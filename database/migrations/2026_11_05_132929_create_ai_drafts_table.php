<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per answer the AI drafted: the text it wrote, the call that
     * wrote it and the officer's verdict. Comparing drafted_text with the
     * answer as it stands shows how much the officer had to change.
     */
    public function up(): void
    {
        Schema::create('ai_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('monthly_report_answer_id')->constrained()->cascadeOnDelete();
            $table->text('drafted_text');
            $table->string('rating', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_drafts');
    }
};
