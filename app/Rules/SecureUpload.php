<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\FileType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;
use ZipArchive;

/**
 * Checks what an upload really is, not what its name or client MIME claims.
 *
 * The extension and `mimes` rules trust the file name. This rule opens the
 * file: its leading bytes must be the format the extension promises, and
 * Office files must be genuine, macro-free packages.
 */
class SecureUpload implements ValidationRule
{
    private const MaxUnpackedBytes = 200 * 1024 * 1024;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || $value->getRealPath() === false) {
            return;
        }

        $type = FileType::tryFrom(strtolower($value->getClientOriginalExtension()));
        $path = $value->getRealPath();

        if ($type === null) {
            return;
        }

        $problem = match ($type) {
            FileType::Pdf => $this->pdfProblem($path),
            FileType::Png, FileType::Jpg => $this->imageProblem($path, $type),
            FileType::Docx, FileType::Xlsx => $this->officeProblem($path, $type),
        };

        if ($problem !== null) {
            $fail($problem)->translate();
        }
    }

    private function pdfProblem(string $path): ?string
    {
        $bytes = (string) file_get_contents($path);

        if (! str_contains(substr($bytes, 0, 1024), '%PDF-') || $this->detectedMime($path) !== 'application/pdf') {
            return 'validation.secure_upload.content_mismatch';
        }

        return null;
    }

    private function imageProblem(string $path, FileType $type): ?string
    {
        $expected = $type === FileType::Png ? 'image/png' : 'image/jpeg';
        $size = @getimagesize($path);

        if ($size === false || $size['mime'] !== $expected || $this->detectedMime($path) !== $expected) {
            return 'validation.secure_upload.content_mismatch';
        }

        return null;
    }

    private function officeProblem(string $path, FileType $type): ?string
    {
        if (! str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04")) {
            return 'validation.secure_upload.content_mismatch';
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return 'validation.secure_upload.content_mismatch';
        }

        try {
            $mainPart = $type === FileType::Docx ? 'word/document.xml' : 'xl/workbook.xml';

            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($mainPart) === false) {
                return 'validation.secure_upload.content_mismatch';
            }

            $unpacked = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $unpacked += $entry['size'] ?? 0;

                if (stripos((string) $entry['name'], 'vbaProject.bin') !== false) {
                    return 'validation.secure_upload.active_content';
                }
            }

            if ($unpacked > self::MaxUnpackedBytes) {
                return 'validation.secure_upload.content_mismatch';
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    private function detectedMime(string $path): string|false
    {
        return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
    }
}
