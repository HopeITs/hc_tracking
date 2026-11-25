<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Program requests are retired (D-68, user decision 2026-11-25): tracking
     * owns its programs, so a proposal row picks an existing program and
     * whoever holds `programs.create` adds a missing one on the Programs page.
     *
     * The activity and notifications of the old request events go first,
     * since no foreign key reaches them and their subject is no longer in the
     * morph map; then the table goes. `proposal_locations.is_new` stays.
     */
    public function up(): void
    {
        $activityIds = DB::table('activities')
            ->where(fn ($query) => $query->where('event', 'like', 'program\_request.%')->orWhere('subject_type', 'program_request'))
            ->pluck('id');

        foreach ($activityIds->chunk(500) as $chunk) {
            DB::table('notifications')->whereIn('activity_id', $chunk->all())->delete();
            DB::table('activities')->whereIn('id', $chunk->all())->delete();
        }

        DB::table('notifications')->where('subject_type', 'program_request')->delete();

        Schema::dropIfExists('program_requests');
    }

    /**
     * Recreates the empty table as W-34a made it; the removed rows, activity
     * and notifications do not come back.
     */
    public function down(): void
    {
        if (Schema::hasTable('program_requests')) {
            return;
        }

        Schema::create('program_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_location_id')->constrained('proposal_locations', indexName: 'program_requests_proposal_location_id_foreign')->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('hr_program_id')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'program_requests_requested_by_foreign')->nullOnDelete();
            $table->timestamps();

            $table->unique('proposal_location_id', 'program_requests_proposal_location_id_unique');
            $table->index('status', 'program_requests_status_index');
        });
    }
};
