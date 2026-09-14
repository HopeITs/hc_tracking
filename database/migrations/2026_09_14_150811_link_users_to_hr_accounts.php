<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * People log in with their HR account (hr_db.hchr_users), matched to a
     * tracking user by email. The tracking user keeps only what HR does not
     * hold: the application role, two-factor settings and passkeys. The HR
     * account and employee ids are recorded at each login; no foreign key can
     * reach across databases. The local password is no longer checked, so it
     * becomes optional.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('hr_user_id')->nullable()->unique()->after('email');
            $table->unsignedBigInteger('employee_id')->nullable()->index()->after('hr_user_id');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Fails once any user has no local password.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['hr_user_id']);
            $table->dropIndex(['employee_id']);
            $table->dropColumn(['hr_user_id', 'employee_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
