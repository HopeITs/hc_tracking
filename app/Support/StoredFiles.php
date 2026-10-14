<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FileType;
use App\Exceptions\FileStorageNotConfigured;
use App\Exceptions\UnreadableStoredFile;
use App\Models\Media;
use App\Models\ReportFile;
use App\Models\TaskAttachment;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The mechanics every uploaded document shares: private storage, a trusted
 * type taken from the validated extension, a display name that is safe and
 * still opens, and disk cleanup that never strands a row.
 *
 * Every upload is encrypted at rest (contracts §16): the bytes are written as
 * an AES-256-GCM payload under a random path, the row records the key id, and
 * stream() decrypts only after the caller has authorized the request. A row
 * without a key id is a legacy plaintext file and is streamed as it is.
 *
 * Authorization is not decided here. The action that calls this checks the
 * permission first.
 */
final class StoredFiles
{
    /**
     * Every table that holds uploaded files. A task that adds a file table
     * registers it here so the commands that move or extract files see it.
     *
     * @var list<string>
     */
    public const FileTables = ['media', 'report_files', 'task_attachments'];

    /**
     * Store an upload (when there is one) on the model, set its display name and
     * save it. The upload is validated by the caller, then encrypted here; with
     * no usable file key nothing is written. A replaced document is removed
     * from disk once the row points at the new one.
     *
     *
     * @param  array<string, mixed>  $attributes  every other column to write with the save
     * @param  array<string, mixed>  $uploadAttributes  columns written only when a file was uploaded
     *
     * @throws FileStorageNotConfigured when the file key is missing or malformed
     */
    public static function save(
        Media|ReportFile|TaskAttachment $file,
        ?UploadedFile $upload,
        string $directory,
        string $typedName,
        array $attributes,
        array $uploadAttributes = [],
        string $label = 'file',
    ): void {
        $replaced = null;
        $storedPath = null;

        if ($upload !== null) {
            // Resolve the key before touching the disk: no key, no write.
            FileKeyring::current();

            if ($file->exists && $file->original_name !== null) {
                $replaced = ['disk' => $file->disk, 'path' => $file->path];
            }

            $bytes = $upload->get();

            if (! is_string($bytes)) {
                throw new RuntimeException('The upload could not be read.');
            }

            $type = FileType::from(Str::lower($upload->getClientOriginalExtension()));
            $storedPath = $directory.'/'.Str::random(40).'.'.$type->value;

            $file->fill([
                'disk' => 'local',
                'path' => $storedPath,
                'type' => $type,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
                'encryption_key_id' => self::putEncrypted('local', $storedPath, $bytes),
                'uploaded_by' => auth()->id(),
                ...$uploadAttributes,
            ]);
        }

        $typedName = UploadName::clean($typedName, '');

        $file->fill([
            ...$attributes,
            'original_name' => $typedName !== ''
                ? self::nameWithExtension($typedName, $file->type)
                : UploadName::clean($upload?->getClientOriginalName()),
        ]);

        try {
            $file->save();
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                self::forget('local', $storedPath, $label);
            }

            throw $exception;
        }

        if ($replaced !== null) {
            self::forget($replaced['disk'], $replaced['path'], $label, ['id' => $file->id]);
        }

        // Livewire keeps the plaintext upload in its temporary folder; drop it
        // now that the encrypted copy exists. Best effort: files:prune sweeps
        // whatever is left after 24 hours.
        if ($upload instanceof TemporaryUploadedFile) {
            rescue(function () use ($upload): void {
                $upload->delete();
                FileUploadConfiguration::storage()->delete(FileUploadConfiguration::path($upload->getFilename().'.json', false));
            }, report: false);
        }
    }

    /**
     * Encrypt the contents with the current file key and write them to the
     * path. Returns the key id to record on the row.
     *
     * @throws FileStorageNotConfigured when the file key is missing or malformed
     * @throws RuntimeException when the disk refuses the write
     */
    public static function putEncrypted(string $disk, string $path, string $contents): string
    {
        $current = FileKeyring::current();

        if (Storage::disk($disk)->put($path, $current['encrypter']->encrypt($contents, false)) !== true) {
            throw new RuntimeException('A stored file could not be written.');
        }

        return $current['id'];
    }

    /**
     * The original bytes of a stored file. A NULL key id is a legacy
     * plaintext file and is returned as it is.
     *
     * @throws UnreadableStoredFile when the file is missing, corrupt or its key is unknown
     */
    public static function contents(string $disk, string $path, ?string $keyId): string
    {
        $stored = Storage::disk($disk)->get($path);

        if (! is_string($stored)) {
            throw UnreadableStoredFile::forKey($keyId);
        }

        if ($keyId === null) {
            return $stored;
        }

        try {
            return FileKeyring::for($keyId)->decrypt($stored, false);
        } catch (DecryptException) {
            throw UnreadableStoredFile::forKey($keyId);
        }
    }

    /**
     * Stream a stored file to the browser. The caller has already authorized
     * the request and checked the file belongs to its parent.
     *
     * The file is decrypted before the response is built, so a wrong key or a
     * corrupt file becomes the friendly error page instead of a half-sent
     * body. The Content-Type is the validated type's, never the client's or
     * the detected one.
     *
     * @param  array<string, string>  $headers
     *
     * @throws UnreadableStoredFile
     */
    public static function stream(
        string $disk,
        string $path,
        ?string $keyId,
        string $downloadName,
        FileType $type,
        array $headers = [],
        string $disposition = 'attachment',
    ): StreamedResponse {
        $contents = self::contents($disk, $path, $keyId);

        return response()->streamDownload(
            function () use ($contents): void {
                echo $contents;
            },
            $downloadName,
            ['Content-Type' => $type->mimeType(), 'Content-Length' => (string) strlen($contents), ...$headers],
            $disposition,
        );
    }

    /**
     * A display name guaranteed to carry the file's real extension, since it
     * becomes the downloaded filename.
     */
    public static function nameWithExtension(string $displayName, FileType $type): string
    {
        $displayName = UploadName::clean($displayName);

        if (Str::lower(pathinfo($displayName, PATHINFO_EXTENSION)) === $type->value) {
            return $displayName;
        }

        return $displayName.'.'.$type->value;
    }

    /**
     * Remove a stored file, logging rather than surfacing a storage failure so
     * a disk hiccup never leaves a record stuck in the UI.
     *
     * @param  array<string, mixed>  $context
     */
    public static function forget(string $disk, string $path, string $label, array $context = []): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            Log::warning("Failed to delete {$label} from disk.", [...$context, 'path' => $path, 'error' => $exception->getMessage()]);
        }
    }
}
