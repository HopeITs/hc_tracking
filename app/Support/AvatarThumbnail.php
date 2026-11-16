<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * A small square copy of an HR photo, so a picker listing hundreds of people
 * downloads a few kilobytes per face instead of each full-size original.
 *
 * The copy is made once per photo version with GD and kept on the private
 * `local` disk (never a public URL: HR treats staff photos as sensitive), so
 * EmployeeAvatarController still hands it only to signed-in users.
 */
final class AvatarThumbnail
{
    /** Edge of the square copy in pixels: sharp at the largest avatar the interface draws (40px) on 4x screens. */
    public const int Size = 160;

    /**
     * The absolute path of the thumbnail for an HR photo, made on first use;
     * null when the file cannot be read as an image (the caller then serves
     * the original).
     */
    public static function pathFor(string $sourcePath, int $version): ?string
    {
        $local = Storage::disk('local');
        $thumbnailPath = 'avatar-thumbs/'.sha1($sourcePath.'|'.$version.'|'.self::Size).'.jpg';

        if ($local->exists($thumbnailPath)) {
            return $local->path($thumbnailPath);
        }

        $bytes = Storage::disk('hr')->get($sourcePath);
        $source = is_string($bytes) && $bytes !== '' ? @imagecreatefromstring($bytes) : false;

        if ($source === false) {
            return null;
        }

        $edge = min(imagesx($source), imagesy($source));
        $thumbnail = imagecreatetruecolor(self::Size, self::Size);
        imagefill($thumbnail, 0, 0, (int) imagecolorallocate($thumbnail, 255, 255, 255));
        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            intdiv(imagesx($source) - $edge, 2),
            intdiv(imagesy($source) - $edge, 2),
            self::Size,
            self::Size,
            $edge,
            $edge,
        );

        ob_start();
        imagejpeg($thumbnail, null, 82);
        $jpeg = (string) ob_get_clean();

        if ($jpeg === '' || ! $local->put($thumbnailPath, $jpeg)) {
            return null;
        }

        return $local->path($thumbnailPath);
    }
}
