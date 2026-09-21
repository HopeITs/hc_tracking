<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn report_files from typed references into real stored files.
     *
     * Mirrors the storage columns on `media`: `disk` is stored per row so a
     * later FILESYSTEM_DISK change never orphans files already written, and
     * defaults to the private 'local' disk. `original_name` and `uploaded_by`
     * are nullable because rows recorded before this migration are reference
     * records only — no file was ever written for them and no uploader was
     * captured. `uploaded_by` nulls on user delete: the evidence outlives
     * the account that uploaded it.
     */
    public function up(): void
    {
        Schema::table('report_files', function (Blueprint $table): void {
            $table->string('disk', 50)->default('local')->after('path');
            $table->string('original_name')->nullable()->after('disk');
            $table->string('mime_type', 100)->nullable()->after('original_name');
            $table->unsignedBigInteger('size')->nullable()->after('mime_type');
            $table->foreignId('uploaded_by')->nullable()->after('type')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_files', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['disk', 'original_name', 'mime_type', 'size']);
        });
    }
};
