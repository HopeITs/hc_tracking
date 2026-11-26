<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ProposalLocationBackfill;
use Illuminate\Database\Seeder;

/**
 * Location rows for the seeded proposals (W-34a): one per existing link
 * through the same backfill the command runs. Runs after the proposal
 * seeders; idempotent.
 */
class ProposalLocationSeeder extends Seeder
{
    public function run(): void
    {
        app(ProposalLocationBackfill::class)->run(apply: true);
    }
}
