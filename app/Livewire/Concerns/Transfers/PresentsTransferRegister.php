<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Transfers;

use Illuminate\Support\Number;

/**
 * How the transfers register prints a figure on its Overview view: whole
 * amounts, exact amounts, percentages that never round a real loss to zero,
 * and exchange rates.
 *
 * Used by transfers/index and its _overview partial only. The page provides
 * nothing. The filter pills, which capture the page's own `$chosen` and
 * `$counts`, stay as closures in the page's top PHP block.
 */
trait PresentsTransferRegister
{
    protected function amount(float $value): string
    {
        return Number::format($value, maxPrecision: 0);
    }

    protected function exact(float $value): string
    {
        return number_format($value, 2);
    }

    /**
     * A percentage that never rounds a real loss down to zero. A 30 USD fee on
     * a 100,000 USD tranche is 0.03% — the common case in this domain — and
     * printing it as a red "−0%" reads as a broken page, not as a small loss.
     */
    protected function percent(float $part, float $whole): string
    {
        if ($whole <= 0) {
            return '0';
        }

        $value = $part / $whole * 100;
        $formatted = Number::format($value, maxPrecision: 1);

        return $value > 0 && (float) str_replace(',', '', $formatted) === 0.0
            ? __('transfers.under_a_tenth_of_a_percent')
            : $formatted;
    }

    /**
     * Whole units, except for an amount that would otherwise round to zero:
     * a shortfall of 0.40 is still a shortfall and must not print as "0".
     */
    protected function amountAtLeast(float $value): string
    {
        return $value > 0 && $value < 1
            ? number_format($value, 2)
            : Number::format($value, maxPrecision: 0);
    }

    /**
     * An exchange rate with its trailing zeros dropped. Trimming the raw
     * decimal:6 string would turn a rate of 0 into an empty cell.
     */
    protected function rate(string $value): string
    {
        $trimmed = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
