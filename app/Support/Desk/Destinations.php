<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\ProposalReviewer;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Lang;

/**
 * Every page the Tracking Desk can open, with the rule that decides who may.
 *
 * The Desk has no menu: the Ask bar lists these as "Go to" results and the
 * Settings sheet lists the settings ones. Both read this one list, so a page
 * and its permission check are written once. Order follows the old sidebar
 * (money as it arrives, daily work after it, reference lists last).
 */
final class Destinations
{
    public const string Pages = 'pages';

    public const string Settings = 'settings';

    /**
     * The Settings sheet groups, each route with the permission that opens it
     * (shown in the lock tooltip). The sheet's "You" group also holds Install,
     * Report a problem and Log out, which are not routes.
     *
     * @var array<string, array<string, string|null>>
     */
    private const array SettingsGroups = [
        'you' => ['security.edit' => null, 'notification-settings.edit' => null],
        'people' => ['admin.users.index' => 'access-management.manage-users'],
        'meal' => [
            'indicator-library.index' => 'indicator-library.view',
            'admin.evidence-types.index' => 'evidence-types.manage',
            'admin.narrative-questions.index' => 'narrative-questions.manage',
            'admin.sectors.index' => 'sectors.view',
        ],
        'places' => [
            'admin.cities.index' => 'cities.view',
            'admin.accounts.index' => 'accounts.view',
            'strategies.index' => 'strategies.view',
        ],
        'system' => [
            'admin.notification-settings.edit' => 'notification-settings.manage',
            'executive-summary.index' => 'executive-summary.view',
            'activity.index' => 'activity-log.view',
        ],
    ];

    /**
     * Every destination the given user may open, in display order.
     *
     * @return list<array{route: string, label: string, icon: string, section: string}>
     */
    public function for(User $user, ?string $section = null): array
    {
        $destinations = [];

        foreach ($this->all() as $destination) {
            if ($section !== null && $destination['section'] !== $section) {
                continue;
            }

            if (! ($destination['allows'])($user)) {
                continue;
            }

            $destinations[] = [
                'route' => $destination['route'],
                'label' => __($destination['label']),
                'icon' => $destination['icon'],
                'section' => $destination['section'],
            ];
        }

        return $destinations;
    }

