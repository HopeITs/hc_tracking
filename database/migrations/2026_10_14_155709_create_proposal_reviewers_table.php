<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people asked to review a proposal in its concept-note or proposal
 * review stage (contracts.md §7, T07). `employee_id` is an HR id, so it has no
 * foreign key; the unique index keeps one row per person and stage, even when
 * two managers add the same person at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proposal_reviewers')) {
            throw new RuntimeException('proposal_reviewers already exists; refusing to run twice.');
        }

        Schema::create('proposal_reviewers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals', indexName: 'proposal_reviewers_proposal_id_foreign')->cascadeOnDelete();
            $table->string('stage', 20);
            $table->unsignedBigInteger('employee_id');
            $table->string('slot', 30);
            $table->dateTime('seen_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users', indexName: 'proposal_reviewers_added_by_foreign')->nullOnDelete();
            $table->timestamps();

            $table->unique(['proposal_id', 'stage', 'employee_id'], 'proposal_reviewers_proposal_stage_employee_unique');
            $table->index('employee_id', 'proposal_reviewers_employee_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_reviewers');
    }
};
