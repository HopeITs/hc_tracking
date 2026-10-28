<?php

use App\Enums\EventImportance;
use App\Enums\OversightMode;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\EventCatalog;
use Illuminate\Support\Facades\Lang;

/**
 * Every key of contracts.md §15.2, in table order.
 *
 * @return list<string>
 */
function contractEventKeys(): array
{
    return [
        'project.created', 'project.owner_acknowledged', 'project.owner_declined', 'project.approved', 'project.activated',
        'project.period_changed', 'project.started', 'project.ended', 'project.closed', 'project.archived', 'project.sectors_changed',
        'proposal.created', 'proposal.status_changed', 'proposal.review_requested', 'proposal.reviewer_added', 'proposal.reviewer_removed',
        'proposal.reviewer_seen', 'proposal.need_assessment_added', 'proposal.approved', 'proposal.approved_without_agreement',
        'proposal.on_hold_reminder', 'proposal.final_report_accepted', 'proposal.beneficiaries_changed', 'proposal.targets_saved',
        'proposal.project_linked', 'proposal.project_unlinked', 'proposal.deleted',
        'agreement.recorded', 'transfer.generated', 'transfer.received', 'transfer.late',
        'monthly_reports.generated', 'monthly_report.due', 'monthly_report.overdue', 'monthly_report.submitted', 'monthly_report.validated',
        'monthly_report.returned', 'monthly_report.reopen_requested', 'monthly_report.reopen_decided', 'monthly_report.reopen_escalated',
        'report_template.pending', 'report_template.confirmed', 'report_template.lines_added',
        'donor_report.saved', 'donor_report.submitted', 'donor_report.due', 'donor_report.overdue',
        'department_task.created', 'department_task.due', 'department_task.overdue', 'department_task.delivered', 'department_task.returned',
        'library_indicator.created', 'library_indicator.updated', 'library_indicator.deactivated', 'library_indicator.reactivated',
        'meal_coverage.assigned', 'meal_coverage.responsible_changed', 'meal_coverage.project_assigned',
        'program.logframe_missing', 'annual_plan.missing', 'supervision.added', 'supervision.removed',
        // T07: a moved MEAL Manager slot tells both holders; registered by the owning task, not yet in §15.2 (Dev 3).
        'notification_settings.meal_manager_changed',
        'result.created', 'result.updated', 'result.deleted',
        // T31: the approval rebase of a proposal's plan; registered by the owning task, not yet in §15.2 (Dev 3).
        'proposal.plan_rebased',
        // Desk redesign: a person nudges whoever holds their work; user-approved, not yet in §15.2.
        'nudge.sent',
        // Task workflow: submitted for review / sent back with a note; user-approved 2026-10-27, not yet in §15.2.
        'task_review.submitted', 'task_review.sent_back',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function saveEventOverrides(array $overrides): void
{
    app(NotificationSettings::class)->save(['event_overrides' => $overrides]);
}

test('the catalog holds exactly the contract keys, each once', function () {
    $expected = contractEventKeys();
    sort($expected);
    $keys = EventCatalog::keys();
    sort($keys);

    expect($keys)->toBe($expected)
        ->and(count(EventCatalog::keys()))->toBe(count(array_unique(EventCatalog::keys())));
});

test('every entry is fully described', function () {
    foreach (EventCatalog::all() as $key => $entry) {
        expect($entry['module'])->toBeString()->not->toBe('', $key)
            ->and($entry['importance'])->toBeInstanceOf(EventImportance::class)
            ->and($entry['direct'])->toBeIn(['module_map', 'listener', 'scanner'])
            ->and($entry['oversight'])->toBeInstanceOf(OversightMode::class)
            ->and($entry['critical'])->toBeBool();

        if ($entry['event'] !== null) {
            expect(class_exists($entry['event']))->toBeTrue($key);
        }
    }
});

test('importance, oversight and critical follow the contract', function () {
    $all = EventCatalog::all();
    $digest = array_keys(array_filter($all, fn (array $entry): bool => $entry['importance'] === EventImportance::Digest));
    $critical = array_keys(array_filter($all, fn (array $entry): bool => $entry['critical']));
    $none = array_keys(array_filter($all, fn (array $entry): bool => $entry['oversight'] === OversightMode::None));
    $management = array_keys(array_filter($all, fn (array $entry): bool => $entry['oversight'] === OversightMode::ManagementOnly));
    sort($digest);
    sort($critical);
    sort($none);
    sort($management);

    expect($digest)->toBe([
        'donor_report.saved', 'donor_report.submitted',
        'library_indicator.created', 'library_indicator.deactivated', 'library_indicator.reactivated', 'library_indicator.updated',
        'meal_coverage.assigned', 'meal_coverage.project_assigned', 'meal_coverage.responsible_changed',
        'program.logframe_missing',
        'project.archived', 'project.created', 'project.sectors_changed',
        'proposal.created', 'proposal.deleted', 'proposal.need_assessment_added', 'proposal.project_linked', 'proposal.project_unlinked',
        'proposal.reviewer_removed', 'proposal.reviewer_seen', 'proposal.targets_saved',
        'result.created', 'result.deleted', 'result.updated',
        'supervision.added', 'supervision.removed',
    ])
        ->and($critical)->toBe([
            'department_task.returned', 'monthly_report.reopen_decided', 'monthly_report.reopen_escalated', 'monthly_report.returned',
            'project.activated', 'project.approved', 'proposal.approved', 'proposal.status_changed',
        ])
        ->and($management)->toBe(['transfer.generated', 'transfer.late', 'transfer.received'])
        ->and($none)->toBe(['department_task.created', 'department_task.delivered', 'department_task.due', 'department_task.returned', 'nudge.sent', 'proposal.plan_rebased', 'task_review.sent_back', 'task_review.submitted']);
});

test('every key has a title and a body in English and Arabic', function () {
    foreach (EventCatalog::keys() as $key) {
        foreach (['en', 'ar'] as $locale) {
            foreach (['title', 'body'] as $part) {
                $line = "notifications.events.{$key}.{$part}";

                expect(Lang::has($line, $locale, false))->toBeTrue("{$line} ({$locale})")
                    ->and(__($line, [], $locale))->toBeString();
            }
        }
    }
});

test('effective merges the admin overrides into the definition', function () {
    saveEventOverrides([
        'project.closed' => ['importance' => 'digest', 'oversight_roles' => ['Financial', 'Super Admin']],
        'project.started' => ['enabled' => false],
        'project.ended' => ['importance' => 'loud'],
    ]);
    $catalog = app(EventCatalog::class);

    expect($catalog->effective('project.closed'))
        ->toMatchArray(['importance' => EventImportance::Digest, 'enabled' => true, 'oversight_roles' => ['Financial'], 'module' => 'projects'])
        ->and($catalog->effective('project.started')['enabled'])->toBeFalse()
        ->and($catalog->effective('project.ended')['importance'])->toBe(EventImportance::Immediate)
        ->and($catalog->effective('project.approved'))->toMatchArray(['enabled' => true, 'oversight_roles' => []]);
});

test('an unknown key has no definition', function () {
    saveEventOverrides(['made.up' => ['enabled' => false]]);

    expect(app(EventCatalog::class)->effective('made.up'))->toBeNull()
        ->and(EventCatalog::has('made.up'))->toBeFalse()
        ->and(EventCatalog::has('project.closed'))->toBeTrue();
});

test('an activity event finds its catalog key', function () {
    expect(EventCatalog::keyForActivity('library_indicator.created'))->toBe('library_indicator.created')
        ->and(EventCatalog::keyForActivity('donor_proposal.created'))->toBe('agreement.recorded')
        ->and(EventCatalog::keyForActivity('task.created'))->toBeNull();
});
