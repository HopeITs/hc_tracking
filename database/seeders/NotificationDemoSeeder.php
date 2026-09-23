<?php

namespace Database\Seeders;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProposalStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;

/**
 * Demo notifications for looking at the inbox, bell, badges and activity log.
 *
 * Opt-in only, never part of DatabaseSeeder (seeding must stay quiet, see
 * .ai/rules/database-seeders.md). Run it on a seeded database with:
 *
 *     php artisan db:seed --class=NotificationDemoSeeder
 *
 * Every user holding a role (the only people notifications can reach) becomes
 * a recipient. Another user performs real actions through the models, so the
 * normal pipeline records the activity and fans it out: an assignment, status
 * changes, a risk that happened, an approved and a rejected proposal, a failed
 * tranche, a postponed milestone, a new project owner and a burst of edits
 * that groups into one row. The actions are spread over the last two weeks;
 * everything older than two days is marked read. It changes the records it
 * acts on, and running it again adds another round.
 */
class NotificationDemoSeeder extends Seeder
{
    /**
     * Seed the demo notifications.
     */
    public function run(): void
    {
        $recipients = User::query()->whereHas('roles')->whereNotNull('employee_id')->get();

        if ($recipients->isEmpty()) {
            $this->command?->warn('No user with a role and an HR employee: nobody can receive notifications.');

            return;
        }

        // Fan out now instead of waiting for a queue worker; don't try the socket.
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);

        try {
            $recipients->each(fn (User $recipient) => $this->actFor($recipient));
        } finally {
            Date::setTestNow();
            Auth::forgetUser();
        }

        $this->command?->info('Seeded '.UserNotification::query()->count().' notifications for '.$recipients->count().' user(s).');
    }

    /**
     * Another person's actions that concern the recipient.
     */
    private function actFor(User $recipient): void
    {
        $actor = User::query()->whereKeyNot($recipient->id)->whereHas('roles')->first()
            ?? User::query()->whereKeyNot($recipient->id)->first();

        if ($actor === null) {
            return;
        }

        Auth::setUser($actor);
        $employeeId = (int) $recipient->employee_id;
        $now = CarbonImmutable::now();

        $task = Task::query()->whereNull('parent_task_id')->inRandomOrder()->first();

        if ($task !== null) {
            $this->at($now->subDays(9), fn () => $task->taskAssignees()->firstOrCreate(['employee_id' => $employeeId]));
            $this->at($now->subDays(6), fn () => $task->update(['status' => TaskStatus::InProgress]));
            $this->at($now->subMinutes(12), fn () => $task->update(['priority' => TaskPriority::High]));
            $this->at($now->subMinutes(10), fn () => $task->update(['status' => TaskStatus::UnderReview]));
        }

        $risk = Risk::query()->where('happened', false)->inRandomOrder()->first();
        $this->at($now->subDays(3), fn () => $risk?->update(['happened' => true]));

        $openProposals = Proposal::query()->whereNotIn('status', [ProposalStatus::Approved->value, ProposalStatus::Rejected->value])->inRandomOrder()->limit(2)->get();
        $this->at($now->subDays(2)->subHours(3), fn () => $openProposals->get(0)?->update(['status' => ProposalStatus::Approved]));
        $this->at($now->subDay()->subHours(2), fn () => $openProposals->get(1)?->update(['status' => ProposalStatus::Rejected]));

        $tranche = TransfersTracking::query()->where('ar_status', ArStatus::Pending->value)->inRandomOrder()->first();
        $this->at($now->subDay(), fn () => $tranche?->update(['ar_status' => ArStatus::Failed]));

        $milestone = Milestone::query()->whereNotIn('status', [MilestoneStatus::Reached->value, MilestoneStatus::Cancelled->value])->inRandomOrder()->first();
        $this->at($now->subHours(5), fn () => $milestone?->update(['responsible_user_id' => $employeeId]));
        $this->at($now->subHours(3), fn () => $milestone?->update(['status' => MilestoneStatus::Postponed]));

        $project = Project::query()->where('user_id', '!=', $employeeId)->inRandomOrder()->first();
        $this->at($now->subMinutes(40), fn () => $project?->update(['user_id' => $employeeId]));

        UserNotification::query()
            ->forUser($recipient)
            ->whereNull('read_at')
            ->where('created_at', '<', $now->subDays(2))
            ->eachById(fn (UserNotification $notification) => $notification->forceFill(['read_at' => $notification->created_at->addHour()])->save());
    }

    /**
     * Run an action as if it happened at the given moment.
     */
    private function at(CarbonImmutable $moment, callable $action): void
    {
        Date::setTestNow($moment);
        $action();
        Date::setTestNow();
    }
}
