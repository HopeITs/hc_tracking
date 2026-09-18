<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user can hold several roles at once; this records which one is
     * "active" for display purposes on the Users tab (mirrors hc-hr's
     * assigned-roles/active-role split). Permission checks in this app stay
     * additive across every assigned role (Spatie's normal behavior) — this
     * column does not narrow what a user can do, only which role is shown as
     * primary.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('active_role_id')->nullable()->after('employee_id')->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_role_id');
        });
    }
};
