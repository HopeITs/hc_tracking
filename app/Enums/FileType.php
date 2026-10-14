<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The file formats that may be uploaded.
 *
 * Shared by `report_files.type` and `task_attachments.type`, both VARCHAR(50).
 * The value is the file's extension, not a display category: the stored `path`
 * is built from it, so a row's type and the extension on disk always agree.
 * The list therefore doubles as the set of formats an upload may use.
 *
 * Values are lower case and casing is part of the value — 'PDF' is not 'pdf'.
 */
enum FileType: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Xlsx = 'xlsx';
    case Jpg = 'jpg';
    case Png = 'png';

    /**
     * All file type values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The Content-Type a stored file is served with.
     *
     * Taken from the validated type, never from the stored bytes or the
     * client's declared MIME, so a download or preview cannot be coaxed into
     * another type.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Jpg => 'image/jpeg',
            self::Png => 'image/png',
        };
    }
}
