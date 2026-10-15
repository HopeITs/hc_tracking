<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How a program combines one indicator's targets across donors on the same
 * center (contracts §12.4, D-40/D-46). `shared`: each donor counts the same
 * people, the center total is the highest target. `split`: each donor funds
 * a share, the center total is the sum. Display only; targets stay per donor.
 */
enum DonorAttribution: string
{
    use HasTranslatedLabel;

    case Shared = 'shared';
    case Split = 'split';

    /**
     * The center total of several donors' targets for one indicator.
     *
     * @param  list<float>  $targets
     */
    public function combine(array $targets): float
    {
        if ($targets === []) {
            return 0.0;
        }

        return match ($this) {
            self::Shared => max($targets),
            self::Split => array_sum($targets),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
