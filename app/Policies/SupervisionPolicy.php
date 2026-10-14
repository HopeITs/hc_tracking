<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Supervision exceptions are maintained by holders of supervisions.manage
 * (contracts §8: General Manager). Supervision itself grants nothing.
 */
class SupervisionPolicy
{
    /**
     * Determine whether the user can add or remove supervision exceptions.
     */
    public function manage(User $user): bool
    {
        return $user->can('supervisions.manage');
    }
}
