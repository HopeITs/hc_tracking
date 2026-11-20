<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReportTemplatePlacement;
use App\Models\User;

class ReportTemplatePlacementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('report-templates.manage');
    }

    public function view(User $user, ReportTemplatePlacement $placement): bool
    {
        return $this->manage($user, $placement);
    }

    public function manage(User $user, ReportTemplatePlacement $placement): bool
    {
        return ReportTemplatePlacement::query()->manageableBy($user)->whereKey($placement->id)->exists();
    }
}
