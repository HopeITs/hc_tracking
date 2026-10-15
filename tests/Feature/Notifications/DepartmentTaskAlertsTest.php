<?php

use App\Actions\Tasks\DeliverDepartmentTask;
use App\Actions\Tasks\ReturnDepartmentTask;
use App\Actions\Tasks\SaveDepartmentTask;
use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\ProposalStatus;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\DeadlineCatalog;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\OverdueScanner;
use Carbon\CarbonImmutable;

/*
 * Department tasks in the deadline scanners (T31, D-33, contracts §15.2):
 * `department_task.due` (assignee + department, in working days, default 5)
 * and `.overdue` (+ requester, + oversight). 2026-09-21 is a Monday.
 */

beforeEach(function () {
    $this->media = insertHrDepartment('Media');
    $this->memberEmployee = insertHrEmployee();
    insertHrContract($this->memberEmployee, insertHrPosition($this->media));
    $this->member = userWithRole('Media Team', $this->memberEmployee);

    $this->assigneeEmployee = insertHrEmployee(firstNameEn: 'Lina');
    insertHrContract($this->assigneeEmployee, insertHrPosition($this->media));
    $this->assignee = userWithRole('Media Team', $this->assigneeEmployee);

    $this->requester = userWithRole('Public Relations Manager', insertHrEmployee());
    $this->proposal = Activity::withoutRecording(fn (): Proposal => Proposal::factory()->create(['planned_date_begin' => '2026-01-01', 'planned_date_end' => '2027-12-31']));
});

function departmentAlertTask(string $due, bool $assigned = true, ?int $departmentId = null, ?Proposal $proposal = null): Task
{
    return Activity::withoutRecording(function () use ($due, $assigned, $departmentId, $proposal): Task {
        $task = Task::factory()
            ->forProposal($proposal ?? test()->proposal, $departmentId ?? test()->media)
            ->create(['title' => 'Film the distribution', 'due_date' => $due, 'created_by' => test()->requester->id]);

        $task->taskAssignees()->delete();

        if ($assigned) {
            $task->taskAssignees()->create(['employee_id' => test()->assigneeEmployee]);
        }

        return $task;
    });
}

function scanDepartmentDue(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DueSoonScanner::class)->scan(CarbonImmutable::now());
}

function scanDepartmentOverdue(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(OverdueScanner::class)->scan(CarbonImmutable::now());
}

test('the catalog lists a department task once, with its assignee and department, and the requester only when late', function () {
    $task = departmentAlertTask('2026-09-28');

    $items = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-10-15'))->where('subjectType', 'task')->where('subjectId', $task->id);

    expect($items)->toHaveCount(1);

    $item = $items->first();

    expect($item->eventKey)->toBe('department_task')
        ->and($item->directUserIds)->toEqualCanonicalizing([$this->member->id, $this->assignee->id])
        ->and($item->overdueUserIds)->toBe([$this->requester->id])
        ->and($item->dueSoonStages)->toBe([5, 0])
        ->and($item->unassignedUserIds)->toBe([]);
});

test('department task due alerts follow the working-day setting and fire once per stage', function () {
    departmentAlertTask('2026-09-28'); // Monday

    expect(scanDepartmentDue('2026-09-21 09:00'))->toBe(2)   // 5 working days: assignee + member
        ->and(scanDepartmentDue('2026-09-21 09:30'))->toBe(0) // deduplicated
        ->and(scanDepartmentDue('2026-09-24 09:00'))->toBe(0) // still inside the 5-day stage
        ->and(scanDepartmentDue('2026-09-28 09:00'))->toBe(2); // the day

    app(NotificationSettings::class)->save(['department_task_due_days' => 2]);
    departmentAlertTask('2026-10-07'); // Wednesday

    expect(scanDepartmentDue('2026-10-01 09:00'))->toBe(0)
        ->and(scanDepartmentDue('2026-10-05 09:00'))->toBe(2);
});

test('a department task with nobody named tells its department a week ahead, once', function () {
    departmentAlertTask('2026-09-30', assigned: false); // 7 working days after 2026-09-21

    expect(scanDepartmentDue('2026-09-21 09:00'))->toBe(2) // both people of Media
        ->and(scanDepartmentDue('2026-09-22 09:00'))->toBe(0);

    expect(sentencesFor($this->member))->toBe(['“Film the distribution” is due on Sep 30, 2026 and nobody in the department carries it yet — name a person']);
});

test('an overdue department task also tells its requester and oversight', function () {
    $gm = userWithRole('General Manager', insertHrEmployee());
    departmentAlertTask('2026-09-25');

    scanDepartmentOverdue('2026-09-28 09:00');

    expect(collect(['assignee' => $this->assignee, 'member' => $this->member, 'requester' => $this->requester, 'gm' => $gm])
        ->map(fn (User $user): int => UserNotification::query()->forUser($user)->count())->all())
        ->toBe(['assignee' => 1, 'member' => 1, 'requester' => 1, 'gm' => 1]);
});

