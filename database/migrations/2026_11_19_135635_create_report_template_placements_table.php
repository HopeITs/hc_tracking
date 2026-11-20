<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_template_placements', function (Blueprint $table): void {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('city_id');
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('center_id')->nullable();
            $table->boolean('city_wide')->default(false);
            $table->string('status', 20)->default('pending');
            $table->foreignId('report_template_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['proposal_id', 'city_id', 'program_id', 'center_id', 'city_wide'], 'template_placements_proposal_place_unique');
            $table->index(['status', 'city_id', 'program_id'], 'template_placements_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_template_placements');
    }
};
