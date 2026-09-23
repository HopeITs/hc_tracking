<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Keeps tracking users in step with the active HR employees.
 *
 * Creates a user for every active HR account that lacks one, and deletes
 * users whose HR employee is inactive (unless records still reference them).
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
    protected $description = 'Create tracking users for active HR employees and remove inactive ones';

    /**
     * Create the missing users, remove inactive ones, and report the counts.
     */
    public function handle(): int
    {
        $created = User::syncFromHrAccounts();
        $pruned = User::pruneInactiveHrAccounts();

        $this->components->info("Created {$created} user(s) from HR accounts.");
        $this->components->info("Deleted {$pruned['deleted']} user(s) of inactive HR employees.");

        foreach ($pruned['kept'] as $email) {
            $this->components->warn("Kept {$email}: records still reference this user.");
        }

        return self::SUCCESS;
    }
}
