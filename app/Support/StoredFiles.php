<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FileType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mechanics every uploaded document shares: private storage, a trusted
 * type taken from the validated extension, a display name that is safe and
 * still opens, and disk cleanup that never strands a row.
 *
 * Authorization is not decided here. The action that calls this checks the
 * permission first.
 */
final class StoredFiles
{
    /**
     * Store an upload (when there is one) on the model, set its display name and
     * save it. A replaced document is removed from disk once the row points at
     * the new one.
     *
     * @param  array<string, mixed>  $attributes  every other column to write with the save
     * @param  array<string, mixed>  $uploadAttributes  columns written only when a file was uploaded
     */
    public static function save(
        Model $file,
        ?UploadedFile $upload,
        string $directory,
        string $typedName,
        array $attributes,
        array $uploadAttributes = [],
        string $label = 'file',
    ): void {
        $replaced = null;

        if ($upload !== null) {
            if ($file->exists && $file->original_name !== null && $file->path !== null) {
                $replaced = ['disk' => $file->disk, 'path' => $file->path];
            }

            $file->fill([
                'disk' => 'local',
                'path' => $upload->store($directory, 'local'),
                'type' => FileType::from(Str::lower($upload->getClientOriginalExtension())),
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
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
        ])->save();

        if ($replaced !== null) {
            self::forget($replaced['disk'], $replaced['path'], $label, ['id' => $file->id]);
        }
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
