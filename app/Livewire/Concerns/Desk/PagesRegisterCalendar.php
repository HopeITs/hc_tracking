<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * The month a register's Calendar view shows (x-desk.month-calendar writes
 * 'Y-m' here). Anything else in the URL falls back to the current month.
 */
trait PagesRegisterCalendar
{
    #[Url(as: 'cal', except: '')]
    public string $calendarMonth = '';

    public function calendarMonthStart(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->calendarMonth) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $this->calendarMonth)->startOfMonth();
        }

        return CarbonImmutable::today()->startOfMonth();
    }
}
