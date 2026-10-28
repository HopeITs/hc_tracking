<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\ProposalReviewer;
use App\Models\User;
use Closure;

/**
 * Every page the Tracking Desk can open, with the rule that decides who may.
 *
 * The sidebar, the Ask bar's "Go to" results and the avatar menu all read
 * this one list, so a page and its permission check are written once. Order
 * and groups follow the old sidebar; `module` names the unread-notification
 * module whose count the sidebar shows beside the link.
 */
final class Destinations
{
    /**
     * Sidebar group keys in display order (labels: navigation.group_*).
     *
     * @var list<string>
     */
    public const array Groups = ['overview', 'strategy_planning', 'projects_delivery', 'monitoring_evaluation', 'funding_finance', 'workspace', 'administration', 'you'];

    /**
     * Pages reached from the top bar instead of the sidebar: the inbox from the
     * bell, notification preferences and rules from the avatar menu and the
     * bell's panel. The Ask bar still finds them.
     *
     * @var list<string>
     */
    private const array OutsideSidebar = ['notifications.index', 'notification-settings.edit', 'admin.notification-settings.edit'];

    /**
     * Sidebar group => its heading's translation key.
     *
     * @var array<string, string>
     */
    private const array GroupLabels = [
        'overview' => 'navigation.group_overview',
        'strategy_planning' => 'navigation.group_strategy_planning',
        'projects_delivery' => 'navigation.group_projects_delivery',
        'monitoring_evaluation' => 'navigation.group_monitoring_evaluation',
        'funding_finance' => 'navigation.group_funding_finance',
        'workspace' => 'navigation.group_workspace',
        'administration' => 'navigation.group_administration',
        'you' => 'navigation.group_you',
    ];

    /**
     * Every destination the given user may open, in display order.
     *
     * @return list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null}>
     */
    public function for(User $user, ?string $group = null): array
    {
        $destinations = [];

        foreach ($this->all() as $destination) {
            if ($group !== null && $destination['group'] !== $group) {
                continue;
            }

            if (! $user->isAdministrator() && ! ($destination['allows'])($user)) {
                continue;
            }

            $destinations[] = [
                'route' => $destination['route'],
                'label' => __($destination['label']),
                'icon' => $destination['icon'],
                'hero' => $destination['hero'],
                'group' => $destination['group'],
                'module' => $destination['module'],
            ];
        }

        return $destinations;
    }

    /**
     * The sidebar: the user's destinations grouped, empty groups left out.
     *
     * @return list<array{group: string, label: string, items: list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null}>}>
     */
    public function sidebarFor(User $user): array
    {
        $byGroup = collect($this->for($user))->reject(fn (array $page): bool => in_array($page['route'], self::OutsideSidebar, true))->groupBy('group');
        $groups = [];

        foreach (self::Groups as $group) {
            if (! $byGroup->has($group)) {
                continue;
            }

            $groups[] = [
                'group' => $group,
                'label' => __(self::GroupLabels[$group]),
                'items' => array_values($byGroup->get($group)->all()),
            ];
        }

        return $groups;
    }

