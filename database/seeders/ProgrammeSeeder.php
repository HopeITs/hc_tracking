<?php

namespace Database\Seeders;

use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Programs, filled by syncing from HR.
 *
 * Nothing is invented here: every HR program gets its tracking row under the
 * same id, and managers come from HR's Program Manager positions. Strategies
 * are left unassigned.
 */
class ProgrammeSeeder extends Seeder
{
    /**
     * Mirror the HR programs.
     */
    public function run(): void
    {
        Program::syncFromHr();
    }
}
