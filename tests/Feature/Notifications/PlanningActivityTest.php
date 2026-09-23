<?php

use App\Enums\MilestoneStatus;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\ScheduleBaseline;
use App\Models\Strategy;
use App\Models\UserNotification;

test('a new responsible person is told they are responsible, managers watch', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $newEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');
    $newResponsible = userWithRole('MEAL', $newEmployee);
    $watcher = userWithRole('Managers');
    $actor = userWithRole('Managers');
    $milestone = Activity::withoutRecording(fn () => Milestone::factory()->create([
        'project_id' => Project::factory()->create(['user_id' => $ownerEmployee])->id,
        'name' => 'Baseline survey',
    ]));

    $this->actingAs($actor);
    $milestone->update(['responsible_user_id' => $newEmployee]);

    expect(sentencesFor($newResponsible))->toBe([$actor->name.' made you responsible for “Baseline survey”'])
        ->and(UserNotification::query()->forUser($newResponsible)->sole()->priority)->toBe(NotificationPriority::Important)
        ->and(sentencesFor($owner))->toBe([$actor->name.' made Nour Ali responsible for “Baseline survey”'])
        ->and(sentencesFor($watcher))->toHaveCount(1)
        ->and(sentencesFor($actor))->toBe([]);
});

test('a postponed milestone reaches the general manager as important', function () {
    $generalManager = userWithRole('General Manager');
    $milestone = Activity::withoutRecording(fn () => Milestone::factory()->create());

    $this->actingAs(userWithRole('Managers'));
    $milestone->update(['status' => MilestoneStatus::Postponed]);

    expect(UserNotification::query()->forUser($generalManager)->sole()->priority)->toBe(NotificationPriority::Important);
});

test('a routine milestone edit does not reach the general manager', function () {
    $generalManager = userWithRole('General Manager');
    $milestone = Activity::withoutRecording(fn () => Milestone::factory()->create());

    $this->actingAs(userWithRole('Managers'));
    $milestone->update(['name' => 'Renamed']);

    expect(sentencesFor($generalManager))->toBe([]);
});

test('approving a project is key, deleting it is critical', function () {
    $generalManager = userWithRole('General Manager');
    $superAdmin = userWithRole('Super Admin');
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Winter Support']));

    $this->actingAs(userWithRole('Managers'));
    $project->update(['status' => ProjectStatus::Approved]);
    $this->travel(6)->minutes();
    $project->delete();

    $priorities = UserNotification::query()->forUser($generalManager)->orderBy('created_at')->pluck('priority')->all();

    expect($priorities)->toBe([NotificationPriority::Important, NotificationPriority::Critical])
        ->and(sentencesFor($superAdmin))->toHaveCount(2);
});

test('a new project owner is told, and the schedule baseline is recorded on the project', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Winter Support']));

    $this->actingAs(userWithRole('Managers'));
    $project->update(['user_id' => $ownerEmployee]);
    ScheduleBaseline::query()->create(['project_id' => $project->id, 'name' => 'Baseline 1']);

    expect(Activity::query()->where('subject_type', 'project')->orderBy('id')->pluck('event')->all())
        ->toBe(['project.owner_changed', 'project.baseline_saved'])
        ->and(sentencesFor($owner))->toHaveCount(1)
        ->and(sentencesFor($owner)[0])->toEndWith('made you the owner of “Winter Support” and 1 more update');
});

test('program and annual plan managers hear about a manager change', function () {
    $employee = insertHrEmployee();
    $manager = userWithRole('Managers', $employee);
    [$program, $plan] = Activity::withoutRecording(function (): array {
        $program = Program::factory()->unassigned()->create();

        return [$program, AnnualPlan::factory()->create(['program_id' => $program->id])];
    });

    $this->actingAs(userWithRole('Managers'));
    $program->update(['manager_id' => $employee]);
    $plan->update(['manager_id' => $employee]);

    expect(UserNotification::query()->forUser($manager)->pluck('module')->sort()->values()->all())->toBe(['annual-plans', 'programs']);
});

test('strategies are watched by managers', function () {
    $watcher = userWithRole('Managers');
    $strategy = Activity::withoutRecording(fn () => Strategy::factory()->create(['name' => 'Country Strategy']));

    $this->actingAs(userWithRole('Managers'));
    $strategy->update(['end_year' => 2030]);

    expect(sentencesFor($watcher))->toBe([Activity::query()->sole()->actor->name.' updated the strategy “Country Strategy”']);
});