    /**
     * @return list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null, allows: Closure(User): bool}>
     */
    private function all(): array
    {
        $can = fn (string $ability): Closure => fn (User $user): bool => $user->can($ability);
        $everyone = fn (): bool => true;
        $page = fn (string $route, string $label, string $icon, string $hero, string $group, Closure $allows, ?string $module = null): array => [
            'route' => $route,
            'label' => $label,
            'icon' => $icon,
            'hero' => $hero,
            'group' => $group,
            'module' => $module,
            'allows' => $allows,
        ];

        return [
            $page('dashboard', 'desk.your_desk', 'grid', 'home', 'overview', $everyone),
            $page('reviews.index', 'common.my_reviews', 'file', 'document-magnifying-glass', 'overview', $this->hasReviews(...)),

            $page('strategies.index', 'common.strategies', 'chart', 'light-bulb', 'strategy_planning', $can('strategies.view'), 'strategies'),
            $page('programs.index', 'common.programs', 'folder', 'rectangle-group', 'strategy_planning', $can('programs.view'), 'programs'),
            $page('annual-plans.index', 'common.annual_plans', 'cal', 'calendar-date-range', 'strategy_planning', $can('annual-plans.view'), 'annual-plans'),

            $page('projects.index', 'common.projects', 'folder', 'folder-open', 'projects_delivery', $can('projects.view'), 'projects'),
            $page('milestones.index', 'common.milestones', 'flag', 'flag', 'projects_delivery', $can('milestones.view'), 'milestones'),
            $page('timeline.index', 'titles.timeline', 'cal', 'bars-3-bottom-left', 'projects_delivery', $can('projects.view')),
            $page('risks.index', 'common.risks', 'info', 'exclamation-triangle', 'projects_delivery', $can('risks.view'), 'risks'),

            $page('results.index', 'common.results_framework', 'chart', 'trophy', 'monitoring_evaluation', $can('results.view'), 'results'),
            $page('indicator-library.index', 'navigation.indicator_library', 'chart', 'book-open', 'monitoring_evaluation', $can('indicator-library.view'), 'indicator-library'),
            $page('indicators.index', 'common.indicators', 'chart', 'chart-bar', 'monitoring_evaluation', $can('indicators.view'), 'indicators'),
            $page('targets.index', 'common.targets', 'dot', 'viewfinder-circle', 'monitoring_evaluation', $can('targets.view'), 'targets'),
            $page('reports.index', 'common.reports', 'file', 'document-chart-bar', 'monitoring_evaluation', $can('reports.view'), 'reports'),
            $page('reports.monthly-matrix', 'navigation.monthly_matrix', 'table', 'table-cells', 'monitoring_evaluation', $can('reports.view')),
            $page('monthly-reports.index', 'navigation.monthly_reports', 'cal', 'newspaper', 'monitoring_evaluation', $can('reports.view'), 'monthly-reports'),
            $page('report-templates.index', 'navigation.report_templates', 'file', 'clipboard-document-check', 'monitoring_evaluation', $can('report-templates.manage'), 'report-templates'),
            $page('donor-reports.compose', 'navigation.donor_report_builder', 'file', 'document-plus', 'monitoring_evaluation', $can('reports.aggregate')),
            $page('reporting-duties.index', 'navigation.reporting_duties', 'file', 'inbox-stack', 'monitoring_evaluation', fn (User $user): bool => $user->can('funding-agreements.update') || $user->hasReportingDuties(), 'donor-reports'),

            $page('donors.index', 'common.donors', 'users', 'gift', 'funding_finance', $can('donors.view'), 'donors'),
            $page('proposals.index', 'common.proposals', 'file', 'document-text', 'funding_finance', $can('proposals.view'), 'proposals'),
            $page('funding-agreements.index', 'common.funding_agreements', 'coins', 'document-currency-dollar', 'funding_finance', $can('funding-agreements.view'), 'funding-agreements'),
            $page('transfers.index', 'common.transfers', 'coins', 'arrows-right-left', 'funding_finance', $can('transfers.view'), 'transfers'),
            $page('admin.accounts.index', 'common.accounts', 'coins', 'building-library', 'funding_finance', $can('accounts.view'), 'accounts'),

            $page('tasks.index', 'common.tasks', 'check', 'check-circle', 'workspace', $can('tasks.view'), 'tasks'),
            $page('department-tasks.index', 'titles.department_tasks', 'board', 'building-office-2', 'workspace', $can('tasks.view')),
            $page('calendar.index', 'common.my_calendar', 'cal', 'calendar', 'workspace', $can('calendar.view')),
            $page('notifications.index', 'desk.notifications', 'bell', 'bell', 'workspace', $everyone),

            $page('admin.users.index', 'navigation.user_directory', 'users', 'users', 'administration', fn (User $user): bool => $user->can('viewAny', User::class), 'access-management'),
            $page('admin.notification-settings.edit', 'navigation.notification_settings', 'bell', 'adjustments-horizontal', 'administration', $can('notification-settings.manage')),
            $page('activity.index', 'navigation.activity_log', 'undo', 'clipboard-document-list', 'administration', $can('activity-log.view')),
            $page('admin.cities.index', 'common.cities', 'map', 'map-pin', 'administration', $can('cities.view')),
            $page('admin.sectors.index', 'navigation.sectors', 'grid', 'tag', 'administration', $can('sectors.view')),
            $page('admin.evidence-types.index', 'navigation.evidence_types', 'file', 'paper-clip', 'administration', $can('evidence-types.manage')),
            $page('admin.narrative-questions.index', 'navigation.narrative_questions', 'info', 'question-mark-circle', 'administration', $can('narrative-questions.manage')),

            $page('security.edit', 'common.security', 'lock', 'shield-check', 'you', $everyone),
            $page('notification-settings.edit', 'desk.my_notifications', 'bell', 'cog-6-tooth', 'you', $everyone),
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
