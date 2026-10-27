<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\ProposalReviewer;
use App\Models\User;
use App\Support\SupervisionMap;
use Closure;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The panels a Desk can hold, who may see each one, and the layout each role
 * starts from.
 *
 * A layout is an ordered list of [panel, size]; size is one of the Sizes keys
 * and sets how many of the Desk's twelve columns the panel spans. A person's
 * own arrangement is stored on users.desk_layout; until they change it they
 * get their role's default. A stored layout is always read through
 * layoutFor(), which drops panels the person may no longer see and unknown
 * ids or sizes, so a changed permission never shows a forbidden panel.
 */
final class Panels
{
    /**
     * Panel size => columns of the twelve-column Desk grid.
     *
     * @var array<string, int>
     */
    public const array Sizes = ['S' => 3, 'M' => 4, 'L' => 6, 'W' => 8, 'XL' => 12];

    /**
     * The starting layout per role, in the order the panels appear.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const array RoleLayouts = [
        'General Manager' => [['glance', 'XL'], ['needs', 'L'], ['myteam', 'S'], ['reviews', 'S'], ['pipeline', 'L'], ['money', 'L'], ['matrix', 'XL'], ['duties', 'M'], ['targets', 'M'], ['plans', 'L'], ['projects', 'XL']],
        'Public Relations Manager' => [['needs', 'L'], ['coming', 'S'], ['waiting', 'S'], ['pipeline', 'L'], ['money', 'L'], ['projects', 'XL'], ['duties', 'M'], ['transfers', 'M'], ['reviews', 'M']],
        'Public Relations' => [['needs', 'L'], ['coming', 'L'], ['tasks', 'L'], ['milestones', 'L'], ['projects', 'XL']],
        'MEAL' => [['needs', 'L'], ['validate', 'S'], ['templates', 'S'], ['matrix', 'XL'], ['targets', 'L'], ['myteam', 'L'], ['projects', 'XL'], ['reviews', 'M'], ['coming', 'M']],
        'Financial' => [['needs', 'L'], ['money', 'L'], ['transfers', 'M'], ['deliverables', 'M'], ['duties', 'M'], ['projects', 'XL'], ['reviews', 'M']],
        'Managers' => [['needs', 'L'], ['coming', 'S'], ['waiting', 'S'], ['matrix', 'XL'], ['tasks', 'L'], ['milestones', 'L'], ['projects', 'XL'], ['myteam', 'M']],
        'Media Team' => [['needs', 'L'], ['deliverables', 'L'], ['coming', 'L'], ['tasks', 'L'], ['projects', 'XL']],
        'Team Member' => [['needs', 'L'], ['tasks', 'L'], ['coming', 'L'], ['milestones', 'L'], ['projects', 'XL']],
    ];

    /**
     * The role whose layout someone gets when none of their roles has one.
     */
    private const string FallbackRole = 'Team Member';

    /**
     * The Panels popup's starting set per page, keyed by full route name or
     * by its first segment; '*' covers every other page. Panels the viewer
     * may not see are dropped when read, so a set may name any panel.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const array PageLayouts = [
        'projects' => [['milestones', 'L'], ['tasks', 'L'], ['coming', 'L']],
        'programs' => [['projects', 'XL'], ['plans', 'L'], ['targets', 'L']],
        'strategies' => [['glance', 'XL'], ['plans', 'L'], ['targets', 'L']],
        'annual-plans' => [['plans', 'L'], ['targets', 'L'], ['matrix', 'XL']],
        'executive-summary' => [['glance', 'XL'], ['money', 'L'], ['pipeline', 'L']],
        'milestones' => [['milestones', 'L'], ['coming', 'L'], ['tasks', 'L']],
        'timeline' => [['milestones', 'L'], ['coming', 'L'], ['projects', 'XL']],
        'risks' => [['needs', 'L'], ['projects', 'XL']],
        'results' => [['targets', 'L'], ['validate', 'L'], ['matrix', 'XL']],
        'indicators' => [['targets', 'L'], ['validate', 'L'], ['matrix', 'XL']],
        'indicator-library' => [['targets', 'L'], ['templates', 'L']],
        'targets' => [['targets', 'L'], ['validate', 'L'], ['matrix', 'XL']],
        'reports' => [['validate', 'L'], ['duties', 'L'], ['matrix', 'XL']],
        'monthly-reports' => [['validate', 'L'], ['templates', 'L'], ['matrix', 'XL']],
        'donor-reports' => [['duties', 'L'], ['deliverables', 'L'], ['donors', 'L']],
        'reporting-duties' => [['duties', 'L'], ['deliverables', 'L'], ['money', 'L']],
        'donors' => [['donors', 'L'], ['pipeline', 'L'], ['money', 'L']],
        'proposals' => [['pipeline', 'L'], ['reviews', 'L'], ['donors', 'L'], ['needs', 'L']],
        'reviews' => [['reviews', 'L'], ['pipeline', 'L']],
        'funding-agreements' => [['money', 'L'], ['transfers', 'L'], ['duties', 'L']],
        'transfers' => [['transfers', 'L'], ['money', 'L'], ['deliverables', 'L']],
        'admin.accounts.index' => [['transfers', 'L'], ['money', 'L']],
        'tasks' => [['tasks', 'L'], ['coming', 'L'], ['waiting', 'L'], ['deliverables', 'L']],
        'department-tasks' => [['deliverables', 'L'], ['tasks', 'L'], ['myteam', 'L']],
        'calendar' => [['coming', 'L'], ['tasks', 'L'], ['milestones', 'L']],
        'notifications' => [['needs', 'L'], ['coming', 'L']],
        'activity' => [['needs', 'L'], ['coming', 'L']],
        '*' => [['needs', 'L'], ['tasks', 'L'], ['coming', 'L']],
    ];

    /**
     * How many pages' Panels sets one person keeps.
     */
    private const int MaxStoredPages = 60;

