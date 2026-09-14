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
        Schema::table('transfers_tracking', function (Blueprint $table) {
            $table->decimal('amount_received', 14, 2)->nullable()->change();
            $table->decimal('net_amount_received', 14, 2)->nullable()->change();
            $table->date('scheduled_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfers_tracking', function (Blueprint $table) {
            $table->decimal('amount_received', 14, 2)->nullable(false)->change();
            $table->decimal('net_amount_received', 14, 2)->nullable(false)->change();
            $table->date('scheduled_date')->nullable(false)->change();
        });
    }
};
