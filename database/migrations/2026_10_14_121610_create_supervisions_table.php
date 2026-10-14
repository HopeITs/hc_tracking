<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervision exceptions and additions on top of the HR direct manager
 * (contracts §10, D-13). Both employee columns are HR ids, so they have no
 * foreign key. `pair_low`/`pair_high` order the two ids, so the unique index
 * refuses a duplicate and a direct A↔B loop in the database itself, even when
 * two admins add them at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supervisions')) {
            return;
        }

        Schema::create('supervisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supervisor_employee_id');
            $table->unsignedBigInteger('supervisee_employee_id');
            $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'supervisions_created_by_foreign')->nullOnDelete();
            $table->timestamps();
            $table->unsignedBigInteger('pair_low')->storedAs('LEAST(`supervisor_employee_id`, `supervisee_employee_id`)');
            $table->unsignedBigInteger('pair_high')->storedAs('GREATEST(`supervisor_employee_id`, `supervisee_employee_id`)');

            $table->unique(['pair_low', 'pair_high'], 'supervisions_pair_unique');
            $table->index('supervisee_employee_id', 'supervisions_supervisee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisions');
    }
};
