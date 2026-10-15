<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\EventImportance;
use App\Enums\OversightMode;
use App\Events\FundingAgreementRecorded;
use App\Events\ProjectActivated;
use App\Events\ProjectPeriodChanged;
use App\Events\ProposalStatusChanged;
use App\Notifications\Alerts\NotificationSettings;
use LogicException;

/**
 * The one list of notification events (contracts.md §15). Every movement on
 * a project, a proposal or a report has an entry here; EventCatalogCoverageTest
 * fails otherwise. A task registers a key by adding one entry to its module
 * group below and `events.{key}.title|body` to lang/{en,ar}/notifications.php.
 *
 * Keys are stable strings: never rename one without a contracts.md change.
 *
 * @phpstan-type Definition array{module: string, importance: EventImportance, direct: string, oversight: OversightMode,
 *                                critical: bool, activity: string|null, event: class-string|null}
 * @phpstan-type Effective array{module: string, importance: EventImportance, direct: string, oversight: OversightMode, critical: bool,
 *                               activity: string|null, event: class-string|null, enabled: bool, oversight_roles: list<string>}
 */
final class EventCatalog
{
    /**
     * The role that never oversees anything (D-20a).
     */
    public const SuperAdminRole = 'Super Admin';

    /**
     * @var array<string, Definition>|null
     */
    private static ?array $all = null;

    /**
     * @var array<string, string>|null activity event => key
     */
    private static ?array $byActivity = null;

    public function __construct(private NotificationSettings $settings) {}

    /**
     * @return array<string, Definition> key => definition
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $all = [];

        foreach (self::groups() as $group) {
            foreach ($group as $key => $definition) {
                if (array_key_exists($key, $all)) {
                    throw new LogicException("Event key [{$key}] is registered twice.");
                }

                $all[$key] = $definition;
            }
        }

        return self::$all = $all;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * The catalog key an activity event belongs to, null when it is not a
     * catalog movement.
     */
    public static function keyForActivity(string $activityEvent): ?string
    {
        if (self::$byActivity === null) {
            self::$byActivity = [];

            foreach (self::all() as $key => $definition) {
                if ($definition['activity'] !== null) {
                    self::$byActivity[$definition['activity']] = $key;
                }
            }
        }

        return self::$byActivity[$activityEvent] ?? null;
    }

