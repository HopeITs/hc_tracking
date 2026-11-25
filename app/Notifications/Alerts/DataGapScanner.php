<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Models\ProposalLocation;
use App\Models\Report;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Weekly nudges about missing data: active places with no indicators, places
 * past their end still active, approved reports with no files, and (T14)
 * programs with open places but no program framework, through
 * LogframeGapAlert. Runs on working days only, so each week's nudge lands on
 * its first working day.
 *
 * Y-16 (D-93): a place is a **location row**. Two rows may stand at one place
 * (D-77), where a project produced one nudge: the ledger's stage carries the
 * place, so the second row of a place finds the week already claimed.
 */
final class DataGapScanner implements Scanner
{
    public function __construct(
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
        private WorkingCalendar $calendar,
        private LogframeGapAlert $logframeGaps,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        if (! $this->calendar->isWorkingDay($now)) {
            return 0;
        }

        $week = $now->format('o-\WW');
        $meal = $this->resolver->usersWithRoles(['MEAL']);
        $sent = 0;

        $active = ProposalLocation::query()
            ->notPaused()
            ->where('proposal_locations.status', ProjectStatus::Active->value)
            ->withCount('indicators')
            ->with(['centers', 'city:id,name_en,name_ar', 'program:id', 'proposal:id,name'])
            ->get(['id', 'proposal_id', 'project_id', 'city_id', 'city_wide', 'program_id', 'responsible_employee_id', 'end_date']);

        $owners = $this->employees->usersFor($active->pluck('responsible_employee_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());

        foreach ($active as $row) {
            $owner = array_filter([$owners[(int) $row->responsible_employee_id] ?? null]);
            $place = $row->place()->key();
            $gaps = array_filter([
                'no_indicators' => $row->indicators_count === 0 ? $meal : null,
                'past_end' => $row->end_date !== null && $row->end_date->lt($now->startOfDay()) ? [] : null,
            ], fn (?array $others): bool => $others !== null);

            foreach ($gaps as $gap => $others) {
                $payload = $this->dispatcher->payload('projects', NotificationPriority::Info, "notifications.alerts.data_gap.{$gap}", ['subject' => $row->headline()], 'proposal_location', $row->id);
                $sent += $this->dispatcher->send('data_gap', "{$gap}:{$place}:{$week}", $payload, $owner, $others);
            }
        }

        $reports = Report::query()->where('status', ReportStatus::Approved->value)->whereNull('monthly_report_id')->doesntHave('files')->get(['id', 'user_id', 'plan_indicator_id']);

        foreach ($reports as $report) {
            $payload = $this->dispatcher->payload('reports', NotificationPriority::Info, 'notifications.alerts.data_gap.report_without_files', ['subject' => $report->activityLabel()], 'report', $report->id);
            $sent += $this->dispatcher->send('data_gap', "report_without_files:{$week}", $payload, [$report->user_id]);
        }

        $sent += $this->logframeGaps->notify($this->programsWithOpenProjects());

        return $sent;
    }

    /**
     * Programs that hold at least one open place (draft, approved or active),
     * for the logframe rule (T14).
     *
     * @return list<int>
     */
    private function programsWithOpenProjects(): array
    {
        $open = array_values(array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()));

        return array_values(ProposalLocation::query()
            ->notPaused()
            ->whereIn('proposal_locations.status', array_map(fn (ProjectStatus $status): string => $status->value, $open))
            ->whereNotNull('program_id')
            ->distinct()
            ->pluck('program_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
