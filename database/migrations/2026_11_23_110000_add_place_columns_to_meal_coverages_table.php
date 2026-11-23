<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_coverages', function (Blueprint $table): void {
            $table->foreignId('program_id')->nullable()->constrained('programs')->cascadeOnDelete();
            $table->unsignedBigInteger('center_id')->nullable();
            $table->boolean('city_wide')->default(false);
            $table->string('scope_key', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meal_coverages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('program_id');
            $table->dropColumn(['center_id', 'city_wide', 'scope_key']);
        });
    }
};
