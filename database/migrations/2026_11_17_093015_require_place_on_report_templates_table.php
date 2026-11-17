<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-02, step 2: city and program become required, `project_id` stops
     * being unique and becomes nullable, and the place index is added (not
     * unique: two templates may share a place, D-76).
     */
    public function up(): void
    {
        if (DB::table('report_templates')->where(fn ($query) => $query->whereNull('city_id')->orWhereNull('program_id'))->exists()) {
            throw new RuntimeException('Templates without a city or program remain; run report-templates:place-backfill. Nothing was changed.');
        }

        Schema::table('report_templates', function (Blueprint $table) {
            $table->dropForeign('report_templates_project_id_foreign');
            $table->dropUnique('report_templates_project_id_unique');
        });

        Schema::table('report_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable(false)->change();
            $table->unsignedBigInteger('program_id')->nullable(false)->change();
            $table->unsignedBigInteger('project_id')->nullable()->change();

            $table->index('project_id', 'report_templates_project_id_index');
            $table->foreign('project_id', 'report_templates_project_id_foreign')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('program_id', 'report_templates_program_id_foreign')->references('id')->on('programs')->restrictOnDelete();
            $table->index('city_id', 'report_templates_city_id_index');
            $table->index('center_id', 'report_templates_center_id_index');
            $table->index(['city_id', 'program_id', 'center_id', 'city_wide'], 'report_templates_place_index');
        });
    }

    /**
     * Restores the unique `project_id`. A template with no project (made
     * after Y-02) or two templates of one project cannot be restored, so
     * down() stops before DDL and says which.
     */
    public function down(): void
    {
        $blocking = DB::table('report_templates')
            ->whereNull('project_id')
            ->orWhereIn('project_id', fn ($query) => $query->select('project_id')->from('report_templates')->whereNotNull('project_id')->groupBy('project_id')->havingRaw('COUNT(*) > 1'))
            ->pluck('id');

        if ($blocking->isNotEmpty()) {
            throw new RuntimeException('Templates without a project or sharing one cannot get a unique project_id back: '.$blocking->implode(', ').'. Nothing was changed.');
        }

        Schema::table('report_templates', function (Blueprint $table) {
            $table->dropIndex('report_templates_place_index');
            $table->dropIndex('report_templates_center_id_index');
            $table->dropIndex('report_templates_city_id_index');
            $table->dropForeign('report_templates_program_id_foreign');
            $table->dropForeign('report_templates_project_id_foreign');
            $table->dropIndex('report_templates_project_id_index');
        });

        Schema::table('report_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
            $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->unsignedBigInteger('program_id')->nullable()->change();

            $table->unique('project_id', 'report_templates_project_id_unique');
            $table->foreign('project_id', 'report_templates_project_id_foreign')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
