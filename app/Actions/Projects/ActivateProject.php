<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use LogicException;

/**
 * Activate an approved, dated project and move its still-approved proposals to active.
 *
 * Stub with the frozen contracts.md §6 signature; T08 implements it.
 */
final class ActivateProject
{
    public function handle(Project $project, string $via): void
    {
        throw new LogicException('Implemented in T08');
    }
}
