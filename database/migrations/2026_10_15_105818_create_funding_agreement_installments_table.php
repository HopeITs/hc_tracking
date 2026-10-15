<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The planned payment schedule of a funding agreement (contracts.md §7, T09).
 *
 * An installment is a promise from the schedule; a `transfers_tracking` row is
 * what actually moves. The unique pair keeps the sequence 1..n without gaps
 * being claimed twice, even when two people edit the schedule at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('funding_agreement_installments')) {
            throw new RuntimeException('funding_agreement_installments already exists; refusing to run twice.');
        }

        Schema::create('funding_agreement_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('funding_agreement_id')->constrained('donor_proposal', indexName: 'funding_agreement_installments_agreement_foreign')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10);
            $table->date('scheduled_date');
            $table->timestamps();

            $table->unique(['funding_agreement_id', 'sequence'], 'funding_agreement_installments_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_agreement_installments');
    }
};
