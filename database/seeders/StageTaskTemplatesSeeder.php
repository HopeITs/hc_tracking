<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\DefaultStageTaskTemplates;
use Illuminate\Database\Seeder;

/**
 * The four starting stage task templates (D-63). Query builder only, so it is
 * idempotent and records no activity; a data migration calls it so an
 * existing database gets the rows too.
 */
class StageTaskTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        DefaultStageTaskTemplates::ensure();
    }
}
