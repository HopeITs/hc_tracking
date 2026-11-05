<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An active proposal can be extended (W-08, D-60): the original
     * `actual_date_end` stays, the new end and the reason live beside it.
     */
    public function up(): void
    {
        foreach (['extended_date_end', 'extension_reason'] as $column) {
            if (Schema::hasColumn('proposals', $column)) {
                throw new RuntimeException("proposals.{$column} already exists; nothing was changed.");
            }
        }

        Schema::table('proposals', function (Blueprint $table) {
            $table->date('extended_date_end')->nullable();
            $table->text('extension_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['extended_date_end', 'extension_reason']);
        });
    }
};
