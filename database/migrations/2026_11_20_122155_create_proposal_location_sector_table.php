<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-11, step 1 (D-93): the sectors of a location row, the twin of
     * `project_sector` (contracts.md §12.5 and §7).
     *
     * Sectors describe the place and carry no date, so they are copied to
     * every row of a project (wave4/AGENT.md §8.4). `sector_id` restricts on
     * delete, as on `project_sector`: a sector in use is deactivated, never
     * deleted. `proposal_location_id` cascades with its row.
     */
    public function up(): void
    {
        if (Schema::hasTable('proposal_location_sector')) {
            throw new RuntimeException('proposal_location_sector already exists; nothing was changed.');
        }

        Schema::create('proposal_location_sector', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_location_id')->constrained('proposal_locations', indexName: 'proposal_location_sector_location_id_foreign')->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained('sectors', indexName: 'proposal_location_sector_sector_id_foreign')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['proposal_location_id', 'sector_id'], 'proposal_location_sector_location_id_sector_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_location_sector');
    }
};
