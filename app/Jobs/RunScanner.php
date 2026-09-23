<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\Alerts\Scanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one alert scanner on the queue. Safe to retry: the ledger stops a
 * stage going twice to the same person.
 */
final class RunScanner implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  class-string<Scanner>  $scanner
     */
    public function __construct(public string $scanner) {}

    public function handle(): void
    {
        app($this->scanner)->scan(CarbonImmutable::now());
    }
}
