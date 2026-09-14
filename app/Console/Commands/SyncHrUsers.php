<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Creates a tracking user for every HR account that does not have one yet.
 *
 * New users start as Officer, or Admin for auth.hr_admin_emails. Existing users
 * and their roles are left alone. HR is only read.
 */
class SyncHrUsers extends Command
{
    /**
     * @var string
     */
    protected $signature = 'hr:sync-users';

    /**
     * @var string
     */
    protected $description = 'Create tracking users for every HR system account';

    /**
     * Create the missing users and report how many were added.
     */
    public function handle(): int
    {
        $created = User::syncFromHrAccounts();

        $this->components->info("Created {$created} user(s) from HR accounts.");

        return self::SUCCESS;
    }
}
