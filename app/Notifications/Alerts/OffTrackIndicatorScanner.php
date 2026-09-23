<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\Period;
use App\Models\Indicator;
use App\Models\Project;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Indicators behind pace or silent past their reporting frequency. Like
 * every alert, never sent on a non-working day.
 */
final class OffTrackIndicatorScanner implements Scanner
{
    public function __construct(
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private AlertLedger $ledger,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
        private WorkingCalendar $calendar,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        if (! $this->calendar->isWorkingDay($now)) {
            return 0;
        }

        $indicators = Indicator::query()->with('progress')->get();
        $owners = $this->ownerUsers($indicators->pluck('project_id')->filter()->unique()->values()->all());
        $meal = $this->resolver->usersWithRoles(['MEAL']);
        $paceStage = 'pace:'.$now->year;
        $sent = 0;

        foreach ($indicators as $indicator) {
            $direct = array_values(array_filter([$owners[$indicator->project_id] ?? null]));

            if ($this->isOffPace($indicator, $now)) {
                $payload = $this->dispatcher->payload('indicators', NotificationPriority::Important, 'notifications.alerts.off_track.pace', [
                    'subject' => $indicator->name,
                    'achieved' => (string) (int) floor((float) $indicator->percentAchieved()),
                    'expected' => (string) (int) floor($this->elapsedShare($now) * 100),
                ], 'indicator', $indicator->id);

                $sent += $this->dispatcher->send('off_track', $paceStage, $payload, $direct, $meal);
            } else {
                $this->ledger->release('off_track', 'indicator', $indicator->id, $paceStage);
            }

            $lastReading = $indicator->progress->last()?->date ?? $indicator->created_at;

            if ($lastReading !== null && $lastReading->lt($now->subMonths($this->windowMonths($indicator->frequency)))) {
                $payload = $this->dispatcher->payload('indicators', NotificationPriority::Important, 'notifications.alerts.off_track.silent', [
                    'subject' => $indicator->name,
                    'date' => ['date' => $lastReading->toDateString()],
                ], 'indicator', $indicator->id);

                $sent += $this->dispatcher->send('off_track', 'silent', $payload, $direct, $meal);
            } else {
                $this->ledger->release('off_track', 'indicator', $indicator->id, 'silent');
            }
        }

        return $sent;
    }

    /**
     * Below the configured share of the pace expected by now in the calendar
     * year. Unmeasurable indicators (no target, no reading) are never off pace.
     */
    public function isOffPace(Indicator $indicator, CarbonImmutable $now): bool
    {
        $achieved = $indicator->percentAchieved();
        $expected = $this->elapsedShare($now) * 100;

        return $achieved !== null && $expected > 0
            && $achieved < $expected * ((int) $this->settings->get('off_track_pace_percent') / 100);
    }

    private function elapsedShare(CarbonImmutable $now): float
    {
        $start = $now->startOfYear();

        return ($now->getTimestamp() - $start->getTimestamp()) / ($now->endOfYear()->getTimestamp() - $start->getTimestamp());
    }

    private function windowMonths(Period $frequency): int
    {
        return match ($frequency) {
            Period::Monthly => 1,
            Period::Quarterly => 3,
            Period::SemiAnnual => 6,
            Period::Annual => 12,
        };
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int> project id => owner user id
     */
    private function ownerUsers(array $projectIds): array
    {
        $ownerEmployees = Project::query()->whereIn('id', $projectIds)->pluck('user_id', 'id')->map(fn ($id): int => (int) $id)->all();
        $users = $this->employees->usersFor(array_values($ownerEmployees));

        return array_filter(array_map(fn (int $employeeId): ?int => $users[$employeeId] ?? null, $ownerEmployees));
    }
}
