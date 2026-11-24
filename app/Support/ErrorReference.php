<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * The short reference id of one failure (M-05): written to the log with the
 * exception and shown to the person, so what they quote finds the log line.
 *
 * One id per exception object, so the log line and the response agree.
 */
final class ErrorReference
{
    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $issued = null;

    public static function for(Throwable $exception): string
    {
        self::$issued ??= new WeakMap;

        return self::$issued[$exception] ??= Str::upper(Str::random(8));
    }
}