    /**
     * Every panel the given user may see, keyed by id, in library order.
     *
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}>
     */
    public function availableTo(User $user): array
    {
        $panels = [];

        foreach ($this->definitions() as $id => $panel) {
            if (! $user->isAdministrator() && ! ($panel['allows'])($user)) {
                continue;
            }

            $panels[$id] = [
                'id' => $id,
                'title' => __("desk.panel.{$id}.title"),
                'description' => __("desk.panel.{$id}.description"),
                'icon' => $panel['icon'],
                'size' => $panel['size'],
                'register' => $panel['register'],
            ];
        }

        return $panels;
    }

    /**
     * Every panel the given user may not see, keyed by id, with the reason
     * the Customize sheet shows (desk.panel.<id>.locked).
     *
     * @return array<string, array{id: string, title: string, reason: string}>
     */
    public function lockedFor(User $user): array
    {
        $panels = [];

        foreach ($this->definitions() as $id => $panel) {
            if ($user->isAdministrator() || ($panel['allows'])($user)) {
                continue;
            }

            $panels[$id] = [
                'id' => $id,
                'title' => (string) __("desk.panel.{$id}.title"),
                'reason' => (string) __("desk.panel.{$id}.locked"),
            ];
        }

        return $panels;
    }

    /**
     * The user's Desk: their own arrangement, or their role's default, with
     * every panel they may not see (and every unknown entry) left out.
     *
     * @return list<array{panel: string, size: string}>
     */
    public function layoutFor(User $user): array
    {
        return $this->clean(is_array($user->desk_layout) ? $user->desk_layout : $this->defaultLayoutFor($user), $user);
    }

    /**
     * The panels behind the top bar's Panels button on one page (by route
     * name): the user's own set for that page, or the page's default.
     *
     * @return list<array{panel: string, size: string}>
     */
    public function pageLayoutFor(User $user, string $route): array
    {
        $stored = is_array($user->page_panels) ? ($user->page_panels[$route] ?? null) : null;

        return $this->clean(is_array($stored) ? $stored : $this->defaultPageLayoutFor($route), $user);
    }

    /**
     * Save one page's set; null puts the page back on its default. Only
     * known route names are kept, and the oldest pages fall off past
     * MaxStoredPages so the column stays small.
     *
     * @param  list<array{panel: string, size: string}>|null  $layout
     */
    public function savePageLayout(User $user, string $route, ?array $layout): void
    {
        if (! Route::has($route)) {
            return;
        }

        $pages = is_array($user->page_panels) ? $user->page_panels : [];
        unset($pages[$route]);

        if ($layout !== null) {
            $pages[$route] = array_values($layout);
        }

        $user->page_panels = $pages === [] ? null : array_slice($pages, -self::MaxStoredPages, preserve_keys: true);
        $user->save();
    }

    /**
     * A page's starting set: the panels that complement it, by full route
     * name or else its first segment (projects.show uses "projects").
     *
     * @return list<array{panel: string, size: string}>
     */
    public function defaultPageLayoutFor(string $route): array
    {
        $entries = self::PageLayouts[$route] ?? self::PageLayouts[Str::before($route, '.')] ?? self::PageLayouts['*'];

        return array_values(array_map(fn (array $entry): array => ['panel' => $entry[0], 'size' => $entry[1]], $entries));
    }

