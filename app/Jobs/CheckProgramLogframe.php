<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\Alerts\LogframeGapAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks programs for a missing program framework and alerts MEAL (T14).
 * Dispatched after commit by SaveProject and the HR program sync. Safe to
 * retry: the ledger stops a program going twice to the same person.
 */
final class CheckProgramLogframe implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  list<int>  $programIds
     */
    public function __construct(public array $programIds) {}

    public function handle(LogframeGapAlert $alert): void
    {
        $alert->notify($this->programIds);
    }
}
