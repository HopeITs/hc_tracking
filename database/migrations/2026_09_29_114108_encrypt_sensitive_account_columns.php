<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The four sensitive account columns, with their nullability.
     *
     * @var array<string, bool>
     */
    private const array Columns = [
        'account_number' => false,
        'iban' => true,
        'swift_code' => true,
        'authorized_person' => true,
    ];

    /**
     * Encrypt the bank identifiers at rest. Ciphertext is several times longer
     * than the value, so the columns become TEXT. `account_number_last4` keeps
     * the directory searchable by the last four characters, the one thing a
     * LIKE over ciphertext can no longer do.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('account_number_last4', 4)->nullable()->after('account_number')->index();
        });

        foreach (self::Columns as $column => $nullable) {
            DB::statement(sprintf('ALTER TABLE accounts MODIFY %s TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci %s', $column, $nullable ? 'NULL' : 'NOT NULL'));
        }

        DB::table('accounts')->orderBy('id')->chunkById(100, function ($accounts) {
            foreach ($accounts as $account) {
                $update = ['account_number_last4' => substr(preg_replace('/[^A-Za-z0-9]/', '', $account->account_number), -4)];

                foreach (array_keys(self::Columns) as $column) {
                    $update[$column] = $account->{$column} === null ? null : Crypt::encryptString($account->{$column});
                }

                DB::table('accounts')->where('id', $account->id)->update($update);
            }
        });
    }

    /**
     * Decrypt back to plain columns. Fails on a value longer than the original
     * column allowed, which cannot happen for data written through the form.
     */
    public function down(): void
    {
        DB::table('accounts')->orderBy('id')->chunkById(100, function ($accounts) {
            foreach ($accounts as $account) {
                $update = [];

                foreach (array_keys(self::Columns) as $column) {
                    $update[$column] = $account->{$column} === null ? null : Crypt::decryptString($account->{$column});
                }

                DB::table('accounts')->where('id', $account->id)->update($update);
            }
        });

        DB::statement('ALTER TABLE accounts MODIFY account_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY iban VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        DB::statement('ALTER TABLE accounts MODIFY swift_code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
        DB::statement('ALTER TABLE accounts MODIFY authorized_person VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['account_number_last4']);
            $table->dropColumn('account_number_last4');
        });
    }
};
