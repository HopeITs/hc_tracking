<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ArStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;

/**
 * One-click actions offered inside a notification. Offered only to people
 * whose permissions allow them; re-checked (permission and current state)
 * when run, because the record may have moved on since. Every write goes
 * through the model, so it is recorded as an activity by the acting user.
 */
final class QuickActions
{
    public function __construct(private Inbox $inbox) {}

    /**
     * @return list<array{type: string}>
     */
    public function forSubject(?string $subjectType, ?int $subjectId, User $user): array
    {
        if ($subjectType === null || $subjectId === null) {
            return [];
        }

        $types = match ($subjectType) {
            'task' => $user->can('tasks.update') ? match (Task::query()->whereKey($subjectId)->toBase()->value('status')) {
                TaskStatus::Todo->value => ['task.start', 'task.mark_done'],
                TaskStatus::InProgress->value, TaskStatus::UnderReview->value => ['task.mark_done'],
                default => [],
            } : [],
            'report' => $user->can('reports.update') ? match (Report::query()->whereKey($subjectId)->toBase()->value('status')) {
                ReportStatus::Draft->value => ['report.mark_reviewed'],
                ReportStatus::Reviewed->value => ['report.approve'],
                default => [],
            } : [],
            'transfer' => $user->can('transfers.update') && TransfersTracking::query()->whereKey($subjectId)->toBase()->value('ar_status') === ArStatus::Pending->value
                ? ['transfer.mark_received'] : [],
            default => [],
        };

        return array_map(fn (string $type): array => ['type' => $type], $types);
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    public function run(User $user, UserNotification $notification, string $type): array
    {
        if (! in_array($type, array_column($notification->data['actions'] ?? [], 'type'), true)) {
            return $this->result(false, 'unavailable');
        }

        $id = (int) $notification->subject_id;

        return match ($type) {
            'task.start', 'task.mark_done' => $this->runTask($user, $id, $type),
            'report.mark_reviewed', 'report.approve' => $this->runReport($user, $id, $type),
            'transfer.mark_received' => $user->can('transfers.update')
                ? ['ok' => true, 'message' => 'notifications.quick.opening', 'redirect' => route('transfers.index', ['edit' => $id])]
                : $this->result(false, 'forbidden'),
            'alert.snooze' => $this->snooze($user, $notification),
            default => $this->result(false, 'unavailable'),
        };
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    private function runTask(User $user, int $id, string $type): array
    {
        if (! $user->can('tasks.update')) {
            return $this->result(false, 'forbidden');
        }

        $task = Task::query()->find($id);

        if ($task === null) {
            return $this->result(false, 'missing');
        }

        if ($type === 'task.start') {
            if ($task->status !== TaskStatus::Todo) {
                return $this->result(false, 'already_started');
            }

            $task->status = TaskStatus::InProgress;
        } else {
            if ($task->status === TaskStatus::Done) {
                return $this->result(false, 'already_done');
            }

            $task->status = TaskStatus::Done;
            $task->completed_date ??= CarbonImmutable::today();
            $task->progress_percentage = 100;
        }

        $task->save();

        return $this->result(true, 'done');
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    private function runReport(User $user, int $id, string $type): array
    {
        if (! $user->can('reports.update')) {
            return $this->result(false, 'forbidden');
        }

        $report = Report::query()->find($id);

        if ($report === null) {
            return $this->result(false, 'missing');
        }

        if ($type === 'report.mark_reviewed') {
            if ($report->status !== ReportStatus::Draft) {
                return $this->result(false, 'already_done');
            }

            $report->fill(['status' => ReportStatus::Reviewed, 'submit_date' => now()->toDateString()])->save();
        } else {
            if ($report->status !== ReportStatus::Reviewed) {
                return $this->result(false, 'already_done');
            }

            $report->fill(['status' => ReportStatus::Approved])->save();
        }

        return $this->result(true, 'done');
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    private function snooze(User $user, UserNotification $notification): array
    {
        $this->inbox->snooze($user, $notification->id, 'tomorrow');

        return $this->result(true, 'snoozed');
    }

    /**
     * @return array{ok: bool, message: string, redirect: null}
     */
    private function result(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => 'notifications.quick.'.$message, 'redirect' => null];
    }
}
