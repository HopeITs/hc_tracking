<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The assumptions of a proposal's logical framework: what must hold for
     * a result to be reached, each on one result, with whether it still
     * holds (holds / at_risk / broken).
     */
    public function up(): void
    {
        if (Schema::hasTable('assumptions')) {
            throw new RuntimeException('assumptions already exists; nothing was changed.');
        }

        Schema::create('assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals', indexName: 'assumptions_proposal_id_foreign')->cascadeOnDelete();
            $table->foreignId('result_id')->constrained('results', indexName: 'assumptions_result_id_foreign')->cascadeOnDelete();
            $table->text('statement');
            $table->string('status', 20)->default('holds');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assumptions');
    }
};
