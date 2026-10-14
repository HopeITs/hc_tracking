<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\UnreadableStoredFile;
use App\Support\StoredFiles;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Emergency extraction of one stored upload to an explicit destination
 * (contracts §16), for the day the application cannot open a file but a person
 * with the key still must.
 *
 * The destination must be a new file outside the upload folders and public/,
 * so the plaintext copy can never be served by the application or swept in as
 * an upload. Only the destination is printed, never a path inside the upload
 * folders, a key or any contents; the copy is the operator's to delete.
 */
class DecryptStoredFile extends Command implements Isolatable
{
    /**
     * @var string
     */
    protected $signature = 'files:decrypt
        {table : The table holding the file row (media, report_files or task_attachments)}
        {--id= : The id of the row to extract}
        {--to= : A new file path outside the upload folders and public/}';

    /**
     * @var string
     */
    protected $description = 'Decrypt one stored upload into a file outside the upload folders (emergency use)';

    public function handle(): int
    {
        $table = (string) $this->argument('table');

        if (! in_array($table, StoredFiles::FileTables, true)) {
            $this->components->error('Unknown table. Use one of: '.implode(', ', StoredFiles::FileTables).'.');

            return self::FAILURE;
        }

        $id = filter_var($this->option('id'), FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            $this->components->error('Pass the row id with --id.');

            return self::FAILURE;
        }

        $destination = $this->destination((string) $this->option('to'));

        if ($destination === null) {
            return self::FAILURE;
        }

        $row = DB::table($table)->where('id', $id)->first(['id', 'disk', 'path', 'encryption_key_id']);

        if ($row === null) {
            $this->components->error("No {$table} row with id {$id}.");

            return self::FAILURE;
        }

        try {
            $contents = StoredFiles::contents($row->disk, $row->path, $row->encryption_key_id);
        } catch (UnreadableStoredFile) {
            Log::error('Stored file could not be decrypted by files:decrypt.', ['table' => $table, 'id' => $row->id, 'key_id' => $row->encryption_key_id]);
            $this->components->error('The file could not be opened.');

            return self::FAILURE;
        }

        // "x" refuses an existing file, closing the gap between the check above and the write.
        $handle = @fopen($destination, 'xb');

        if ($handle === false) {
            $this->components->error('The destination could not be created.');

            return self::FAILURE;
        }

        $written = fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents)) {
            @unlink($destination);
            $this->components->error('The destination could not be written.');

            return self::FAILURE;
        }

        $this->components->info("Written to {$destination}");
        $this->components->warn('This copy is not encrypted. Delete it as soon as you are done.');

        return self::SUCCESS;
    }

    /**
     * The absolute destination, or NULL (after saying why) when it is not a new
     * file in an existing folder outside the places the application reads from.
     */
    private function destination(string $to): ?string
    {
        $name = basename($to);
        $folder = $to === '' ? false : realpath(dirname($to));

        if ($folder === false || ! is_dir($folder) || in_array($name, ['', '.', '..'], true)) {
            $this->components->error('Pass --to as a new file inside an existing folder.');

            return null;
        }

        $destination = $folder.DIRECTORY_SEPARATOR.$name;

        if (file_exists($destination) || is_link($destination)) {
            $this->components->error('The destination already exists; it is never overwritten.');

            return null;
        }

        foreach ($this->forbiddenRoots() as $root) {
            if (str_starts_with($this->comparable($destination).'/', $this->comparable($root).'/')) {
                $this->components->error('The destination must be outside the upload folders and public/.');

                return null;
            }
        }

        return $destination;
    }

    /**
     * Folders a plaintext copy must never land in: wherever uploads live and
     * everything the web server could serve.
     *
     * @return list<string>
     */
    private function forbiddenRoots(): array
    {
        $roots = [
            Storage::disk('local')->path(''),
            (string) config('filesystems.disks.local.root'),
            storage_path('app'),
            public_path(),
        ];

        return array_values(array_filter(array_map(
            fn (string $root): string|false => $root === '' ? false : (realpath($root) ?: $root),
            $roots,
        )));
    }

    /**
     * A path in a form that compares equal however it was spelled: forward
     * slashes, no trailing slash, and lower case where the filesystem ignores case.
     */
    private function comparable(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
