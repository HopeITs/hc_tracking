<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number the donor files an agreement under is the external project
     * number, beside the internal number on proposals. Type, length (50),
     * nullability and the absence of an index stay exactly as they are.
     */
    public function up(): void
    {
        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->renameColumn('project_num', 'external_project_number');
        });
    }

    public function down(): void
    {
        Schema::table('donor_proposal', function (Blueprint $table) {
            $table->renameColumn('external_project_number', 'project_num');
        });
    }
};
