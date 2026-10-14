<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\FileStorageNotConfigured;
use App\Exceptions\UnreadableStoredFile;
use App\Models\Account;
use App\Models\Activity;
use App\Support\FileKeyring;
use App\Support\StoredFiles;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use RuntimeException;
use stdClass;
use Throwable;

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
 *
 * With --files it re-keys uploaded documents instead (contracts §16): every
 * file whose key id is not the current file key id is decrypted with its own
 * key and written back under the current one, leaving database columns alone.
 */
class ReencryptSecrets extends Command implements Isolatable
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'security:reencrypt
        {--files : Re-key the uploaded files with the current file key instead of the database columns}
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

        if ($this->option('files')) {
            return $this->reencryptFiles($dryRun);
        }

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
     * Re-key every stored file that is not on the current file key.
     *
     * Each file is handled on its own, so one unreadable file is reported by
     * id and the rest still move. A missing or invalid current key stops the
     * run before anything is read or written.
     */
    private function reencryptFiles(bool $dryRun): int
    {
        try {
            $currentKeyId = FileKeyring::current()['id'];
        } catch (FileStorageNotConfigured) {
            $this->components->error('File encryption is not configured. No file was changed.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirmToProceed('Re-keying rewrites stored files. Run with --dry-run first.')) {
            return self::FAILURE;
        }

        $failedAny = false;

        Activity::withoutRecording(function () use ($currentKeyId, $dryRun, &$failedAny): void {
            foreach (StoredFiles::FileTables as $table) {
                $counts = ['done' => 0, 'skipped' => 0, 'failed' => []];

                DB::table($table)
                    ->select(['id', 'disk', 'path', 'encryption_key_id'])
                    ->whereNotNull('encryption_key_id')
                    ->where('encryption_key_id', '!=', $currentKeyId)
                    ->lazyById()
                    ->each(function (stdClass $row) use ($table, $dryRun, &$counts): void {
                        $outcome = $this->rekeyFile($table, $row, $dryRun);

                        if ($outcome === 'failed') {
                            $counts['failed'][] = (int) $row->id;
                        } else {
                            $counts[$outcome]++;
                        }
                    });

                $this->components->info("{$table}: {$counts['done']} re-keyed".($dryRun ? ' (dry run)' : '').'.');

                if ($counts['skipped'] > 0) {
                    $this->components->warn("{$table}: {$counts['skipped']} changed while running and were skipped; run the command again.");
                }

                if ($counts['failed'] !== []) {
                    $failedAny = true;

                    $this->components->error(sprintf(
                        '%s: %d could not be re-keyed (ids: %s).',
                        $table,
                        count($counts['failed']),
                        implode(', ', $counts['failed']),
                    ));
                }
            }
        });

        return $failedAny ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Move one file to the current key: write the re-encrypted copy beside the
     * original, check it decrypts to the same bytes, switch the row to it in a
     * locked transaction, and only then delete the original.
     *
     * @return 'done'|'skipped'|'failed'
     */
    private function rekeyFile(string $table, stdClass $row, bool $dryRun): string
    {
        if ($dryRun) {
            try {
                StoredFiles::contents($row->disk, $row->path, $row->encryption_key_id);

                return 'done';
            } catch (UnreadableStoredFile) {
                return $this->failedFile($table, $row);
            }
        }

        $newPath = null;

        try {
            $outcome = DB::transaction(function () use ($table, $row, &$newPath): string {
                $locked = DB::table($table)->where('id', $row->id)->lockForUpdate()->first(['id', 'path', 'encryption_key_id']);

                if ($locked === null || $locked->path !== $row->path || $locked->encryption_key_id !== $row->encryption_key_id) {
                    return 'skipped';
                }

                $plaintext = StoredFiles::contents($row->disk, $row->path, $row->encryption_key_id);
                $newPath = dirname($row->path).'/'.Str::random(40).'.'.pathinfo($row->path, PATHINFO_EXTENSION);
                $keyId = StoredFiles::putEncrypted($row->disk, $newPath, $plaintext);

                if (! hash_equals(hash('sha256', $plaintext), hash('sha256', StoredFiles::contents($row->disk, $newPath, $keyId)))) {
                    throw new RuntimeException('The re-keyed copy does not match the original.');
                }

                DB::table($table)->where('id', $row->id)->update(['path' => $newPath, 'encryption_key_id' => $keyId]);

                return 'done';
            });
        } catch (Throwable) {
            if ($newPath !== null) {
                StoredFiles::forget($row->disk, $newPath, 'stored file', ['table' => $table, 'id' => $row->id]);
            }

            return $this->failedFile($table, $row);
        }

        if ($outcome === 'done') {
            StoredFiles::forget($row->disk, $row->path, 'stored file', ['table' => $table, 'id' => $row->id]);
        }

        return $outcome;
    }

    /**
     * Log a file that could not be re-keyed (table, row id and key id only)
     * and tell the caller to count it as failed.
     *
     * @return 'failed'
     */
    private function failedFile(string $table, stdClass $row): string
    {
        Log::error('Stored file could not be re-keyed.', ['table' => $table, 'id' => $row->id, 'key_id' => $row->encryption_key_id]);

        return 'failed';
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
