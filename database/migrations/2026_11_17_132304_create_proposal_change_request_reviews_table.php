<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Finance Manager's and the MEAL Manager's review of a change
     * request (Y-09, D-89a, contracts §7): advice, never a second approval.
     */
    public function up(): void
    {
        if (Schema::hasTable('proposal_change_request_reviews')) {
            throw new RuntimeException('proposal_change_request_reviews already exists; nothing was changed.');
        }

        Schema::create('proposal_change_request_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_change_request_id')->constrained('proposal_change_requests', indexName: 'proposal_change_request_reviews_request_id_foreign')->cascadeOnDelete();
            $table->string('slot', 20);
            $table->string('verdict', 20)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'proposal_change_request_reviews_reviewed_by_foreign')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['proposal_change_request_id', 'slot'], 'proposal_change_request_reviews_request_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_change_request_reviews');
    }
};
