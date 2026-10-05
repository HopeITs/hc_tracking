<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * The interface languages hc-tracking is translated into.
 *
 * The value is the Laravel locale and the lang/ directory name.
 */
enum Locale: string
{
    case English = 'en';
    case Arabic = 'ar';

    /**
     * All locale values, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The text direction for the <html dir> attribute.
     */
    public function direction(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    /**
     * The Carbon locale for month and day names.
     *
     * Arabic uses the Syrian (Levantine) month names, e.g. أيلول rather than سبتمبر.
     */
    public function carbonLocale(): string
    {
        return $this === self::Arabic ? 'ar_SY' : 'en';
    }

    /**
     * The date written with this language's month and day names.
     *
     * Carbon's locale() switches the date it is called on, so a copy is
     * localized and the caller's date keeps its own locale.
     */
    public function formatDate(CarbonInterface $date, string $format): string
    {
        $localized = $date->copy();
        $localized->locale($this->carbonLocale());

        return $localized->translatedFormat($format);
    }

    /**
     * The other language, which the sidebar switch offers.
     */
    public function other(): self
    {
        return $this === self::Arabic ? self::English : self::Arabic;
    }

    /**
     * The language currently applied to the request.
     */
    public static function current(): self
    {
        return self::tryFrom(app()->getLocale()) ?? self::English;
    }
}
