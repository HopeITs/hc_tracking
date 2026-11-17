<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\Alerts\PlanMilestoneScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ScanPlanMilestones extends Command
{
    protected $signature = 'annual-plans:scan-milestones';

    protected $description = 'Send milestone reminders and missed alerts';

    public function handle(PlanMilestoneScanner $scanner): int
    {
        $scanner->scan(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