    /**
     * Definition merged with the admin overrides (notification_settings key
     * `event_overrides`); null for an unknown key. An override with an unknown
     * importance is ignored; Super Admin is never an oversight role.
     *
     * @return Effective|null
     */
    public function effective(string $key): ?array
    {
        $definition = self::all()[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        $overrides = $this->settings->get('event_overrides');
        $override = is_array($overrides) && is_array($overrides[$key] ?? null) ? $overrides[$key] : [];
        $importance = is_string($override['importance'] ?? null) ? EventImportance::tryFrom($override['importance']) : null;
        $roles = is_array($override['oversight_roles'] ?? null) ? $override['oversight_roles'] : [];

        return [
            ...$definition,
            'importance' => $importance ?? $definition['importance'],
            'enabled' => ($override['enabled'] ?? true) !== false,
            'oversight_roles' => array_values(array_unique(array_filter(
                $roles,
                fn (mixed $role): bool => is_string($role) && $role !== '' && $role !== self::SuperAdminRole,
            ))),
        ];
    }

    /**
     * One group per module, so a task edits only its own group.
     *
     * @return list<array<string, Definition>>
     */
    private static function groups(): array
    {
        $all = OversightMode::All;
        $now = EventImportance::Immediate;
        $digest = EventImportance::Digest;

        return [
            // projects
            [
                'project.created' => self::entry('projects', $digest, 'module_map', $all, activity: 'project.created'),
                'project.owner_acknowledged' => self::entry('projects', $now, 'module_map', $all, activity: 'project.owner_acknowledged'),
                'project.owner_declined' => self::entry('projects', $now, 'module_map', $all, activity: 'project.owner_declined'),
                'project.approved' => self::entry('projects', $now, 'module_map', $all, critical: true, activity: 'project.approved'),
                'project.activated' => self::entry('projects', $now, 'listener', $all, critical: true, event: ProjectActivated::class),
                'project.period_changed' => self::entry('projects', $now, 'listener', $all, event: ProjectPeriodChanged::class),
                'project.started' => self::entry('projects', $now, 'scanner', $all),
                'project.ended' => self::entry('projects', $now, 'scanner', $all),
                'project.closed' => self::entry('projects', $now, 'module_map', $all, activity: 'project.closed'),
                'project.archived' => self::entry('projects', $digest, 'module_map', $all, activity: 'project.archived'),
                'project.sectors_changed' => self::entry('projects', $digest, 'module_map', $all, activity: 'project.sectors_changed'),
            ],
            // proposals
            [
                'proposal.created' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.created'),
                'proposal.status_changed' => self::entry('proposals', $now, 'module_map', $all, critical: true, activity: 'proposal.status_changed', event: ProposalStatusChanged::class),
                'proposal.review_requested' => self::entry('proposals', $now, 'module_map', $all, activity: 'proposal.review_requested'),
                'proposal.reviewer_added' => self::entry('proposals', $now, 'module_map', $all, activity: 'proposal.reviewer_added'),
                'proposal.reviewer_removed' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.reviewer_removed'),
                'proposal.reviewer_seen' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.reviewer_seen'),
                'proposal.need_assessment_added' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.need_assessment_added'),
                'proposal.approved' => self::entry('proposals', $now, 'module_map', $all, critical: true, activity: 'proposal.approved'),
                'proposal.on_hold_reminder' => self::entry('proposals', $now, 'scanner', $all),
                'proposal.final_report_accepted' => self::entry('proposals', $now, 'module_map', $all, activity: 'proposal.final_report_accepted'),
                'proposal.beneficiaries_changed' => self::entry('proposals', $now, 'module_map', $all, activity: 'proposal.beneficiaries_changed'),
                'proposal.targets_saved' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.targets_saved'),
                'proposal.project_linked' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.project_linked'),
                'proposal.project_unlinked' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.project_unlinked'),
                'proposal.deleted' => self::entry('proposals', $digest, 'module_map', $all, activity: 'proposal.deleted'),
            ],
            // funding-agreements
            [
                'proposal.approved_without_agreement' => self::entry('funding-agreements', $now, 'listener', $all),
                'agreement.recorded' => self::entry('funding-agreements', $now, 'module_map', $all, activity: 'donor_proposal.created', event: FundingAgreementRecorded::class),
            ],
            // transfers
            [
                'transfer.generated' => self::entry('transfers', $now, 'listener', OversightMode::ManagementOnly),
                'transfer.received' => self::entry('transfers', $now, 'module_map', OversightMode::ManagementOnly, activity: 'transfer.received'),
                'transfer.late' => self::entry('transfers', $now, 'scanner', OversightMode::ManagementOnly),
            ],
            // monthly-reports
            [
                'monthly_reports.generated' => self::entry('monthly-reports', $now, 'listener', $all),
                'monthly_report.due' => self::entry('monthly-reports', $now, 'scanner', $all),
                'monthly_report.overdue' => self::entry('monthly-reports', $now, 'scanner', $all),
                'monthly_report.submitted' => self::entry('monthly-reports', $now, 'module_map', $all, activity: 'monthly_report.submitted'),
                'monthly_report.validated' => self::entry('monthly-reports', $now, 'module_map', $all, activity: 'monthly_report.validated'),
                'monthly_report.returned' => self::entry('monthly-reports', $now, 'module_map', $all, critical: true, activity: 'monthly_report.returned'),
                'monthly_report.reopen_requested' => self::entry('monthly-reports', $now, 'module_map', $all, activity: 'monthly_report.reopen_requested'),
                'monthly_report.reopen_decided' => self::entry('monthly-reports', $now, 'module_map', $all, critical: true, activity: 'monthly_report.reopen_decided'),
                'monthly_report.reopen_escalated' => self::entry('monthly-reports', $now, 'module_map', $all, critical: true, activity: 'monthly_report.reopen_escalated'),
            ],
            // report-templates
            [
                'report_template.pending' => self::entry('report-templates', $now, 'listener', $all),
                'report_template.confirmed' => self::entry('report-templates', $now, 'module_map', $all, activity: 'report_template.confirmed'),
                'report_template.lines_added' => self::entry('report-templates', $now, 'module_map', $all, activity: 'report_template.lines_added'),
            ],
            // donor-reports
            [
                'donor_report.saved' => self::entry('donor-reports', $digest, 'module_map', $all, activity: 'donor_report.saved'),
                'donor_report.submitted' => self::entry('donor-reports', $digest, 'module_map', $all, activity: 'donor_report.submitted'),
                'donor_report.due' => self::entry('donor-reports', $now, 'scanner', $all),
                'donor_report.overdue' => self::entry('donor-reports', $now, 'scanner', $all),
            ],
            // tasks (department tasks)
            [
                'department_task.created' => self::entry('tasks', $now, 'module_map', OversightMode::None, activity: 'department_task.created'),
                'department_task.due' => self::entry('tasks', $now, 'scanner', OversightMode::None),
                'department_task.overdue' => self::entry('tasks', $now, 'scanner', $all),
                'department_task.delivered' => self::entry('tasks', $now, 'module_map', OversightMode::None, activity: 'department_task.delivered'),
                'department_task.returned' => self::entry('tasks', $now, 'module_map', OversightMode::None, critical: true, activity: 'department_task.returned'),
            ],
            // indicator-library
            [
                'library_indicator.created' => self::entry('indicator-library', $digest, 'module_map', $all, activity: 'library_indicator.created'),
                'library_indicator.updated' => self::entry('indicator-library', $digest, 'module_map', $all, activity: 'library_indicator.updated'),
                'library_indicator.deactivated' => self::entry('indicator-library', $digest, 'module_map', $all, activity: 'library_indicator.deactivated'),
                'library_indicator.reactivated' => self::entry('indicator-library', $digest, 'module_map', $all, activity: 'library_indicator.reactivated'),
            ],
            // cities (MEAL coverage)
            [
                'meal_coverage.assigned' => self::entry('cities', $digest, 'module_map', $all, activity: 'meal_coverage.assigned'),
                'meal_coverage.responsible_changed' => self::entry('cities', $digest, 'module_map', $all, activity: 'meal_coverage.responsible_changed'),
                'meal_coverage.project_assigned' => self::entry('cities', $digest, 'module_map', $all, activity: 'meal_coverage.project_assigned'),
            ],
            // results
            [
                'program.logframe_missing' => self::entry('results', $digest, 'scanner', $all),
            ],
            // annual-plans
            [
                'annual_plan.missing' => self::entry('annual-plans', $now, 'scanner', $all),
            ],
            // access-management
            [
                'supervision.added' => self::entry('access-management', $digest, 'module_map', $all, activity: 'supervision.added'),
                'supervision.removed' => self::entry('access-management', $digest, 'module_map', $all, activity: 'supervision.removed'),
                // T07: the MEAL Manager slot moved to another HR position; the previous and new holders are told.
                'notification_settings.meal_manager_changed' => self::entry('access-management', $now, 'module_map', $all, activity: 'notification_settings.meal_manager_changed'),
            ],
        ];
    }

    /**
     * @param  class-string|null  $event
     * @return Definition
     */
    private static function entry(string $module, EventImportance $importance, string $direct, OversightMode $oversight, bool $critical = false, ?string $activity = null, ?string $event = null): array
    {
        return [
            'module' => $module,
            'importance' => $importance,
            'direct' => $direct,
            'oversight' => $oversight,
            'critical' => $critical,
            'activity' => $activity,
            'event' => $event,
        ];
    }
}
