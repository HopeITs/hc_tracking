<?php

use App\Events\ProjectActivated;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\UserNotification;
use App\Notifications\EventCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * Activity events that are deliberately not catalog movements, with why.
 * A new movement must get a catalog entry, never a line here without Dev 1.
 */
const EVENT_CATALOG_IGNORED = [
    'project.status_changed' => 'Generic status move; approved/activated/closed/archived have their own keys (T05/T08 split the event).',
    'project.owner_changed' => 'Owner assignment flow, routed by the projects module map.',
    'project.dates_changed' => 'Covered by project.period_changed (T08) for active projects.',
    'project.updated' => 'Field edits, logged but notify nobody.',
    'project.deleted' => 'Archive is the movement (project.archived); hard delete is an admin cleanup.',
    'proposal.updated' => 'Field edits, logged but notify nobody.',
    'library_indicator.deleted' => 'Not a contracts §15 key; flagged to Dev 1 (catalog it or keep activity-only).',
    'meal_coverage.deleted' => 'Coverage removal is not a §15 movement.',
    'donor_proposal.updated' => 'Agreement edits; amounts never travel in notifications.',
    'donor_proposal.deleted' => 'Admin cleanup of an agreement.',
    'transfer.scheduled' => 'A manual tranche; generated ones are transfer.generated (T09).',
    'transfer.status_changed' => 'Received is its own key; other moves stay with the finance module.',
    'transfer.updated' => 'Field edits; amounts never travel in notifications.',
    'transfer.deleted' => 'Admin cleanup of a tranche.',
    'donor_report.created' => 'Legacy manual donor report; T19 records donor_report.saved.',
    'donor_report.updated' => 'Field edits, logged but notify nobody.',
    'donor_report.deleted' => 'Admin cleanup.',
    'task.created' => 'General tasks; department-task keys come with T31.',
    'task.status_changed' => 'General tasks; department-task keys come with T31.',
    'task.due_date_changed' => 'General tasks; department-task keys come with T31.',
    'task.priority_changed' => 'General tasks; department-task keys come with T31.',
    'task.updated' => 'General tasks; department-task keys come with T31.',
    'task.deleted' => 'General tasks; department-task keys come with T31.',
];

/**
 * The modules each owning task adds to config/notifications.php when it lands.
 */
const EVENT_CATALOG_PENDING_MODULES = ['donor-reports' => 'T19'];

/**
 * Every activity event a model can record from its own hooks: created,
 * deleted and the activityEvents() suffixes.
 *
 * @param  class-string<Model>  $class
 * @return list<string>
 */
function recordedEventsOf(string $class): array
{
    $model = new $class;
    $type = $model->getMorphClass();
    $hook = fn (string $method): string => (new ReflectionMethod($model, $method))->invoke($model);

    return array_values(array_unique([
        $hook('activityCreatedEvent'),
        $hook('activityDeletedEvent'),
        ...array_map(fn (string $suffix): string => "{$type}.{$suffix}", array_values($model->activityEvents())),
    ]));
}

test('every catalog key has its lang, a known direct source, an oversight mode and a known module', function () {
    $modules = [...array_keys(config('notifications.modules')), ...array_keys(EVENT_CATALOG_PENDING_MODULES)];

    foreach (EventCatalog::all() as $key => $entry) {
        foreach (['en', 'ar'] as $locale) {
            expect(Lang::has("notifications.events.{$key}.title", $locale, false))->toBeTrue("{$key} title ({$locale})")
                ->and(Lang::has("notifications.events.{$key}.body", $locale, false))->toBeTrue("{$key} body ({$locale})");
        }

        expect($entry['direct'])->toBeIn(['module_map', 'listener', 'scanner'], $key)
            ->and($entry['module'])->toBeIn($modules, $key);
    }
});

test('every domain event dispatched after commit is a catalog event', function () {
    $catalogEvents = array_values(array_filter(array_column(EventCatalog::all(), 'event')));

    foreach (glob(app_path('Events/*.php')) as $file) {
        $class = 'App\\Events\\'.basename($file, '.php');
        $reflection = new ReflectionClass($class);

        if ($reflection->isFinal() && $reflection->implementsInterface(ShouldDispatchAfterCommit::class)) {
            expect($catalogEvents)->toContain($class);
        }
    }
});

test('every recorded movement of the lifecycle models is a catalog key or ignored with a reason', function (string $class) {
    foreach (recordedEventsOf($class) as $event) {
        $covered = EventCatalog::keyForActivity($event) !== null || array_key_exists($event, EVENT_CATALOG_IGNORED);

        expect($covered)->toBeTrue("{$event} is neither in EventCatalog nor in EVENT_CATALOG_IGNORED");
    }
})->with([
    'projects' => Project::class,
    'proposals' => Proposal::class,
    'library indicators' => LibraryIndicator::class,
    'MEAL coverage' => MealCoverageAssignment::class,
    'funding agreements' => DonorProposal::class,
    'transfers' => TransfersTracking::class,
    'donor reports' => DonorReport::class,
    'department tasks' => Task::class,
]);

test('every ignored event is still recorded somewhere', function () {
    $recorded = array_merge(...array_map(recordedEventsOf(...), [
        Project::class, Proposal::class, LibraryIndicator::class, MealCoverageAssignment::class,
        DonorProposal::class, TransfersTracking::class, DonorReport::class, Task::class,
    ]));

    expect(array_diff(array_keys(EVENT_CATALOG_IGNORED), $recorded))->toBe([]);
});

test('a grouped monthly report activation sends exactly one notification', function () {
    $ownerId = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerId);
    $project = Activity::withoutRecording(fn (): Project => Project::factory()->active()->create([
        'user_id' => $ownerId,
        'start_date' => '2026-11-01',
        'end_date' => '2027-10-31',
    ]));

    ProjectActivated::dispatch($project->id, null, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-10-31'), 'template_confirmation');

    expect(UserNotification::query()->forUser($owner)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.monthly_reports.generated')
        ->count())->toBe(1);
});
