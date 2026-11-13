<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ProposalOwnerNotifier;
use Illuminate\Console\Command;

/**
 * Tells every project owner about each proposal of their project that waits
 * on them (once per proposal and status), so a step that began before the
 * owner had an account, or before this notice existed, still reaches them.
 */
class RemindProposalOwners extends Command
{
    protected $signature = 'proposals:remind-owners';

    protected $description = 'Notify project owners of proposals waiting on them';

    public function handle(): int
    {
        $this->info(ProposalOwnerNotifier::notifyAllWaiting().' notifications sent.');

        return self::SUCCESS;
    }
}
