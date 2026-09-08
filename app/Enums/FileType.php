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
}
