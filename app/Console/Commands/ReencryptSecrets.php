<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Activity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

/**
 * Re-encrypts every value encrypted at rest with the current APP_KEY, so a
 * rotated-out key can be removed from APP_PREVIOUS_KEYS afterwards.
 *
 * Decryption accepts the current key and every previous key; encryption always
 * uses the current key. The plaintext is re-encrypted byte for byte: values are
 * decrypted without unserializing and encrypted without serializing, so
 * Fortify's serialized payloads and the model's encryptString payloads keep
 * their own format. Rows are read and written through the query builder
 * (updated_at untouched) under Activity::withoutRecording(), one transaction
 * per table. Only counts and row ids are printed, never a value.
 */
class ReencryptSecrets extends Command
{
    /**
     * @var string
     */
    protected $signature = 'security:reencrypt
        {--dry-run : Decrypt and count every value without writing anything}';

    /**
     * @var string
     */
    protected $description = 'Re-encrypt every encrypted column with the current APP_KEY after a key rotation';

    /**
     * Re-encrypt each table's encrypted columns and report the counts.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<string, array{encrypter: Encrypter, columns: list<string>}> $tables */
        $tables = [
            'users' => [
                'encrypter' => Fortify::currentEncrypter(),
                'columns' => ['two_factor_secret', 'two_factor_recovery_codes'],
            ],
            'accounts' => [
                'encrypter' => Account::currentEncrypter(),
                'columns' => ['account_number', 'iban'],
            ],
        ];

        $failedAny = false;

        Activity::withoutRecording(function () use ($tables, $dryRun, &$failedAny): void {
            foreach ($tables as $table => $definition) {
                $reencrypt = fn (): array => $this->reencryptTable($table, $definition['columns'], $definition['encrypter'], $dryRun);

                $report = $dryRun ? $reencrypt() : DB::transaction($reencrypt);

                foreach ($report as $column => $counts) {
                    $verb = $dryRun ? 'decrypted (dry run)' : 're-encrypted';

                    $this->components->info("{$table}.{$column}: {$counts['done']} {$verb}.");

                    if ($counts['failed'] !== []) {
                        $failedAny = true;

                        $this->components->error(sprintf(
                            '%s.%s: %d failed to decrypt with any known key (ids: %s).',
                            $table,
                            $column,
                            count($counts['failed']),
                            implode(', ', $counts['failed']),
                        ));
                    }
                }
            }
        });

        return $failedAny ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Re-encrypt the non-null values of the given columns, row by row.
     *
     * @param  list<string>  $columns
     * @return array<string, array{done: int, failed: list<int>}>
     */
    private function reencryptTable(string $table, array $columns, Encrypter $encrypter, bool $dryRun): array
    {
        $done = array_fill_keys($columns, 0);
        $failed = array_fill_keys($columns, []);

        DB::table($table)
            ->select(['id', ...$columns])
            ->where(function ($query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->lazyById()
            ->each(function (object $row) use ($table, $columns, $encrypter, $dryRun, &$done, &$failed): void {
                $updates = [];

                foreach ($columns as $column) {
                    $payload = $row->{$column};

                    if ($payload === null) {
                        continue;
                    }

                    try {
                        $plaintext = $encrypter->decrypt($payload, false);
                    } catch (DecryptException) {
                        $failed[$column][] = (int) $row->id;

                        continue;
                    }

                    $updates[$column] = $encrypter->encrypt($plaintext, false);
                    $done[$column]++;
                }

                if (! $dryRun && $updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            });

        $report = [];

        foreach ($columns as $column) {
            $report[$column] = ['done' => $done[$column], 'failed' => $failed[$column]];
        }

        return $report;
    }
}
