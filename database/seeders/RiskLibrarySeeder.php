<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\DefaultRiskLibrary;
use Illuminate\Database\Seeder;

/**
 * The starting risk library (W-09, D-61): MEAL's Theory of Change risk
 * matrix. DefaultRiskLibrary::ensure() is the one idempotent rule, shared
 * with the data migrations, so a re-seed inserts only what is missing.
 */
class RiskLibrarySeeder extends Seeder
{
    public function run(): void
    {
        DefaultRiskLibrary::ensure();
    }
}
