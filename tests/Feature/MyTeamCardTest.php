<?php

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Supervision;
use App\Models\Task;
use App\Models\User;
use App\Support\SupervisionMap;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

/**
 * A local supervision row between two HR employees, written without recording.
 */
function teamSupervision(int $supervisorEmployeeId, int $superviseeEmployeeId): void
{
    Activity::withoutRecording(fn () => Supervision::factory()->create([
        'supervisor_employee_id' => $supervisorEmployeeId,
        'supervisee_employee_id' => $superviseeEmployeeId,
    ]));
}

/**
 * A task assigned to one HR employee only.
 */
function supervisedTaskAssignedTo(int $employeeId, string $dueDate, TaskStatus $status = TaskStatus::InProgress): Task
{
    $task = Task::factory()->create(['due_date' => $dueDate, 'status' => $status]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    return $task;
}

/**
 * The My Team rows a user sees on Home.
 *
 * @return list<array{employeeId: int, name: string, overdue: int}>
 */
function myTeamFor(User $user): array
{
    return Livewire::actingAs($user)->test('pages::dashboard.index')->instance()->myTeam;
}

test('the card is hidden from someone who supervises nobody', function () {
    $user = userWithRole('Managers', insertHrEmployee());
    $unlinked = userWithRole('Managers');

    Livewire::actingAs($user)->test('pages::dashboard.index')->assertDontSeeHtml('data-my-team');
    Livewire::actingAs($unlinked)->test('pages::dashboard.index')->assertDontSeeHtml('data-my-team');

    expect(myTeamFor($user))->toBe([])
        ->and(myTeamFor($unlinked))->toBe([]);
});

test('it counts each supervisee\'s overdue open tasks, from HR and local supervision', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Saleh'));
    $hrReport = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Khalil');
    $localReport = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad');
    $department = insertHrDepartment();
    $managerPosition = insertHrPosition($department, 'Manager');
    insertHrContract($supervisor->employee_id, $managerPosition);
    insertHrContract($hrReport, insertHrPosition($department, directManagerId: $managerPosition));
    teamSupervision($supervisor->employee_id, $localReport);

    supervisedTaskAssignedTo($hrReport, '2026-09-01');
    supervisedTaskAssignedTo($hrReport, '2026-09-10', TaskStatus::Todo);
    supervisedTaskAssignedTo($hrReport, '2026-09-02', TaskStatus::Done);
    supervisedTaskAssignedTo($hrReport, '2026-09-20');
    supervisedTaskAssignedTo($localReport, '2026-09-20');

    expect(myTeamFor($supervisor))->toBe([
        ['employeeId' => $hrReport, 'name' => 'Omar Khalil', 'overdue' => 2],
        ['employeeId' => $localReport, 'name' => 'Lina Haddad', 'overdue' => 0],
    ]);

    Livewire::actingAs($supervisor)->test('pages::dashboard.index')
        ->assertSeeHtml('data-my-team')
        ->assertSeeHtml('data-my-team-person')
        ->assertSee('Omar Khalil')
        ->assertSeeHtml(e(route('tasks.index', ['assignee' => $hrReport])));
});

test('tasks the supervisor cannot see are not counted', function () {
    $finance = insertHrDepartment('Finance');
    $media = insertHrDepartment('Media');
    $supervisee = insertHrEmployee();
    insertHrContract($supervisee, insertHrPosition($finance));
    $supervisor = userWithRole('Team Member', insertHrEmployee());
    insertHrContract($supervisor->employee_id, insertHrPosition($media));
    teamSupervision($supervisor->employee_id, $supervisee);

    supervisedTaskAssignedTo($supervisee, '2026-09-01');

    expect(myTeamFor($supervisor))->toBe([
        ['employeeId' => $supervisee, 'name' => 'Sami Haddad', 'overdue' => 0],
    ]);
});

test('an inactive supervisee is not listed', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee());
    $inactive = insertHrEmployee(isActive: false);
    teamSupervision($supervisor->employee_id, $inactive);
    supervisedTaskAssignedTo($inactive, '2026-09-01');

    expect(myTeamFor($supervisor))->toBe([]);
    Livewire::actingAs($supervisor)->test('pages::dashboard.index')->assertDontSeeHtml('data-my-team');
});

test('the card costs the same queries for one or ten supervisees', function () {
    $queriesFor = function (int $superviseeCount): int {
        $supervisor = userWithRole('Managers', insertHrEmployee());

        foreach (range(1, $superviseeCount) as $index) {
            $supervisee = insertHrEmployee();
            teamSupervision($supervisor->employee_id, $supervisee);
            supervisedTaskAssignedTo($supervisee, '2026-09-01');
        }

        // A new request: forget the previous run's map, then render once so the
        // HR lookup is warm and only the card's own queries are counted.
        SupervisionMap::flush();
        $component = Livewire::actingAs($supervisor)->test('pages::dashboard.index')->instance();
        expect($component->myTeam)->toHaveCount($superviseeCount);
        SupervisionMap::flush();
        unset($component->myTeam);

        DB::enableQueryLog();
        DB::connection('hr')->enableQueryLog();
        DB::flushQueryLog();
        DB::connection('hr')->flushQueryLog();

        expect($component->myTeam)->toHaveCount($superviseeCount);

        $count = count(DB::getQueryLog()) + count(DB::connection('hr')->getQueryLog());
        DB::disableQueryLog();
        DB::connection('hr')->disableQueryLog();

        return $count;
    };

    expect($queriesFor(10))->toBe($queriesFor(1));
});
