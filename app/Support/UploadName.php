<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Makes a user-supplied file name safe to store and to send in a
 * Content-Disposition header.
 *
 * Control characters (CR/LF above all, which would split a header), path
 * separators and quotes are removed. Letters from any script, Arabic
 * included, are kept.
 */
final class UploadName
{
    private const MaxLength = 150;

    public static function clean(?string $name, string $fallback = 'file'): string
    {
        $name = (string) preg_replace('/[\p{C}\\\\\/"<>|:*?%]+/u', '', (string) $name);
        $name = trim($name, " .\t");

        if ($name === '') {
            return $fallback;
        }

        if (mb_strlen($name) <= self::MaxLength) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, self::MaxLength - mb_strlen($extension) - 1);

        return $extension === '' ? $stem : $stem.'.'.$extension;
    }
}
