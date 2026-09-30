<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Gate;

/**
 * Delete a tranche: a mistaken or duplicate entry.
 */
class DeleteTransfer
{
    public function handle(TransfersTracking $transfer): void
    {
        Gate::authorize('transfers.delete');

        $transfer->delete();
    }
}
