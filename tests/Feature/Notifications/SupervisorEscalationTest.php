<?php

use App\Enums\NotificationPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Supervision;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\EscalationScanner;

/**
 * A local supervision row written without recording.
 */
function supervises(User $supervisor, User $supervisee): void
{
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $supervisor->employee_id,
        'supervisee_employee_id' => $supervisee->employee_id,
    ]));
}

/**
 * @return list<string>
 */
function alertKeysFor(User $user): array
{
    return UserNotification::query()->forUser($user)->get()->map(fn (UserNotification $notification): string => $notification->data['key'])->all();
}

beforeEach(function () {
    $this->managerEmployee = insertHrEmployee();
    $this->manager = userWithRole('Managers', $this->managerEmployee);
    $this->program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $this->managerEmployee]));
    $this->assignee = userWithRole('Managers', insertHrEmployee());
    $this->task = overdueTaskFor($this->assignee, '2026-09-11', $this->program->id); // Friday
});

test('the supervisor of the assignee is alerted at the manager stage inside the catch-up window', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee());
    supervises($supervisor, $this->assignee);

    runScanner(EscalationScanner::class, '2026-09-18 09:00'); // day 5

    $notification = UserNotification::query()->forUser($supervisor)->sole();

    expect($notification->priority)->toBe(NotificationPriority::Critical)
        ->and($notification->data['key'])->toBe('notifications.alerts.escalation.supervisor')
        ->and($notification->subject_id)->toBe($this->task->id)
        ->and(alertKeysFor($this->assignee))->toBe([]);
});

test('the HR direct manager of the assignee is alerted as supervisor', function () {
    $department = insertHrDepartment();
    $hrManager = userWithRole('Managers', insertHrEmployee());
    $managerPosition = insertHrPosition($department, 'Manager');
    insertHrContract($hrManager->employee_id, $managerPosition);
    insertHrContract($this->assignee->employee_id, insertHrPosition($department, 'Officer', directManagerId: $managerPosition));

    runScanner(EscalationScanner::class, '2026-09-18 09:00');

    expect(alertKeysFor($hrManager))->toBe(['notifications.alerts.escalation.supervisor']);
});

test('no supervisor alert outside the catch-up window', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee());
    supervises($supervisor, $this->assignee);

    runScanner(EscalationScanner::class, '2026-09-23 09:00'); // day 8

    expect(alertKeysFor($supervisor))->toBe([]);
});

test('an inactive supervisor gets nothing', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee(isActive: false));
    supervises($supervisor, $this->assignee);

    runScanner(EscalationScanner::class, '2026-09-18 09:00');

    expect(alertKeysFor($supervisor))->toBe([]);
});

test('a supervisor who is also the program manager gets one alert', function () {
    supervises($this->manager, $this->assignee);

    runScanner(EscalationScanner::class, '2026-09-18 09:00');

    expect(alertKeysFor($this->manager))->toBe(['notifications.alerts.escalation.manager']);
});

test('a supervisor who cannot see a department-scoped task gets nothing', function () {
    $finance = insertHrDepartment('Finance');
    $media = insertHrDepartment('Media');
    insertHrContract($this->assignee->employee_id, insertHrPosition($finance));
    $outsider = userWithRole('Team Member', insertHrEmployee());
    insertHrContract($outsider->employee_id, insertHrPosition($media));
    $colleague = userWithRole('Team Member', insertHrEmployee());
    insertHrContract($colleague->employee_id, insertHrPosition($finance));
    supervises($outsider, $this->assignee);
    supervises($colleague, $this->assignee);

    runScanner(EscalationScanner::class, '2026-09-18 09:00');

    expect(alertKeysFor($outsider))->toBe([])
        ->and(alertKeysFor($colleague))->toBe(['notifications.alerts.escalation.supervisor']);
});

test('the ledger prevents a repeat on the next scan', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee());
    supervises($supervisor, $this->assignee);

    runScanner(EscalationScanner::class, '2026-09-18 09:00'); // day 5
    runScanner(EscalationScanner::class, '2026-09-21 09:00'); // day 6, still inside the window

    expect(alertKeysFor($supervisor))->toHaveCount(1);
});

test('a non-working day sends nothing', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee());
    supervises($supervisor, $this->assignee);

    expect(runScanner(EscalationScanner::class, '2026-09-19 09:00'))->toBe(0) // Saturday
        ->and(alertKeysFor($supervisor))->toBe([]);
});

test('an item watched only by roles sends no supervisor alert', function () {
    $financial = userWithRole('Financial', insertHrEmployee());
    $supervisor = userWithRole('Managers', insertHrEmployee());
    supervises($supervisor, $financial);
    Activity::withoutRecording(fn () => $this->task->update(['status' => TaskStatus::Done]));
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-09-11']));

    runScanner(EscalationScanner::class, '2026-09-18 09:00');

    expect(UserNotification::query()->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->exists())->toBeFalse()
        ->and(alertKeysFor($supervisor))->toBe([]);
});
