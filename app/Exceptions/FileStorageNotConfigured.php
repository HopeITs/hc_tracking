<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Thrown when an upload cannot be encrypted because the file key is missing
 * or malformed (contracts §16). Storage fails closed: nothing is written,
 * never a plaintext fallback.
 *
 * It is a validation error on `newFile`, the upload field every file form
 * shares, so the four upload dialogs show the message under the file input
 * without any change of their own.
 */
final class FileStorageNotConfigured extends ValidationException
{
    public static function make(): self
    {
        return self::withMessages(['newFile' => __('errors.file_storage_not_configured')]);
    }
}
