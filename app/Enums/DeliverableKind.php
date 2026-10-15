<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What a media deliverable must hand over (contracts.md §12.2), stored as a list in `tasks.media_kinds`. A video is delivered as a link only.
 */
enum DeliverableKind: string
{
    use HasTranslatedLabel;

    case Video = 'video';
    case Photos = 'photos';
    case File = 'file';
    case Text = 'text';

    /**
     * All values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
