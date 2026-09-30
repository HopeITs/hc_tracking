<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The account columns stored encrypted from now on.
     *
     * @var list<string>
     */
    private const EncryptedColumns = ['account_number', 'iban'];

    /**
     * What an account number or IBAN recorded in the activity log becomes.
     */
    private const Redacted = '[redacted]';

    /**
     * `account_number` and `iban` are encrypted at rest with the APP_KEY
     * (the Account model casts both as 'encrypted'), so a leaked database
     * dump or backup no longer exposes the organisation's bank details.
     *
     * The ciphertext is far longer than 100 characters, so both columns
     * become TEXT first. doctrine/dbal isn't installed, so the column change
     * goes through raw SQL, same approach as the bank-details migration.
     *
     * Values that already decrypt are left alone, so the migration is safe
     * to run on a database where some rows are encrypted already.
     *
     * The activity log recorded old/new account numbers and IBANs in
     * plaintext on every account edit; those values are replaced by
     * "[redacted]". The model no longer records them at all.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE accounts MODIFY account_number TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY iban TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

        $this->encryptExistingValues();
        $this->redactActivityLog();
    }

    /**
     * Decrypts both columns back to plaintext VARCHAR(100). The redacted
     * activity log entries cannot be restored.
     */
    public function down(): void
    {
        DB::table('accounts')->select(['id', ...self::EncryptedColumns])->orderBy('id')->each(function (object $account): void {
            $updates = [];

            foreach (self::EncryptedColumns as $column) {
                $value = $account->{$column};

                if ($value !== null && $this->isEncrypted($value)) {
                    $updates[$column] = Crypt::decryptString($value);
                }
            }

            if ($updates !== []) {
                DB::table('accounts')->where('id', $account->id)->update($updates);
            }
        });

        DB::statement('ALTER TABLE accounts MODIFY account_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE accounts MODIFY iban VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
    }

    /**
     * Encrypt every account number and IBAN still stored in plaintext.
     */
    public function encryptExistingValues(): void
    {
        DB::table('accounts')->select(['id', ...self::EncryptedColumns])->orderBy('id')->each(function (object $account): void {
            $updates = [];

            foreach (self::EncryptedColumns as $column) {
                $value = $account->{$column};

                if ($value !== null && ! $this->isEncrypted($value)) {
                    $updates[$column] = Crypt::encryptString($value);
                }
            }

            if ($updates !== []) {
                DB::table('accounts')->where('id', $account->id)->update($updates);
            }
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * Replace every recorded account number / IBAN, old and new, in the
     * account activities' `changes` and `properties`.
     */
    public function redactActivityLog(): void
    {
        DB::table('activities')
            ->select(['id', 'changes', 'properties'])
            ->where('subject_type', 'account')
            ->orderBy('id')
            ->each(function (object $activity): void {
                $updates = [];

                foreach (['changes', 'properties'] as $column) {
                    $recorded = json_decode((string) $activity->{$column}, true);

                    if (! is_array($recorded)) {
                        continue;
                    }

                    $redacted = $this->redact($recorded);

                    if ($redacted !== $recorded) {
                        $updates[$column] = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    }
                }

                if ($updates !== []) {
                    DB::table('activities')->where('id', $activity->id)->update($updates);
                }
            });
    }

    /**
     * @param  array<array-key, mixed>  $recorded
     * @return array<array-key, mixed>
     */
    private function redact(array $recorded): array
    {
        foreach (self::EncryptedColumns as $column) {
            if (! array_key_exists($column, $recorded)) {
                continue;
            }

            $recorded[$column] = is_array($recorded[$column])
                ? array_map(fn (mixed $value): ?string => $value === null ? null : self::Redacted, $recorded[$column])
                : self::Redacted;
        }

        return $recorded;
    }
};