    /**
     * Every settings destination with whether the user may open it, grouped as
     * the Settings sheet shows them, so the locked ones can say why.
     *
     * @return list<array{route: string, group: string, label: string, icon: string, open: bool, hint: string, locked_reason: string}>
     */
    public function settingsFor(User $user): array
    {
        $byRoute = [];

        foreach ($this->all() as $destination) {
            $byRoute[$destination['route']] = $destination;
        }

        $rows = [];

        foreach (self::SettingsGroups as $group => $routes) {
            foreach ($routes as $route => $permission) {
                $destination = $byRoute[$route];

                $rows[] = [
                    'route' => $route,
                    'group' => $group,
                    'label' => __($destination['label']),
                    'icon' => $destination['icon'],
                    'open' => ($destination['allows'])($user),
                    'hint' => Lang::has($hintKey = 'desk.settings_hint.'.str_replace(['.', '-'], '_', $route)) ? __($hintKey) : '',
                    'locked_reason' => $permission === null
                        ? __('desk.settings_locked')
                        : __('desk.settings_locked_permission', ['permission' => $permission]),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{route: string, label: string, icon: string, section: string, allows: Closure(User): bool}>
     */
    private function all(): array
    {
        $can = fn (string $ability): Closure => fn (User $user): bool => $user->can($ability);

        return [
            ['route' => 'dashboard', 'label' => 'desk.your_desk', 'icon' => 'grid', 'section' => self::Pages, 'allows' => fn (): bool => true],
            ['route' => 'reviews.index', 'label' => 'common.my_reviews', 'icon' => 'file', 'section' => self::Pages, 'allows' => $this->hasReviews(...)],
            ['route' => 'strategies.index', 'label' => 'common.strategies', 'icon' => 'chart', 'section' => self::Pages, 'allows' => $can('strategies.view')],
            ['route' => 'programs.index', 'label' => 'common.programs', 'icon' => 'folder', 'section' => self::Pages, 'allows' => $can('programs.view')],
            ['route' => 'annual-plans.index', 'label' => 'common.annual_plans', 'icon' => 'cal', 'section' => self::Pages, 'allows' => $can('annual-plans.view')],
            ['route' => 'projects.index', 'label' => 'common.projects', 'icon' => 'folder', 'section' => self::Pages, 'allows' => $can('projects.view')],
            ['route' => 'milestones.index', 'label' => 'common.milestones', 'icon' => 'flag', 'section' => self::Pages, 'allows' => $can('milestones.view')],
            ['route' => 'timeline.index', 'label' => 'titles.timeline', 'icon' => 'cal', 'section' => self::Pages, 'allows' => $can('projects.view')],
            ['route' => 'risks.index', 'label' => 'common.risks', 'icon' => 'info', 'section' => self::Pages, 'allows' => $can('risks.view')],
            ['route' => 'results.index', 'label' => 'common.results_framework', 'icon' => 'chart', 'section' => self::Pages, 'allows' => $can('results.view')],
            ['route' => 'indicators.index', 'label' => 'common.indicators', 'icon' => 'chart', 'section' => self::Pages, 'allows' => $can('indicators.view')],
            ['route' => 'targets.index', 'label' => 'common.targets', 'icon' => 'dot', 'section' => self::Pages, 'allows' => $can('targets.view')],
            ['route' => 'reports.index', 'label' => 'common.reports', 'icon' => 'file', 'section' => self::Pages, 'allows' => $can('reports.view')],
            ['route' => 'reports.monthly-matrix', 'label' => 'navigation.monthly_matrix', 'icon' => 'table', 'section' => self::Pages, 'allows' => $can('reports.view')],
            ['route' => 'monthly-reports.index', 'label' => 'navigation.monthly_reports', 'icon' => 'cal', 'section' => self::Pages, 'allows' => $can('reports.view')],
            ['route' => 'report-templates.index', 'label' => 'navigation.report_templates', 'icon' => 'file', 'section' => self::Pages, 'allows' => $can('report-templates.manage')],
            ['route' => 'donor-reports.compose', 'label' => 'navigation.donor_report_builder', 'icon' => 'file', 'section' => self::Pages, 'allows' => $can('reports.aggregate')],
            ['route' => 'reporting-duties.index', 'label' => 'navigation.reporting_duties', 'icon' => 'file', 'section' => self::Pages, 'allows' => fn (User $user): bool => $user->can('funding-agreements.update') || $user->hasReportingDuties()],
            ['route' => 'donors.index', 'label' => 'common.donors', 'icon' => 'users', 'section' => self::Pages, 'allows' => $can('donors.view')],
            ['route' => 'proposals.index', 'label' => 'common.proposals', 'icon' => 'file', 'section' => self::Pages, 'allows' => $can('proposals.view')],
            ['route' => 'funding-agreements.index', 'label' => 'common.funding_agreements', 'icon' => 'coins', 'section' => self::Pages, 'allows' => $can('funding-agreements.view')],
            ['route' => 'transfers.index', 'label' => 'common.transfers', 'icon' => 'coins', 'section' => self::Pages, 'allows' => $can('transfers.view')],
            ['route' => 'tasks.index', 'label' => 'common.tasks', 'icon' => 'check', 'section' => self::Pages, 'allows' => $can('tasks.view')],
            ['route' => 'department-tasks.index', 'label' => 'titles.department_tasks', 'icon' => 'board', 'section' => self::Pages, 'allows' => $can('tasks.view')],
            ['route' => 'calendar.index', 'label' => 'common.my_calendar', 'icon' => 'cal', 'section' => self::Pages, 'allows' => $can('calendar.view')],
            ['route' => 'notifications.index', 'label' => 'desk.notifications', 'icon' => 'bell', 'section' => self::Pages, 'allows' => fn (): bool => true],

            ['route' => 'admin.users.index', 'label' => 'navigation.user_directory', 'icon' => 'users', 'section' => self::Settings, 'allows' => fn (User $user): bool => $user->can('viewAny', User::class)],
            ['route' => 'indicator-library.index', 'label' => 'navigation.indicator_library', 'icon' => 'chart', 'section' => self::Settings, 'allows' => $can('indicator-library.view')],
            ['route' => 'admin.evidence-types.index', 'label' => 'navigation.evidence_types', 'icon' => 'file', 'section' => self::Settings, 'allows' => $can('evidence-types.manage')],
            ['route' => 'admin.narrative-questions.index', 'label' => 'navigation.narrative_questions', 'icon' => 'info', 'section' => self::Settings, 'allows' => $can('narrative-questions.manage')],
            ['route' => 'admin.sectors.index', 'label' => 'navigation.sectors', 'icon' => 'grid', 'section' => self::Settings, 'allows' => $can('sectors.view')],
            ['route' => 'admin.notification-settings.edit', 'label' => 'navigation.notification_settings', 'icon' => 'bell', 'section' => self::Settings, 'allows' => $can('notification-settings.manage')],
            ['route' => 'admin.cities.index', 'label' => 'common.cities', 'icon' => 'map', 'section' => self::Settings, 'allows' => $can('cities.view')],
            ['route' => 'admin.accounts.index', 'label' => 'common.accounts', 'icon' => 'coins', 'section' => self::Settings, 'allows' => $can('accounts.view')],
            ['route' => 'notification-settings.edit', 'label' => 'desk.my_notifications', 'icon' => 'bell', 'section' => self::Settings, 'allows' => fn (): bool => true],
            ['route' => 'executive-summary.index', 'label' => 'common.executive_summary', 'icon' => 'chart', 'section' => self::Settings, 'allows' => $can('executive-summary.view')],
            ['route' => 'activity.index', 'label' => 'navigation.activity_log', 'icon' => 'undo', 'section' => self::Settings, 'allows' => $can('activity-log.view')],
            ['route' => 'security.edit', 'label' => 'common.security', 'icon' => 'lock', 'section' => self::Settings, 'allows' => fn (): bool => true],
        ];
    }

    /**
     * Someone named as a reviewer on any proposal has a My reviews page.
     */
    private function hasReviews(User $user): bool
    {
        return $user->employee_id !== null
            && ProposalReviewer::query()->forEmployee((int) $user->employee_id)->exists();
    }
}
