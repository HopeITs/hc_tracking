<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a program combines one indicator's targets across donors
     * (contracts §12.4, D-40/D-46): `shared` (default, highest) or `split`
     * (sum). A column already present stops the run before anything changes.
     */
    public function up(): void
    {
        if (Schema::hasColumn('programs', 'donor_attribution')) {
            throw new RuntimeException('programs.donor_attribution already exists; this migration has partly run.');
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->string('donor_attribution', 20)->default('shared')->after('manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('donor_attribution');
        });
    }
};
