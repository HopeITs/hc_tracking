<?php

use App\Models\Activity;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Recipients\OversightRecipients;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->meal = userWithRole('MEAL');
    $this->generalManager = userWithRole('General Manager');
    $this->bystander = userWithRole('Managers');
});

function oversight(): OversightRecipients
{
    return app(OversightRecipients::class);
}

/**
 * A project whose MEAL officer is the given employee.
 */
function projectWithOfficer(int $employeeId): Project
{
    return Activity::withoutRecording(function () use ($employeeId): Project {
        $project = Project::factory()->create();
        MealCoverageAssignment::factory()->forProject($project->id)->create(['employee_id' => $employeeId]);

        return $project;
    });
}

test('an all-mode event reaches MEAL, the project officer and General Management', function () {
    $officerEmployee = insertHrEmployee();
    $officer = userWithRole('Managers', $officerEmployee);
    $project = projectWithOfficer($officerEmployee);

    expect(oversight()->userIdsFor('project.closed', $project->id))
        ->toEqualCanonicalizing([$this->meal->id, $officer->id, $this->generalManager->id])
        ->and(oversight()->meal($project->id))->toEqualCanonicalizing([$this->meal->id, $officer->id])
        ->and(oversight()->management())->toBe([$this->generalManager->id]);
});

test('without a project only the MEAL role holders stand for MEAL', function () {
    expect(oversight()->userIdsFor('library_indicator.created'))
        ->toEqualCanonicalizing([$this->meal->id, $this->generalManager->id]);
});

test('an inactive project officer falls back to the MEAL role holders', function () {
    $officerEmployee = insertHrEmployee(isActive: false);
    userWithRole('Managers', $officerEmployee);
    $project = projectWithOfficer($officerEmployee);

    expect(oversight()->meal($project->id))->toBe([$this->meal->id]);
});

test('money events reach General Management only', function () {
    expect(oversight()->userIdsFor('transfer.received'))->toBe([$this->generalManager->id]);
});

test('an event without oversight, or an unknown one, reaches nobody', function (string $key) {
    expect(oversight()->userIdsFor($key))->toBe([]);
})->with(['none mode' => 'department_task.created', 'unknown key' => 'made.up']);

test('a role added in settings joins General Management for that event', function () {
    $financial = userWithRole('Financial');
    app(NotificationSettings::class)->save(['event_overrides' => [
        'transfer.received' => ['oversight_roles' => ['Financial', 'Deleted Role']],
    ]]);

    expect(oversight()->userIdsFor('transfer.received'))->toEqualCanonicalizing([$this->generalManager->id, $financial->id])
        ->and(oversight()->userIdsFor('transfer.late'))->toBe([$this->generalManager->id]);
});

test('Super Admin never oversees, by role or by setting', function () {
    userWithRole('Super Admin');
    $dualRole = userWithRole('MEAL');
    Activity::withoutRecording(fn () => $dualRole->assignRole('Super Admin'));
    app(NotificationSettings::class)->save(['event_overrides' => [
        'project.closed' => ['oversight_roles' => ['Super Admin']],
    ]]);

    expect(oversight()->userIdsFor('project.closed'))->toEqualCanonicalizing([$this->meal->id, $this->generalManager->id]);
});

test('a disabled event reaches nobody', function () {
    app(NotificationSettings::class)->save(['event_overrides' => ['project.closed' => ['enabled' => false]]]);

    expect(oversight()->userIdsFor('project.closed'))->toBe([]);
});

test('department members are mapped to their users', function () {
    $department = insertHrDepartment();
    $otherDepartment = insertHrDepartment('Finance');
    $member = insertHrEmployee();
    $memberWithoutAccount = insertHrEmployee();
    $outsider = insertHrEmployee();
    insertHrContract($member, insertHrPosition($department));
    insertHrContract($memberWithoutAccount, insertHrPosition($department));
    insertHrContract($outsider, insertHrPosition($otherDepartment));
    $memberUser = userWithRole('Media Team', $member);
    userWithRole('Media Team', $outsider);

    expect(oversight()->departmentUserIds($department))->toBe([$memberUser->id]);
});
