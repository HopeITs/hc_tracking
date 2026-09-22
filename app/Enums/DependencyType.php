<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a successor is tied to its predecessor, as in MS Project.
 *
 * The value is what lands in `schedule_dependencies.type` (VARCHAR(2)).
 */
enum DependencyType: string
{
    use HasTranslatedLabel;

    /** The successor starts once the predecessor finishes. */
    case FinishToStart = 'fs';

    /** The successor starts once the predecessor starts. */
    case StartToStart = 'ss';

    /** The successor finishes once the predecessor finishes. */
    case FinishToFinish = 'ff';

    /** The successor finishes once the predecessor starts. */
    case StartToFinish = 'sf';

    /**
     * All dependency type values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The short code MS Project prints in the predecessors column: FS, SS, FF, SF.
     */
    public function code(): string
    {
        return strtoupper($this->value);
    }
}
