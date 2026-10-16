<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\DefaultEvidenceTypes;
use Illuminate\Database\Seeder;

/**
 * The five starting evidence types (D-42). Query builder only, so it is
 * idempotent and records no activity (DatabaseSeeder also wraps it in
 * Activity::withoutRecording).
 */
class EvidenceTypeSeeder extends Seeder
{
    public function run(): void
    {
        DefaultEvidenceTypes::ensure();
    }
}
