<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Jobs\FanOutActivity;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one Activity and, when it is meaningful, queues its fan-out after
 * the surrounding transaction commits (so recipients read committed rows and
 * a rolled-back save notifies nobody).
 */
final class ActivityRecorder
{
    public function __construct(private EmployeeRecipients $employees) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function record(Model&TracksActivity $subject, string $event, array $changes = [], array $properties = [], bool $notify = true): ?Activity
    {
        if (! Activity::isRecording()) {
            return null;
        }

        $activity = Activity::query()->create([
            'actor_id' => Auth::id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'program_id' => $subject->activityProgramId(),
            'module' => $subject->activityModule(),
            'event' => $event,
            'changes' => $changes,
            'properties' => [
                'label' => $subject->activityLabel(),
                'notify' => $notify,
                ...$this->employeeNames($subject, $changes),
                ...$properties,
            ],
        ]);

        if ($notify) {
            FanOutActivity::dispatch($activity->id)->afterCommit();
        }

        return $activity;
    }

    /**
     * Both-language names for every changed employee field, so the sentence
     * reads the same after the HR record changes.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array{names?: array<string, array{0: array{en: string, ar: string}|null, 1: array{en: string, ar: string}|null}>}
     */
    private function employeeNames(TracksActivity $subject, array $changes): array
    {
        $fields = array_values(array_intersect($subject->activityEmployeeFields(), array_keys($changes)));

        if ($fields === []) {
            return [];
        }

        $ids = [];

        foreach ($fields as $field) {
            $ids[] = (int) $changes[$field][0];
            $ids[] = (int) $changes[$field][1];
        }

        $names = $this->employees->names($ids);
        $result = [];

        foreach ($fields as $field) {
            [$old, $new] = $changes[$field];
            $result[$field] = [$names[(int) $old] ?? null, $names[(int) $new] ?? null];
        }

        return ['names' => $result];
    }
}
