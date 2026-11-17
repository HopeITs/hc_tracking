<?php

declare(strict_types=1);

use App\Console\Commands\PlaceBackfillReportTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-02, step 1 (D-75, D-76, D-82, D-88): a template gets its place
     * (nullable for now) and the pivot to its proposals, then existing
     * templates are backfilled. Step 2 tightens the columns. Pre-checks run
     * before any DDL: MySQL does not roll DDL back.
     */
    public function up(): void
    {
        foreach (['city_id', 'program_id', 'center_id', 'city_wide'] as $column) {
            if (Schema::hasColumn('report_templates', $column)) {
                throw new RuntimeException("report_templates.{$column} already exists; nothing was changed.");
            }
        }

        if (Schema::hasTable('proposal_report_template')) {
            throw new RuntimeException('proposal_report_template already exists; nothing was changed.');
        }

        $backfill = new PlaceBackfillReportTemplates;
        $plan = $backfill->plan();
        $without = array_keys(array_filter($plan, fn (array $row): bool => $row['proposal_ids'] === []));

        if ($without !== []) {
            throw new RuntimeException('Templates with no proposal: '.implode(', ', $without).'; nothing was changed.');
        }

        Schema::table('report_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->after('project_id');
            $table->unsignedBigInteger('program_id')->nullable()->after('city_id');
            $table->unsignedBigInteger('center_id')->nullable()->after('program_id');
            $table->boolean('city_wide')->default(false)->after('center_id');
        });

        Schema::create('proposal_report_template', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proposal_id');
            $table->unsignedBigInteger('report_template_id');
            $table->dateTime('joined_at');
            $table->unsignedBigInteger('joined_by')->nullable();
            $table->timestamps();

            $table->unique(['proposal_id', 'report_template_id'], 'proposal_report_template_unique');
            $table->index('report_template_id', 'proposal_report_template_template_index');
            $table->foreign('proposal_id', 'proposal_report_template_proposal_id_foreign')->references('id')->on('proposals')->cascadeOnDelete();
            $table->foreign('report_template_id', 'proposal_report_template_template_id_foreign')->references('id')->on('report_templates')->cascadeOnDelete();
            $table->foreign('joined_by', 'proposal_report_template_joined_by_foreign')->references('id')->on('users')->nullOnDelete();
        });

        $backfill->apply($plan);
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_report_template');

        if (Schema::hasColumn('report_templates', 'city_id')) {
            Schema::table('report_templates', function (Blueprint $table) {
                $table->dropColumn(['city_id', 'program_id', 'center_id', 'city_wide']);
            });
        }
    }
};
