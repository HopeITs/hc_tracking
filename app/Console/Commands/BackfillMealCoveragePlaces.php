<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locations\MealCoveragePlaceBackfill;
use Illuminate\Console\Command;

final class BackfillMealCoveragePlaces extends Command
{
    protected $signature = 'meal-coverage:place-backfill {--dry-run : Preview place coverage without writing}';

    protected $description = 'Preview legacy project coverage mapped to report places';

    public function handle(MealCoveragePlaceBackfill $backfill): int
    {
        $result = $backfill->run();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
