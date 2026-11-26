<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\ResponsibleInactiveScanner;
use App\Notifications\QuickActions;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
| W-35 (D-62): work still named after someone who has left HR asks to be
| reassigned — once per person per record, and quiet again afterwards.
|
| Only the project half is built: proposal location rows arrive with W-34a.
*/

const RESPONSIBLE_INACTIVE_KEY = 'notifications.alerts.responsible_inactive.project';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->prManager = userWithRole('Public Relations Manager');
    $this->teamMember = userWithRole('Team Member');
    $this->travelTo('2026-11-05 09:00:00');
});

/**
 * An open place whose responsible employee has left, or has not (Y-16, D-93).
 */
function projectOwnedBy(int $employeeId, ProjectStatus $status = ProjectStatus::Active): ProposalLocation
{
    return Activity::withoutRecording(fn (): ProposalLocation => rowFor(Project::factory()->create([
        'name' => 'Winter Shelter Aleppo',
        'status' => $status,
        'user_id' => $employeeId,
    ])));
}

function scanResponsible(): int
{
    return app(ResponsibleInactiveScanner::class)->scan(CarbonImmutable::now());
}

/**
 * @return list<int>
 */
function responsibleRecipients(ProposalLocation $row): array
{
    $ids = UserNotification::query()
        ->where('data->key', RESPONSIBLE_INACTIVE_KEY)
        ->where('subject_type', 'proposal_location')
        ->where('subject_id', $row->id)
        ->pluck('notifiable_id')
        ->map(fn (int|string $id): int => (int) $id)
        ->unique()
        ->all();
    sort($ids);

    return array_values($ids);
}

test('an open project whose owner has left HR asks to be reassigned', function () {
    $project = projectOwnedBy(insertHrEmployee(isActive: false));

    expect(scanResponsible())->toBeGreaterThan(0)
        ->and(responsibleRecipients($project))->toBe([$this->prManager->id]);
});

test('a project whose owner is still active says nothing', function () {
    projectOwnedBy(insertHrEmployee());

    expect(scanResponsible())->toBe(0);
});

test('a project that is not open says nothing', function (ProjectStatus $status) {
    projectOwnedBy(insertHrEmployee(isActive: false), $status);

    expect(scanResponsible())->toBe(0);
})->with([
    'closed' => ProjectStatus::Closed,
    'archived' => ProjectStatus::Archived,
]);

test('it is said once per person, not once per scan', function () {
    projectOwnedBy(insertHrEmployee(isActive: false));

    expect(scanResponsible())->toBeGreaterThan(0)
        ->and(scanResponsible())->toBe(0);
});

test('reassigning to another person who has also left asks again', function () {
    $project = projectOwnedBy(insertHrEmployee(isActive: false));
    scanResponsible();

    Activity::withoutRecording(fn () => $project->forceFill(['responsible_employee_id' => insertHrEmployee(isActive: false)])->save());

    expect(scanResponsible())->toBeGreaterThan(0);
});

test('reassigning to someone who is here goes quiet', function () {
    $project = projectOwnedBy(insertHrEmployee(isActive: false));
    scanResponsible();

    Activity::withoutRecording(fn () => $project->forceFill(['responsible_employee_id' => insertHrEmployee()])->save());

    expect(scanResponsible())->toBe(0);
});

test('the sentence names the project and the person, in both languages', function () {
    projectOwnedBy(insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad', firstNameAr: 'لينا', lastNameAr: 'حداد', isActive: false));

    scanResponsible();

    $sentence = sentencesFor($this->prManager)[0] ?? '';

    expect($sentence)->not->toBe('')
        ->and($sentence)->toContain('Winter Shelter Aleppo')
        ->and($sentence)->toContain('Lina Haddad');

    app()->setLocale('ar');

    expect(__(RESPONSIBLE_INACTIVE_KEY, ['subject' => 'س', 'person' => 'ص']))->not->toStartWith('notifications.');
});

test('HR is read once however many projects match', function () {
    foreach (range(1, 5) as $index) {
        projectOwnedBy(insertHrEmployee(isActive: false));
    }

    DB::connection('hr')->flushQueryLog();
    DB::connection('hr')->enableQueryLog();
    scanResponsible();
    $employeeReads = collect(DB::connection('hr')->getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'employees'))
        ->count();
    DB::connection('hr')->disableQueryLog();

    expect($employeeReads)->toBeLessThanOrEqual(2);
});

test('the reassign action is offered to someone who may change the project', function () {
    $project = projectOwnedBy(insertHrEmployee(isActive: false));

    $offered = fn (User $user): array => array_column(app(QuickActions::class)->forSubject('proposal_location', $project->id, $user), 'type');

    expect($offered($this->prManager))->toContain('project.reassign')
        ->and($offered($this->teamMember))->not->toContain('project.reassign');
});

test('the reassign action is not offered when the owner is still here', function () {
    $project = projectOwnedBy(insertHrEmployee());

    $offered = array_column(app(QuickActions::class)->forSubject('proposal_location', $project->id, $this->prManager), 'type');

    expect($offered)->not->toContain('project.reassign');
});
