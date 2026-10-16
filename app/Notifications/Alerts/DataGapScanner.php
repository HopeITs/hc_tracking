<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Models\Project;
use App\Models\Report;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Weekly nudges about missing data: active projects with no milestones or
 * indicators, projects past their end still active, approved reports with no
 * files. Runs on working days only, so each week's nudge lands on its first
 * working day.
 */
final class DataGapScanner implements Scanner
{
    public function __construct(
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
        private WorkingCalendar $calendar,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        if (! $this->calendar->isWorkingDay($now)) {
            return 0;
        }

        $week = $now->format('o-\WW');
        $meal = $this->resolver->usersWithRoles(['MEAL']);
        $sent = 0;

        $active = Project::query()
            ->where('status', ProjectStatus::Active->value)
            ->withCount(['milestones', 'indicators'])
            ->get(['id', 'name', 'user_id', 'end_date']);

        $owners = $this->employees->usersFor($active->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        foreach ($active as $project) {
            $owner = array_filter([$owners[(int) $project->user_id] ?? null]);
            $gaps = array_filter([
                'no_milestones' => $project->milestones_count === 0 ? [] : null,
                'no_indicators' => $project->indicators_count === 0 ? $meal : null,
                'past_end' => $project->end_date !== null && $project->end_date->lt($now->startOfDay()) ? [] : null,
            ], fn (?array $others): bool => $others !== null);

            foreach ($gaps as $gap => $others) {
                $payload = $this->dispatcher->payload('projects', NotificationPriority::Info, "notifications.alerts.data_gap.{$gap}", ['subject' => $project->name], 'project', $project->id);
                $sent += $this->dispatcher->send('data_gap', "{$gap}:{$week}", $payload, $owner, $others);
            }
        }

        $reports = Report::query()->where('status', ReportStatus::Approved->value)->whereNull('monthly_report_id')->doesntHave('files')->get(['id', 'user_id', 'plan_indicator_id']);

        foreach ($reports as $report) {
            $payload = $this->dispatcher->payload('reports', NotificationPriority::Info, 'notifications.alerts.data_gap.report_without_files', ['subject' => $report->activityLabel()], 'report', $report->id);
            $sent += $this->dispatcher->send('data_gap', "report_without_files:{$week}", $payload, [$report->user_id]);
        }

        return $sent;
    }
}
