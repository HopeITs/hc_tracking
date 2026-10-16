<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One monthly report per project per month (contracts §13, D-04b, D-29).
     * `period` is the first day of the month. Narrative lives in
     * `monthly_report_answers` (D-43a), so this table carries no text beyond
     * the return reason. Every user column restricts on delete: whoever
     * prepared, submitted, validated or returned a report stays on record.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_reports')) {
            throw new RuntimeException('monthly_reports already exists; this migration has partly run.');
        }

        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->unsignedBigInteger('project_id');
            $table->date('period');
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('validated_at')->nullable();
            $table->unsignedBigInteger('validated_by')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->text('return_reason')->nullable();
            $table->dateTime('seen_by_meal_at')->nullable();
            $table->timestamps();

            $table->unique('slug', 'monthly_reports_slug_unique');
            $table->unique(['project_id', 'period'], 'monthly_reports_project_period_unique');
            $table->index('status', 'monthly_reports_status_index');

            $table->foreign('project_id', 'monthly_reports_project_id_foreign')
                ->references('id')->on('projects')->restrictOnDelete();

            foreach (['prepared_by', 'submitted_by', 'validated_by', 'returned_by'] as $column) {
                $table->foreign($column, "monthly_reports_{$column}_foreign")
                    ->references('id')->on('users')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
    }
};
