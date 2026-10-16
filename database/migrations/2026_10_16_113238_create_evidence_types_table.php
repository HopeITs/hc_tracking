<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence types a monthly report line can require (contracts.md §12.4,
 * D-42), managed by MEAL (`evidence-types.manage`). A type some template line
 * or report file uses is deactivated, never deleted: those consumers (T33
 * slice 2, T17) restrict on delete as the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evidence_types')) {
            Schema::create('evidence_types', function (Blueprint $table): void {
                $table->id();
                $table->string('name_ar');
                $table->string('name_en')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['is_active', 'sort_order'], 'evidence_types_active_order_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_types');
    }
};
