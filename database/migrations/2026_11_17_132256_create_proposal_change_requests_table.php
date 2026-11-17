<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A change asked for on an approved proposal (Y-09, D-89, D-89a,
     * contracts §7). It changes nothing until the PR Manager approves it.
     */
    public function up(): void
    {
        if (Schema::hasTable('proposal_change_requests')) {
            throw new RuntimeException('proposal_change_requests already exists; nothing was changed.');
        }

        Schema::create('proposal_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals', indexName: 'proposal_change_requests_proposal_id_foreign')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->constrained('users', indexName: 'proposal_change_requests_requested_by_foreign')->restrictOnDelete();
            $table->text('reason');
            $table->json('changes');
            $table->json('payload');
            $table->foreignId('decided_by')->nullable()->constrained('users', indexName: 'proposal_change_requests_decided_by_foreign')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['proposal_id', 'status'], 'proposal_change_requests_proposal_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_change_request_reviews');
        Schema::dropIfExists('proposal_change_requests');
    }
};
