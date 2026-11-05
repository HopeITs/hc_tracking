<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RiskLibrary;
use App\Models\User;
use App\Support\Slots;

/**
 * The risk library is maintained by holders of `risk-library.manage` (MEAL,
 * General Manager) who also hold the MEAL Manager slot (contracts.md §8,
 * D-61). Deactivating, reactivating and moving an entry are edits.
 */
class RiskLibraryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, RiskLibrary $entry): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, RiskLibrary $entry): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, RiskLibrary $entry): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        if (! $user->can('risk-library.manage') || $user->employee_id === null) {
            return false;
        }

        return $user->employee_id === Slots::holderOf('meal_manager');
    }

    public function restore(User $user, RiskLibrary $entry): bool
    {
        return false;
    }

    public function forceDelete(User $user, RiskLibrary $entry): bool
    {
        return false;
    }
}
