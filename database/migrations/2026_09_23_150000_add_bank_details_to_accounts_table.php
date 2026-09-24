<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `name` becomes `account_name`, and the account gains the fields the
     * finance team actually tracks per bank account: which bank, which
     * currency, the account/IBAN/SWIFT identifiers, the authorized
     * signatories and the two address fields (the account holder's postal
     * address and, separately, the bank's own branch address).
     *
     * `opened_at` also becomes nullable here: several real accounts (cash
     * accounts, accounts inherited without paperwork) have no known opening
     * date, where the column previously required one.
     *
     * doctrine/dbal isn't installed, so nullability/definition changes go
     * through raw SQL — same approach as the milestones status-default
     * migration.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('name', 'account_name');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('account_name');
            $table->string('currency', 10)->nullable()->after('bank_name');
            $table->string('authorized_person')->nullable()->after('currency');
            $table->string('account_number', 100)->nullable()->after('authorized_person');
            $table->string('iban', 100)->nullable()->after('account_number');
            $table->string('swift_code', 50)->nullable()->after('iban');
            $table->string('country', 100)->nullable()->after('swift_code');
            $table->text('po_box_street')->nullable()->after('country');
            $table->text('company_address')->nullable()->after('po_box_street');
        });

        // Backfill any account created before this migration (e.g. the
        // FinanceSeeder demo rows) so the NOT NULL constraints below can be
        // applied without failing on existing data.
        DB::table('accounts')->whereNull('bank_name')->update([
            'bank_name' => 'Unspecified',
            'currency' => 'USD',
            'account_number' => DB::raw("CONCAT('LEGACY-', id)"),
            'country' => 'Syria',
        ]);

        DB::statement('ALTER TABLE accounts MODIFY bank_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY currency VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY account_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY country VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY opened_at DATE NULL');
    }

    /**
     * Rollback caveat: fails once any opened_at is NULL, same caveat as the
     * transfers_tracking nullable-amounts migration.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE accounts MODIFY opened_at DATE NOT NULL');

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name',
                'currency',
                'authorized_person',
                'account_number',
                'iban',
                'swift_code',
                'country',
                'po_box_street',
                'company_address',
            ]);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('account_name', 'name');
        });
    }
};
