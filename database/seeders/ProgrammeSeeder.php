<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Support\ProgramImport;
use Illuminate\Database\Seeder;

/**
 * Programs, brought over from HR once.
 *
 * Nothing is invented here: every HR program gets its tracking row under the
 * same id, with HR's name, code, slug and active flag copied in. Tracking owns
 * them from then on (D-68, W-34b), so this runs once on a fresh database and
 * reports drift on any later run. Strategies stay unassigned; the managers are
 * picked up from HR's Program Manager positions, which HR still owns.
 */
class ProgrammeSeeder extends Seeder
{
    /**
     * Bring the HR programs over, then take their managers from HR.
     */
    public function run(): void
    {
        app(ProgramImport::class)->apply();

        Program::syncFromHr();
    }
}
