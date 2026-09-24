<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Names, emails and passwords of people, and the names of programs, are
     * owned by HR. Tracking stops keeping copies of them and reads them from
     * HR instead: a user row keeps only its link to the HR account (hr_user_id,
     * now required) and what HR does not hold; a program row keeps only its id
     * and the tracking-owned strategy and manager links.
     *
     * A user not linked yet is linked by email first. The migration stops,
     * changing nothing, if a user has no HR account at all.
     */
    public function up(): void
    {
        $this->linkUsersToHrAccounts();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['name', 'email', 'password']);
            $table->unsignedBigInteger('hr_user_id')->nullable(false)->change();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * The copied columns come back filled from HR.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->string('email')->nullable()->after('name');
            $table->string('password')->nullable()->after('email_verified_at');
            $table->unsignedBigInteger('hr_user_id')->nullable()->change();
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
        });

        $accounts = DB::connection('hr')->table('users')->get(['id', 'employee_id', 'email'])->keyBy('id');
        $employees = DB::connection('hr')->table('employees')->get(['id', 'first_name_en', 'last_name_en'])->keyBy('id');

        foreach (DB::table('users')->get(['id', 'hr_user_id']) as $user) {
            $account = $accounts->get($user->hr_user_id);
            $employee = $account === null ? null : $employees->get($account->employee_id);

            DB::table('users')->where('id', $user->id)->update([
                'email' => $account?->email ?? "user-{$user->id}@invalid",
                'name' => $employee !== null ? trim($employee->first_name_en.' '.$employee->last_name_en) : ($account?->email ?? ''),
            ]);
        }

        foreach (DB::connection('hr')->table('programs')->get(['id', 'name']) as $program) {
            DB::table('programs')->where('id', $program->id)->update(['name' => trim((string) $program->name)]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->unique()->change();
        });
    }

    /**
     * Link every user without an HR account id to the HR account sharing its
     * email, refusing to continue when one has no HR account.
     */
    private function linkUsersToHrAccounts(): void
    {
        $unlinked = DB::table('users')->whereNull('hr_user_id')->get(['id', 'email']);

        if ($unlinked->isEmpty()) {
            return;
        }

        $accounts = DB::connection('hr')->table('users')
            ->whereIn('email', $unlinked->pluck('email')->all())
            ->get(['id', 'employee_id', 'email'])
            ->keyBy(fn (object $account): string => mb_strtolower($account->email));

        $missing = $unlinked->reject(fn (object $user): bool => $accounts->has(mb_strtolower($user->email)));

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('These users have no HR account; give them one in HR or delete them first: '.$missing->pluck('email')->implode(', '));
        }

        foreach ($unlinked as $user) {
            $account = $accounts->get(mb_strtolower($user->email));

            DB::table('users')->where('id', $user->id)->update([
                'hr_user_id' => $account->id,
                'employee_id' => $account->employee_id,
            ]);
        }
    }
};
