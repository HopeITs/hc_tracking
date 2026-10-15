<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated transfers and the fourth date (contracts.md §7, T09; D-31, D-31a).
 *
 * `source` separates a row typed in by Finance (`manual`, every existing row)
 * from one generated out of an installment (`auto`). `installment_id` is
 * unique when set, so an installment can never produce two transfers.
 * `arrival_date` is when the money reached the bank or transfer company.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['source', 'installment_id', 'arrival_date'] as $column) {
            if (Schema::hasColumn('transfers_tracking', $column)) {
                throw new RuntimeException("transfers_tracking.{$column} already exists; refusing to run twice.");
            }
        }

        if (! Schema::hasTable('funding_agreement_installments')) {
            throw new RuntimeException('funding_agreement_installments must exist first.');
        }

        Schema::table('transfers_tracking', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual')->after('ar_status');
            $table->foreignId('installment_id')->nullable()->after('source')
                ->constrained('funding_agreement_installments', indexName: 'transfers_tracking_installment_id_foreign')->nullOnDelete();
            $table->date('arrival_date')->nullable()->after('transfer_date');

            $table->unique('installment_id', 'transfers_tracking_installment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transfers_tracking', function (Blueprint $table): void {
            $table->dropUnique('transfers_tracking_installment_id_unique');
            $table->dropForeign('transfers_tracking_installment_id_foreign');
            $table->dropColumn(['source', 'installment_id', 'arrival_date']);
        });
    }
};
