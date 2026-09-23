<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;

/**
 * A module heard about only by the roles that watch it.
 */
abstract class WatcherRecipients extends ModuleRecipients
{
    /**
     * @return list<string>
     */
    abstract protected function roles(): array;

    public function watcherRoles(Activity $activity): array
    {
        return $this->roles();
    }
}
