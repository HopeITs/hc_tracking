<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActivityPlanStatus;
use App\Enums\PlanUpdateStatus;
use App\Models\ActivityPlan;
use App\Support\PlanCalendar;
use App\Support\PlanExecution;
use App\Support\PlanNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The annual plan's two scheduled notices (Rami, 2026-11-11): in the last
 * week of a month, remind each centre's project manager that the update is
 * due; once its due date (3rd working day, Fri/Sat off) has passed without
 * a sent update, tell the project manager, the MEAL manager and the program
 * manager it is late. Each notice goes out once per centre and month.
 */
class RemindAnnualPlanUpdates extends Command
{
    protected $signature = 'annual-plans:remind {--date= : Pretend today is this date (Y-m-d)}';

    protected $description = 'Remind project managers of due monthly plan updates and flag late ones';

    public function handle(): int
    {
        $today = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::today();
        $sent = PlanNotifier::notifyAllReviewers();

        foreach (ActivityPlan::query()->where('status', ActivityPlanStatus::Approved->value)->get() as $plan) {
            $execution = PlanExecution::for($plan);

            foreach ($execution->projects() as $centre) {
                foreach ($execution->calendar->months() as $month) {
                    $update = $execution->update($centre, $month);

                    if ($update !== null && ! in_array($update->status, [PlanUpdateStatus::Draft, PlanUpdateStatus::Returned], true)) {
                        continue;
                    }

                    $due = PlanCalendar::dueDateOf($month);
                    $key = $plan->id.':'.$centre->id.':'.$month->format('Y-m');
                    $params = ['month' => $month->translatedFormat('F Y'), 'date' => $due->translatedFormat(__('formats.date'))];

                    if ($today->gte($month->endOfMonth()->subDays(6)->startOfDay()) && $today->lte($due)) {
                        PlanNotifier::once('remind:'.$key, 60, $plan, $centre, $month->format('Y-m'), 'annual_plan.update_due', ['project_manager'], $params);
                        $sent++;
                    }

                    if ($today->gt($due) && $update?->status !== PlanUpdateStatus::Returned) {
                        PlanNotifier::once('late:'.$key, 365, $plan, $centre, $month->format('Y-m'), 'annual_plan.update_late', ['project_manager', 'meal', 'program_manager'], $params);
                        $sent++;
                    }
                }
            }
        }

        $this->info("Checked plan updates; {$sent} notices considered.");

        return self::SUCCESS;
    }
}
