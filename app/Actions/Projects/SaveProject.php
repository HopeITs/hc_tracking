<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use LogicException;

/**
 * Create or update a project from validated form data.
 *
 * Stub with the frozen contracts.md §6 signature; T05 implements it.
 */
final class SaveProject
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Project $existing, array $data, User $actor): Project
    {
        throw new LogicException('Implemented in T05');
    }
}
