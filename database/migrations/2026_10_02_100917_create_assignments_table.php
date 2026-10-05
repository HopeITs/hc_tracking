<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per time a person was put in a "responsible" field of a record, so
 * the assignee can acknowledge or reject it and the history stays readable.
 *
 * assignable_type holds a morph-map key. employee_id is an HR employee id (no
 * foreign key: the HR database is read-only), assigned_by / responded_by are
 * tracking users and null out if the user goes. Rows are never deleted, only
 * superseded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('assignable_type', 50);
            $table->unsignedBigInteger('assignable_id');
            $table->string('field', 50);
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['assignable_type', 'assignable_id', 'field']);
        });

        $this->backfillExistingProjectOwners();
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }

    /**
     * Owners assigned before this feature existed are treated as having
     * agreed, so nobody is asked about work already under way.
     */
    private function backfillExistingProjectOwners(): void
    {
        $now = now();

        DB::table('projects')->whereNotNull('user_id')->orderBy('id')->each(function (object $project) use ($now): void {
            DB::table('assignments')->insert([
                'assignable_type' => 'project',
                'assignable_id' => $project->id,
                'field' => 'user_id',
                'employee_id' => $project->user_id,
                'status' => 'acknowledged',
                'responded_at' => $now,
                'response_note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }
};
