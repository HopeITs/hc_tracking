<?php

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Supervision;
use App\Models\Task;
use App\Models\User;
use App\Support\Desk\Panels;
use App\Support\Desk\TeamScope;
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
 * The My team rows a user sees on the Desk.
 *
 * @return list<array{employeeId: int, name: string, overdue: int, relation: string}>
 */
function myTeamFor(User $user): array
{
    return Livewire::actingAs($user)->test('desk.panels.myteam', ['size' => 'M'])->instance()->myTeam;
}

test('anyone linked to an HR employee may add the card, and an empty team says so', function () {
    $user = userWithRole('Managers', insertHrEmployee());
    $unlinked = userWithRole('Managers');

    expect(app(Panels::class)->availableTo($user))->toHaveKey('myteam')
        ->and(app(Panels::class)->availableTo($unlinked))->not->toHaveKey('myteam');

    expect(myTeamFor($user))->toBe([])
        ->and(myTeamFor($unlinked))->toBe([]);

    Livewire::actingAs($user)->test('desk.panels.myteam', ['size' => 'M'])
        ->assertSee(__('desk.panel.myteam.empty'));
});

test('the team adds project people and department colleagues, each once under the closest tie', function () {
    $department = insertHrDepartment();
    $me = userWithRole('Managers', insertHrEmployee(firstNameEn: 'Rana'));
    insertHrContract($me->employee_id, insertHrPosition($department));
    $colleague = insertHrEmployee(firstNameEn: 'Dina');
    insertHrContract($colleague, insertHrPosition($department));
    $report = insertHrEmployee(firstNameEn: 'Omar');
    insertHrContract($report, insertHrPosition($department));
    teamSupervision($me->employee_id, $report);
    $helper = insertHrEmployee(firstNameEn: 'Fadi');
    $stranger = insertHrEmployee(firstNameEn: 'Nour');

    Activity::withoutRecording(function () use ($me, $helper, $report, $stranger): void {
        $task = Task::factory()->create(['created_by' => $me->id, 'status' => TaskStatus::Todo]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->createMany([['employee_id' => $helper], ['employee_id' => $report], ['employee_id' => $me->employee_id]]);
        $done = Task::factory()->create(['created_by' => $me->id, 'status' => TaskStatus::Done]);
        $done->taskAssignees()->delete();
        $done->taskAssignees()->create(['employee_id' => $stranger]);
    });

    expect(TeamScope::for($me))->toEqual([$report => 'report', $helper => 'project', $colleague => 'department']);

    Livewire::actingAs($me)->test('desk.panels.myteam', ['size' => 'L'])
        ->assertSee(['Omar', 'Fadi', 'Dina', __('desk.relation.project')])
        ->assertDontSee('Nour')
        ->set('facets', ['relation' => __('desk.relation.department')])
        ->assertSee('Dina')
        ->assertDontSee('Fadi');
});

test('people on tasks of a project I own are on my team', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    $worker = insertHrEmployee(firstNameEn: 'Lina');
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['user_id' => $me->employee_id]));

    Activity::withoutRecording(function () use ($project, $worker): void {
        $task = Task::factory()->create(['source_type' => 'project', 'source_id' => $project->id, 'status' => TaskStatus::InProgress]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $worker]);
    });

    expect(TeamScope::for($me))->toBe([$worker => 'project']);
});

test('an administrator sees every linked employee but themselves', function () {
    $admin = userWithRole('Super Admin', insertHrEmployee());
    $other = userWithRole('Team Member', insertHrEmployee());

    expect(TeamScope::for($admin))->toBe([$other->employee_id => 'everyone']);
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
        ['employeeId' => $hrReport, 'name' => 'Omar Khalil', 'overdue' => 2, 'relation' => 'report'],
        ['employeeId' => $localReport, 'name' => 'Lina Haddad', 'overdue' => 0, 'relation' => 'report'],
    ]);

    Livewire::actingAs($supervisor)->test('desk.panels.myteam', ['size' => 'M'])
        ->assertSeeHtml('data-panel="myteam"')
        ->assertSeeInOrder(['Omar Khalil', trans_choice('desk.team_tasks_late', 2), 'Lina Haddad', __('desk.nothing_overdue')]);
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
        ['employeeId' => $supervisee, 'name' => 'Sami Haddad', 'overdue' => 0, 'relation' => 'report'],
    ]);
});

test('an inactive supervisee is not listed', function () {
    $supervisor = userWithRole('Managers', insertHrEmployee());
    $inactive = insertHrEmployee(isActive: false);
    teamSupervision($supervisor->employee_id, $inactive);
    supervisedTaskAssignedTo($inactive, '2026-09-01');

    expect(myTeamFor($supervisor))->toBe([]);
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
        $component = Livewire::actingAs($supervisor)->test('desk.panels.myteam', ['size' => 'M'])->instance();
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
