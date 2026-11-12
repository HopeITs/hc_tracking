<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\DonorReport;
use App\Models\ProgramRequest;
use App\Models\ProposalReviewer;
use App\Models\RiskLibrary;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Support\Str;

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
     * Pages reached from the top bar instead of the sidebar: the inbox,
     * notification preferences and rules from the bell's panel (not the
     * avatar menu). The Ask bar still finds them.
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
     * The icon each collapsible sidebar group shows on its own row: the Lucide line icons imported
     * through `php artisan flux:icon` and kept as resources/views/flux/icon/nav-*.blade.php (the
     * nav- prefix keeps them from replacing the Heroicons of the same name used across the site).
     */
    private const array GroupIcons = [
        'overview' => 'nav-layout-dashboard',
        'strategy_planning' => 'nav-target',
        'projects_delivery' => 'nav-folder-kanban',
        'monitoring_evaluation' => 'nav-chart-line',
        'funding_finance' => 'nav-wallet',
        'workspace' => 'nav-list-checks',
        'administration' => 'nav-settings',
        'you' => 'nav-circle-user',
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

            if (! $this->opens($user, $destination)) {
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
     * The destination behind a route, whoever may open it; null for a route
     * that is not a destination.
     *
     * @return array{route: string, label: string, icon: string, hero: string, group: string, module: string|null}|null
     */
    public function find(string $route): ?array
    {
        foreach ($this->all() as $destination) {
            if ($destination['route'] === $route) {
                return [
                    'route' => $destination['route'],
                    'label' => __($destination['label']),
                    'icon' => $destination['icon'],
                    'hero' => $destination['hero'],
                    'group' => $destination['group'],
                    'module' => $destination['module'],
                ];
            }
        }

        return null;
    }

    /**
     * Whether the user may open the destination behind a route: the one rule
     * the sidebar, the Ask bar and the Desk's page cards and buttons all read.
     */
    public function allows(User $user, string $route): bool
    {
        foreach ($this->all() as $destination) {
            if ($destination['route'] === $route) {
                return $this->opens($user, $destination);
            }
        }

        return false;
    }

    /**
     * An administrator opens every page, role-tied ones included, except a
     * page whose own gate holds administrators to it too: the risk library
     * belongs to the MEAL Manager slot holder alone (D-61), and its route
     * answered an administrator 403 while the sidebar and Desk offered it.
     *
     * @param  array{allows: Closure(User): bool, bindsAdministrators: bool}  $destination
     */
    private function opens(User $user, array $destination): bool
    {
        return ($destination['allows'])($user) || ($user->isAdministrator() && ! $destination['bindsAdministrators']);
    }

    /**
     * A route as a short, selector-safe slug for Desk item ids: dots become
     * dashes and a register's ".index" is dropped (admin.evidence-types.index
     * is "admin-evidence-types").
     */
    public static function slugOf(string $route): string
    {
        return str_replace('.', '-', str_ends_with($route, '.index') ? substr($route, 0, -strlen('.index')) : $route);
    }

    /**
     * Every destination route, in display order, whoever may open it.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return array_column($this->all(), 'route');
    }

    /**
     * The sidebar: the user's destinations grouped, empty groups left out.
     *
     * @return list<array{group: string, label: string, icon: string, items: list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null}>}>
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
                'icon' => self::GroupIcons[$group],
                'items' => array_values($byGroup->get($group)->all()),
            ];
        }

        return $groups;
    }

    /**
     * The sidebar link the given route lights up, with its group's heading.
     *
     * A register's own sub-pages (projects.show…) light up their register's
     * link, unless the page has a link of its own (reports.monthly-matrix is
     * not reports.index).
     *
     * @return array{route: string, label: string, group: string, group_label: string}|null
     */
    public function currentFor(User $user, string $routeName): ?array
    {
        $links = collect($this->sidebarFor($user))
            ->flatMap(fn (array $group): array => array_map(fn (array $item): array => [
                'route' => $item['route'],
                'label' => $item['label'],
                'group' => $group['group'],
                'group_label' => $group['label'],
            ], $group['items']));

        return $links->firstWhere('route', $routeName)
            ?? $links->first(fn (array $link): bool => $link['route'] !== 'dashboard'
                && str_ends_with($link['route'], '.index')
                && str_starts_with($routeName, Str::beforeLast($link['route'], '.index').'.'));
    }

    /**
     * @return list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null, allows: Closure(User): bool, bindsAdministrators: bool}>
     */
    private function all(): array
    {
        return $this->table ??= $this->table();
    }

    /**
     * @var list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null, allows: Closure(User): bool, bindsAdministrators: bool}>|null
     */
    private ?array $table = null;

    /**
     * @return list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null, allows: Closure(User): bool, bindsAdministrators: bool}>
     */
    private function table(): array
    {
        $can = fn (string $ability): Closure => fn (User $user): bool => $user->can($ability);
        $everyone = fn (): bool => true;
        $page = fn (string $route, string $label, string $icon, string $hero, string $group, Closure $allows, ?string $module = null, bool $bindsAdministrators = false): array => [
            'route' => $route,
            'label' => $label,
            'icon' => $icon,
            'hero' => $hero,
            'group' => $group,
            'module' => $module,
            'allows' => $allows,
            'bindsAdministrators' => $bindsAdministrators,
        ];

        return [
            $page('dashboard', 'desk.your_desk', 'grid', 'nav-layout-dashboard', 'overview', $everyone),
            $page('reviews.index', 'common.my_reviews', 'file', 'nav-file-check', 'overview', $this->hasReviews(...)),

            $page('strategies.index', 'common.strategies', 'chart', 'nav-lightbulb', 'strategy_planning', $can('strategies.view'), 'strategies'),
            $page('programs.index', 'common.programs', 'folder', 'nav-layers', 'strategy_planning', $can('programs.view'), 'programs'),
            $page('annual-plans.index', 'common.annual_plans', 'cal', 'nav-calendar-range', 'strategy_planning', $can('annual-plans.view'), 'annual-plans'),

            $page('projects.index', 'common.projects', 'folder', 'nav-folder-open', 'projects_delivery', $can('projects.view'), 'projects'),
            $page('milestones.index', 'common.milestones', 'flag', 'nav-flag', 'projects_delivery', $can('milestones.view'), 'milestones'),

            $page('results.index', 'common.results_framework', 'chart', 'nav-network', 'monitoring_evaluation', $can('results.view'), 'results'),
            $page('indicator-library.index', 'navigation.indicator_library', 'chart', 'nav-library-big', 'monitoring_evaluation', $can('indicator-library.view'), 'indicator-library'),
            $page('indicators.index', 'common.indicators', 'chart', 'nav-gauge', 'monitoring_evaluation', $can('indicators.view'), 'indicators'),
            $page('targets.index', 'common.targets', 'dot', 'nav-crosshair', 'monitoring_evaluation', $can('targets.view'), 'targets'),
            $page('reports.index', 'common.reports', 'file', 'nav-file-chart-column', 'monitoring_evaluation', $can('reports.view'), 'reports'),
            $page('reports.monthly-matrix', 'navigation.monthly_matrix', 'table', 'nav-table', 'monitoring_evaluation', $can('reports.view')),
            $page('monthly-reports.index', 'navigation.monthly_reports', 'cal', 'nav-calendar-check', 'monitoring_evaluation', $can('reports.view'), 'monthly-reports'),
            $page('report-templates.index', 'navigation.report_templates', 'file', 'nav-clipboard-list', 'monitoring_evaluation', $can('report-templates.manage'), 'report-templates'),
            $page('donor-reports.index', 'navigation.donor_reports', 'file', 'nav-file-text', 'monitoring_evaluation', fn (User $user): bool => $user->can('viewAny', DonorReport::class)),
            $page('donor-reports.compose', 'navigation.donor_report_builder', 'file', 'nav-file-plus', 'monitoring_evaluation', $can('reports.aggregate')),

            $page('donors.index', 'common.donors', 'users', 'nav-hand-heart', 'funding_finance', $can('donors.view'), 'donors'),
            $page('proposals.index', 'common.proposals', 'file', 'nav-file-pen', 'funding_finance', $can('proposals.view'), 'proposals'),
            $page('funding-agreements.index', 'common.funding_agreements', 'coins', 'nav-handshake', 'funding_finance', $can('funding-agreements.view'), 'funding-agreements'),
            $page('transfers.index', 'common.transfers', 'coins', 'nav-arrow-left-right', 'funding_finance', $can('transfers.view'), 'transfers'),
            $page('admin.accounts.index', 'common.accounts', 'coins', 'nav-landmark', 'funding_finance', $can('accounts.view'), 'accounts'),

            $page('tasks.index', 'common.tasks', 'check', 'nav-square-check-big', 'workspace', $can('tasks.view'), 'tasks'),
            $page('department-tasks.index', 'titles.department_tasks', 'board', 'nav-building', 'workspace', fn (User $user): bool => $user->can('viewDepartmentQueue', Task::class)),
            $page('calendar.index', 'common.my_calendar', 'cal', 'nav-calendar', 'workspace', $can('calendar.view')),
            $page('notifications.index', 'desk.notifications', 'bell', 'nav-bell', 'workspace', $everyone),

            $page('admin.users.index', 'navigation.user_directory', 'users', 'nav-users', 'administration', fn (User $user): bool => $user->can('viewAny', User::class), 'access-management'),
            $page('admin.notification-settings.edit', 'navigation.notification_settings', 'bell', 'nav-sliders-horizontal', 'administration', $can('notification-settings.manage')),
            $page('activity.index', 'navigation.activity_log', 'undo', 'nav-file-clock', 'administration', $can('activity-log.view')),
            $page('admin.cities.index', 'common.cities', 'map', 'nav-map-pin', 'administration', $can('cities.view')),
            $page('admin.sectors.index', 'navigation.sectors', 'grid', 'nav-tags', 'administration', $can('sectors.view')),
            $page('admin.risk-library.index', 'navigation.risk_library', 'info', 'nav-siren', 'administration', fn (User $user): bool => $user->can('viewAny', RiskLibrary::class), bindsAdministrators: true),
            $page('admin.evidence-types.index', 'navigation.evidence_types', 'file', 'nav-paperclip', 'administration', $can('evidence-types.manage')),
            $page('admin.program-requests.index', 'navigation.program_requests', 'folder', 'nav-inbox', 'administration', fn (User $user): bool => $user->can('viewAny', ProgramRequest::class)),
            $page('admin.narrative-questions.index', 'navigation.narrative_questions', 'info', 'nav-message-circle-question-mark', 'administration', $can('narrative-questions.manage')),

            $page('security.edit', 'common.security', 'lock', 'nav-shield-check', 'you', $everyone),
            $page('notification-settings.edit', 'desk.my_notifications', 'bell', 'nav-bell-ring', 'you', $everyone),
        ];
    }

    /**
     * Someone named as a reviewer on any proposal has a My reviews page.
     */
    private function hasReviews(User $user): bool
    {
        /* Read once per person per request: the sidebar, Ask, cards and tabs all ask (it ran 18 times per Desk). */
        return $user->employee_id !== null
            && once(fn (): bool => ProposalReviewer::query()->forEmployee((int) $user->employee_id)->exists());
    }
}
