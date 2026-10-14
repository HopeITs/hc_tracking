<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which file key encrypted each stored upload (contracts §16).
 * NULL means a legacy plaintext file; there is deliberately no backfill, the
 * encrypt-existing command sets the id once a file has been re-written.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const Tables = ['media', 'report_files', 'task_attachments'];

    public function up(): void
    {
        foreach (self::Tables as $table) {
            if (Schema::hasColumn($table, 'encryption_key_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('encryption_key_id', 20)->nullable()->after('size');
            });
        }
    }

    /**
     * Refuses once any file is encrypted: dropping the column would leave
     * ciphertext that nothing knows how to open.
     */
    public function down(): void
    {
        foreach (self::Tables as $table) {
            if (Schema::hasColumn($table, 'encryption_key_id') && DB::table($table)->whereNotNull('encryption_key_id')->exists()) {
                throw new RuntimeException("Cannot drop {$table}.encryption_key_id while encrypted files exist; decrypt them first.");
            }
        }

        foreach (self::Tables as $table) {
            if (! Schema::hasColumn($table, 'encryption_key_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('encryption_key_id');
            });
        }
    }
};
