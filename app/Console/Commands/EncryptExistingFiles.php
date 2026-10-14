<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\FileStorageNotConfigured;
use App\Models\Activity;
use App\Support\FileKeyring;
use App\Support\StoredFiles;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Encrypts the uploads stored before encryption at rest existed (contracts
 * §16): every row with no key id whose plaintext file is on the local disk.
 *
 * Per file the original is never touched until a verified copy exists. The
 * encrypted copy is written to a new sibling path, read back and compared by
 * hash, and only then does one locked transaction switch the row's path and
 * key id; the old plaintext is deleted after commit (anything left behind is
 * swept by files:prune). A row changed or replaced while it was being copied
 * is skipped and picked up by the next run, which is also what makes the
 * command resumable: rows that already have a key id are never read again.
 *
 * Only counts and row ids are printed, never a path, key or any contents.
 */
class EncryptExistingFiles extends Command implements Isolatable
{
    use ConfirmableTrait;

    /**
     * @var string
     */
    protected $signature = 'files:encrypt-existing
        {--dry-run : Count what would be encrypted without writing anything}
        {--chunk=100 : How many rows to read from the database at a time}';

    /**
     * @var string
     */
    protected $description = 'Encrypt the uploaded files that were stored before encryption at rest (resumable)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        try {
            FileKeyring::current();
        } catch (FileStorageNotConfigured) {
            $this->components->error('File encryption is not configured. No file was changed.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirmToProceed('Encrypting existing files rewrites them on disk. Run with --dry-run first.')) {
            return self::FAILURE;
        }

        $failedAny = false;

        Activity::withoutRecording(function () use ($dryRun, $chunk, &$failedAny): void {
            foreach (StoredFiles::FileTables as $table) {
                $failedAny = $this->encryptTable($table, $dryRun, $chunk) || $failedAny;
            }
        });

        return $failedAny ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Process one table and print its counts. Returns whether any file failed.
     */
    private function encryptTable(string $table, bool $dryRun, int $chunk): bool
    {
        $alreadyEncrypted = DB::table($table)->whereNotNull('encryption_key_id')->count();
        $done = 0;
        $skipped = 0;
        $changed = 0;
        $missing = [];
        $failed = [];

        DB::table($table)
            ->select(['id', 'disk', 'path', 'original_name'])
            ->whereNull('encryption_key_id')
            ->lazyById($chunk)
            ->each(function (stdClass $row) use ($table, $dryRun, &$done, &$skipped, &$changed, &$missing, &$failed): void {
                // Reference-only rows never had a file; other disks are not ours to rewrite.
                if ($row->original_name === null || $row->disk !== 'local') {
                    $skipped++;
                    Log::info('Stored file skipped by files:encrypt-existing.', ['table' => $table, 'id' => $row->id]);

                    return;
                }

                if (! Storage::disk($row->disk)->exists($row->path)) {
                    $missing[] = (int) $row->id;
                    Log::warning('Stored file is missing on disk.', ['table' => $table, 'id' => $row->id]);

                    return;
                }

                if ($dryRun) {
                    $done++;

                    return;
                }

                match ($this->encryptFile($table, $row)) {
                    'done' => $done++,
                    'changed' => $changed++,
                    'failed' => $failed[] = (int) $row->id,
                };
            });

        $verb = $dryRun ? 'to encrypt' : 'encrypted';
        $this->components->info("{$table}: {$done} {$verb}, {$alreadyEncrypted} already encrypted, ".count($missing)." missing on disk, {$skipped} skipped");

        if ($missing !== []) {
            $this->components->warn("{$table}: missing on disk (ids: ".implode(', ', $missing).').');
        }

        if ($changed > 0) {
            $this->components->warn("{$table}: {$changed} changed while running and were left as they are; run the command again.");
        }

        if ($failed !== []) {
            $this->components->error(sprintf('%s: %d could not be encrypted (ids: %s).', $table, count($failed), implode(', ', $failed)));
        }

        return $failed !== [];
    }

    /**
     * Encrypt one file: copy, verify, switch the row under a lock, then drop
     * the plaintext.
     *
     * @return 'done'|'changed'|'failed'
     */
    private function encryptFile(string $table, stdClass $row): string
    {
        $newPath = null;

        try {
            $original = Storage::disk($row->disk)->get($row->path);

            if (! is_string($original)) {
                throw new RuntimeException('The original could not be read.');
            }

            $newPath = dirname($row->path).'/'.Str::random(40).'.'.pathinfo($row->path, PATHINFO_EXTENSION);
            $keyId = StoredFiles::putEncrypted($row->disk, $newPath, $original);

            if (! hash_equals(hash('sha256', $original), hash('sha256', StoredFiles::contents($row->disk, $newPath, $keyId)))) {
                throw new RuntimeException('The encrypted copy does not match the original.');
            }

            $outcome = DB::transaction(function () use ($table, $row, $newPath, $keyId): string {
                $locked = DB::table($table)->where('id', $row->id)->lockForUpdate()->first(['id', 'path', 'encryption_key_id']);

                if ($locked === null || $locked->path !== $row->path || $locked->encryption_key_id !== null) {
                    return 'changed';
                }

                DB::table($table)->where('id', $row->id)->update(['path' => $newPath, 'encryption_key_id' => $keyId]);

                return 'done';
            });
        } catch (Throwable) {
            if ($newPath !== null) {
                StoredFiles::forget($row->disk, $newPath, 'stored file', ['table' => $table, 'id' => $row->id]);
            }

            Log::error('Stored file could not be encrypted.', ['table' => $table, 'id' => $row->id, 'key_id' => null]);

            return 'failed';
        }

        StoredFiles::forget($row->disk, $outcome === 'done' ? $row->path : $newPath, 'stored file', ['table' => $table, 'id' => $row->id]);

        return $outcome;
    }
}
