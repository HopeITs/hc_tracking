<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MEAL-managed risk library (contracts.md §7, W-09, D-61). A proposal's
 * risks are picked from it and nowhere else. An entry a risk was picked from
 * is deactivated, never deleted: `risks.library_risk_id` restricts on delete
 * as the backstop (added by the next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('risk_library')) {
            return;
        }

        Schema::create('risk_library', function (Blueprint $table): void {
            $table->id();
            $table->string('title_en');
            $table->string('title_ar');
            $table->string('category', 40)->nullable();
            $table->unsignedTinyInteger('default_likelihood')->nullable();
            $table->unsignedTinyInteger('default_severity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('title_en', 'risk_library_title_en_unique');
            $table->index(['is_active', 'sort_order'], 'risk_library_active_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_library');
    }
};
