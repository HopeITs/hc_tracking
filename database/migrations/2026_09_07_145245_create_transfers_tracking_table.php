<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfers_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_proposal_id')->constrained('donor_proposal')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->decimal('payment_amount', 14, 2);
            $table->string('amount_transfered_currency', 10)->default('USD');
            $table->date('scheduled_date');
            $table->date('transfer_date')->nullable();
            $table->decimal('fees', 10, 2)->default(0);
            $table->string('fees_currency', 10)->default('USD');
            $table->decimal('amount_received', 14, 2);
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->decimal('net_amount_received', 14, 2);
            $table->string('amount_received_currency', 10)->default('USD');
            $table->date('receive_date')->nullable();
            $table->string('ar_status', 50)->default('pending');
            $table->timestamps();

            $table->index(['donor_proposal_id', 'scheduled_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers_tracking');
    }
};
