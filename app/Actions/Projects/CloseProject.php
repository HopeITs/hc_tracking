<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use LogicException;

/**
 * Close an active project.
 *
 * Stub with the frozen contracts.md §6 signature; T05 implements it.
 */
final class CloseProject
{
    public function handle(Project $project): void
    {
        throw new LogicException('Implemented in T05');
    }
}
