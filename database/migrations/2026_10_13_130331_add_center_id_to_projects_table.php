<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every project belongs to an HR center (D-30). The column holds an id from
 * the HR database, so it has no foreign key; it stays nullable until every
 * project is mapped (`hr:map-project-centers`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'center_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('center_id')->nullable()->after('city_id');
            $table->index('center_id', 'projects_center_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'center_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex('projects_center_id_index');
            $table->dropColumn('center_id');
        });
    }
};
