<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The narrative answers of a monthly report (contracts §13, D-43a). The
     * question text is copied at answer time, so a later template edit
     * leaves the report as it was written.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_answers')) {
            throw new RuntimeException('monthly_report_answers already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('monthly_report_id');
            $table->unsignedBigInteger('report_template_question_id')->nullable();
            $table->string('question_text', 1000);
            $table->text('answer')->nullable();
            $table->timestamps();

            $table->foreign('monthly_report_id', 'monthly_report_answers_report_foreign')
                ->references('id')->on('monthly_reports')->cascadeOnDelete();
            $table->foreign('report_template_question_id', 'monthly_report_answers_question_foreign')
                ->references('id')->on('report_template_questions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_answers');
    }
};