    /**
     * A stored layout read safely: unknown, forbidden and repeated panels
     * and unknown sizes are dropped, so a changed permission never shows a
     * forbidden panel.
     *
     * @param  array<mixed>  $stored
     * @return list<array{panel: string, size: string}>
     */
    private function clean(array $stored, User $user): array
    {
        $available = $this->availableTo($user);
        $layout = [];
        $seen = [];

        foreach ($stored as $entry) {
            $panel = is_array($entry) ? ($entry['panel'] ?? null) : null;
            $size = is_array($entry) ? ($entry['size'] ?? null) : null;

            if (! is_string($panel) || ! isset($available[$panel]) || isset($seen[$panel])) {
                continue;
            }

            $seen[$panel] = true;
            $layout[] = ['panel' => $panel, 'size' => is_string($size) && isset(self::Sizes[$size]) ? $size : $available[$panel]['size']];
        }

        return $layout;
    }

    /**
     * The layout of the first of the user's roles that has one.
     *
     * @return list<array{panel: string, size: string}>
     */
    public function defaultLayoutFor(User $user): array
    {
        $roles = $user->getRoleNames()->all();
        $role = collect(array_keys(self::RoleLayouts))->first(fn (string $name): bool => in_array($name, $roles, true));

        if ($role === null && $user->hasRole(config('access-control.administrator_roles', []))) {
            $role = 'General Manager';
        }

        return array_values(array_map(
            fn (array $entry): array => ['panel' => $entry[0], 'size' => $entry[1]],
            self::RoleLayouts[$role ?? self::FallbackRole],
        ));
    }

    /**
     * @return array<string, array{icon: string, size: string, register: string|null, allows: Closure(User): bool}>
     */
    private function definitions(): array
    {
        $everyone = fn (): bool => true;
        $can = fn (string $ability): Closure => fn (User $user): bool => $user->can($ability);

        return [
            'glance' => ['icon' => 'chart', 'size' => 'XL', 'register' => null, 'allows' => $everyone],
            'needs' => ['icon' => 'flag', 'size' => 'L', 'register' => null, 'allows' => $everyone],
            'waiting' => ['icon' => 'users', 'size' => 'M', 'register' => null, 'allows' => $everyone],
            'coming' => ['icon' => 'cal', 'size' => 'M', 'register' => 'calendar.index', 'allows' => $can('calendar.view')],
            'projects' => ['icon' => 'folder', 'size' => 'XL', 'register' => 'projects.index', 'allows' => $can('projects.view')],
            'pipeline' => ['icon' => 'chart', 'size' => 'L', 'register' => 'proposals.index', 'allows' => $can('proposals.view')],
            'money' => ['icon' => 'coins', 'size' => 'L', 'register' => 'funding-agreements.index', 'allows' => fn (User $user): bool => $user->can('funding-agreements.view') && $user->can('transfers.view')],
            'transfers' => ['icon' => 'coins', 'size' => 'M', 'register' => 'transfers.index', 'allows' => $can('transfers.view')],
            'matrix' => ['icon' => 'table', 'size' => 'XL', 'register' => 'reports.monthly-matrix', 'allows' => $can('reports.view')],
            'validate' => ['icon' => 'check', 'size' => 'M', 'register' => 'monthly-reports.index', 'allows' => fn (User $user): bool => $user->hasRole('MEAL')],
            'templates' => ['icon' => 'file', 'size' => 'M', 'register' => null, 'allows' => $can('report-templates.manage')],
            'reviews' => ['icon' => 'search', 'size' => 'M', 'register' => 'reviews.index', 'allows' => $this->hasReviews(...)],
            'tasks' => ['icon' => 'check', 'size' => 'L', 'register' => 'tasks.index', 'allows' => $can('tasks.view')],
            'deliverables' => ['icon' => 'board', 'size' => 'M', 'register' => 'department-tasks.index', 'allows' => $can('tasks.view')],
            'myteam' => ['icon' => 'users', 'size' => 'M', 'register' => null, 'allows' => fn (User $user): bool => $user->employee_id !== null && SupervisionMap::superviseesOf($user->employee_id) !== []],
            'targets' => ['icon' => 'chart', 'size' => 'L', 'register' => 'targets.index', 'allows' => $can('targets.view')],
            'duties' => ['icon' => 'file', 'size' => 'M', 'register' => 'reporting-duties.index', 'allows' => fn (User $user): bool => $user->can('reports.aggregate') || $user->can('funding-agreements.update') || $user->hasReportingDuties()],
            'milestones' => ['icon' => 'flag', 'size' => 'M', 'register' => 'milestones.index', 'allows' => $can('milestones.view')],
            'plans' => ['icon' => 'cal', 'size' => 'L', 'register' => 'annual-plans.index', 'allows' => $can('annual-plans.view')],
            'donors' => ['icon' => 'users', 'size' => 'L', 'register' => 'donors.index', 'allows' => $can('donors.view')],
        ];
    }

    /**
     * Someone named as a reviewer on any proposal has a My reviews panel.
     */
    private function hasReviews(User $user): bool
    {
        return $user->employee_id !== null
            && ProposalReviewer::query()->forEmployee((int) $user->employee_id)->exists();
    }
}
