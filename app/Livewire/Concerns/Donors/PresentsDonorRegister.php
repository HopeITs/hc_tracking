<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Donors;

use Illuminate\Support\Number;

/**
 * How the donors page prints a figure on its Overview view: whole amounts,
 * abbreviated amounts and a donor's initials.
 *
 * Used by donors/index and its _overview partial only. The page provides
 * nothing. The filter pills, which capture the page's own `$chosen`, stay as
 * closures in the page's top PHP block.
 */
trait PresentsDonorRegister
{
    protected function amount(float $value): string
    {
        return Number::format($value, maxPrecision: 0);
    }

    protected function shortAmount(float $value): string
    {
        return Number::abbreviate($value, maxPrecision: 1);
    }

    protected function initials(string $name): string
    {
        return mb_strtoupper(collect(preg_split('/\s+/u', trim($name)) ?: [])->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->implode('')) ?: '·';
    }
}
