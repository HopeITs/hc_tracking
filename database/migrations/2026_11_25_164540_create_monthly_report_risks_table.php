<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a monthly report said about each logframe risk: did it happen.
     */
    public function up(): void
    {
        if (Schema::hasTable('monthly_report_risks')) {
            throw new RuntimeException('monthly_report_risks already exists; this migration has partly run.');
        }

        Schema::create('monthly_report_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->boolean('happened')->default(false);
            $table->timestamps();

            $table->unique(['monthly_report_id', 'risk_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_risks');
    }
};
