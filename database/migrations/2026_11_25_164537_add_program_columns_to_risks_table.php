<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A program-level logframe risk carries `program_id` (and no proposal);
     * a proposal risk copied from it carries `copied_from_id`.
     */
    public function up(): void
    {
        if (Schema::hasColumn('risks', 'program_id') || Schema::hasColumn('risks', 'copied_from_id')) {
            throw new RuntimeException('risks already has program_id or copied_from_id; nothing was changed.');
        }

        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('program_id')
                ->nullable()
                ->after('proposal_id')
                ->constrained('programs', indexName: 'risks_program_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('copied_from_id')
                ->nullable()
                ->after('program_id')
                ->constrained('risks', indexName: 'risks_copied_from_id_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            if (Schema::hasColumn('risks', 'copied_from_id')) {
                $table->dropForeign('risks_copied_from_id_foreign');
                $table->dropColumn('copied_from_id');
            }
            if (Schema::hasColumn('risks', 'program_id')) {
                $table->dropForeign('risks_program_id_foreign');
                $table->dropColumn('program_id');
            }
        });
    }
};
