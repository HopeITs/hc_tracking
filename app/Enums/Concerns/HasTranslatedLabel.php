<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * A translated, human-readable label for a backed enum case.
 *
 * The stored value never changes with the language; only what is shown does.
 * Labels live in lang/{locale}/enums.php under the snake_case enum name, keyed
 * by the value with spaces and dashes turned into underscores, e.g.
 * enums.task_status.in_progress.
 */
trait HasTranslatedLabel
{
    /**
     * The label for this case in the current language.
     */
    public function label(): string
    {
        return __('enums.'.Str::snake(class_basename(static::class)).'.'.self::labelKey($this->value));
    }

    /**
     * The lang key segment for a stored value.
     */
    public static function labelKey(string $value): string
    {
        return Str::lower(preg_replace('/[^A-Za-z0-9]+/', '_', $value));
    }
}
