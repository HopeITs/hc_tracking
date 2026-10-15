<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ProposalStatus;
use App\Events\ProposalStatusChanged;
use App\Models\ActivitySchedule;
use App\Models\Proposal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Move a proposal's activity calendar once, when the donor approves it
 * (contracts §11.2, D-49): every scheduled month of its framework's
 * activities — the proposal calendar and every center override — moves by
 * the whole months between the planned and the actual start, keeping its
 * weeks.
 *
 * The shift is recorded in `activity_schedule_shifts` (unique per proposal)
 * in the same transaction, so a retried job, a second event or a later
 * re-approval after a status correction does nothing. Queued after commit;
 * a failure here never touches the approval.
 */
final class ShiftActivitySchedulesOnApproval implements ShouldQueue
{
    public int $tries = 3;

    public function handle(ProposalStatusChanged $event): void
    {
        if ($event->to !== ProposalStatus::Approved) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $proposal = Proposal::query()
                ->select(['id', 'planned_date_begin', 'actual_date_begin'])
                ->whereKey($event->proposalId)
                ->lockForUpdate()
                ->first();

            if ($proposal === null || $proposal->actual_date_begin === null) {
                return;
            }

            if (DB::table('activity_schedule_shifts')->where('proposal_id', $proposal->id)->exists()) {
                return;
            }

            $delta = (int) $proposal->planned_date_begin->startOfMonth()->diffInMonths($proposal->actual_date_begin->startOfMonth());

            $rows = ActivitySchedule::query()
                ->select(['activity_schedules.id', 'activity_schedules.result_id', 'activity_schedules.project_id', 'activity_schedules.month', 'activity_schedules.weeks'])
                ->join('results', 'results.id', '=', 'activity_schedules.result_id')
                ->where('results.proposal_id', $proposal->id)
                ->lockForUpdate()
                ->get();

            if ($delta !== 0 && $rows->isNotEmpty()) {
                // Delete, then re-insert: an in-place update would collide on the unique month index mid-way.
                ActivitySchedule::query()->whereKey($rows->modelKeys())->delete();

                foreach ($rows as $row) {
                    ActivitySchedule::query()->create([
                        'result_id' => $row->result_id,
                        'project_id' => $row->project_id,
                        'month' => $row->month->addMonthsNoOverflow($delta)->toDateString(),
                        'weeks' => $row->weeks,
                    ]);
                }
            }

            DB::table('activity_schedule_shifts')->insert([
                'proposal_id' => $proposal->id,
                'delta_months' => $delta,
                'shifted' => $delta === 0 ? 0 : $rows->count(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