test('a department removed from HR hands its alerts to the proposal owners', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    Activity::withoutRecording(function () use ($ownerEmployee): void {
        $project = Project::factory()->create(['user_id' => $ownerEmployee]);
        $project->proposals()->attach($this->proposal);
    });
    departmentAlertTask('2026-09-28', assigned: false, departmentId: 999_999);

    scanDepartmentDue('2026-09-21 09:00');

    expect(UserNotification::query()->forUser($owner)->count())->toBeGreaterThan(0)
        ->and(UserNotification::query()->forUser($this->member)->count())->toBe(0);
});

test('tasks and milestones of a rejected proposal stop alerting', function () {
    $task = departmentAlertTask('2026-09-28');
    Activity::withoutRecording(fn () => Milestone::factory()->create([
        'proposal_id' => $this->proposal->id,
        'project_id' => null,
        'name' => 'Plan milestone',
        'planned_start_date' => '2026-09-01',
        'planned_end_date' => '2026-09-28',
    ]));
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Rejected->value]);

    $items = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-10-15'));

    expect($items->where('subjectType', 'task')->where('subjectId', $task->id))->toHaveCount(0)
        ->and($items->where('label', 'Plan milestone'))->toHaveCount(0)
        ->and(scanDepartmentDue('2026-09-21 09:00'))->toBe(0)
        ->and(scanDepartmentOverdue('2026-09-29 09:00'))->toBe(0);
});

test('a proposal-only milestone alerts its responsible person and the proposal owners', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $responsible = userWithRole('Team Member', insertHrEmployee());
    Activity::withoutRecording(function () use ($ownerEmployee, $responsible): void {
        $project = Project::factory()->create(['user_id' => $ownerEmployee]);
        $project->proposals()->attach($this->proposal);
        Milestone::factory()->create([
            'proposal_id' => $this->proposal->id,
            'project_id' => null,
            'name' => 'Plan milestone',
            'responsible_user_id' => $responsible->employee_id,
            'planned_start_date' => '2026-09-01',
            'planned_end_date' => '2026-09-28',
        ]);
    });

    $item = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-10-15'))->firstWhere('label', 'Plan milestone');

    expect($item)->not->toBeNull()
        ->and($item->directUserIds)->toEqualCanonicalizing([$responsible->id, $owner->id]);
});

test('a switched-off department task event sends nothing', function () {
    app(NotificationSettings::class)->save(['event_overrides' => [
        'department_task.due' => ['enabled' => false],
        'department_task.overdue' => ['enabled' => false],
    ]]);
    departmentAlertTask('2026-09-25');

    expect(scanDepartmentDue('2026-09-21 09:00'))->toBe(0)
        ->and(scanDepartmentOverdue('2026-09-28 09:00'))->toBe(0);
});

test('a finished department task is quiet', function () {
    $task = departmentAlertTask('2026-09-25');
    Task::query()->withoutGlobalScopes()->whereKey($task->id)->update(['status' => TaskStatus::Done->value]);

    expect(scanDepartmentOverdue('2026-09-28 09:00'))->toBe(0);
});

test('a new department task tells its department; a delivery tells the requester; a return tells the assignee and department', function () {
    $manager = userWithRole('Public Relations Manager', insertHrEmployee());
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::ConceptNote->value]);
    $this->actingAs($manager);

    $task = app(SaveDepartmentTask::class)->handle($this->proposal->refresh(), null, [
        'title' => 'Photograph the kits',
        'department_id' => $this->media,
        'kind' => TaskKind::MediaDeliverable->value,
        'media_kinds' => [DeliverableKind::Text->value],
        'due_date' => '2026-12-01',
        'assignee_employee_id' => $this->assigneeEmployee,
    ]);

    $counts = fn (): array => collect(['assignee' => $this->assignee, 'member' => $this->member, 'manager' => $manager])
        ->map(fn (User $user): int => UserNotification::query()->forUser($user)->count())
        ->all();

    expect($counts())->toBe(['assignee' => 1, 'member' => 1, 'manager' => 0]);

    $this->actingAs($this->assignee);
    app(DeliverDepartmentTask::class)->handle($task, DeliveryState::Requested, ['delivered_on' => now()->toDateString(), 'delivered_text' => 'Captions attached.']);

    expect($counts())->toBe(['assignee' => 1, 'member' => 1, 'manager' => 1]);

    // Past the grouping window, so the return is its own notification rather than merged into the first.
    $this->travel(1)->day();
    $this->actingAs($manager);
    app(ReturnDepartmentTask::class)->handle($task, DeliveryState::Delivered, 'Add the photographer credit.');

    expect($counts())->toBe(['assignee' => 2, 'member' => 2, 'manager' => 1]);
});
