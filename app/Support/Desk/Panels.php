<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\ProposalReviewer;
use App\Models\User;
use App\Support\SupervisionMap;
use Closure;

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
        'General Manager' => [['glance', 'XL'], ['needs', 'L'], ['myteam', 'S'], ['reviews', 'S'], ['pipeline', 'L'], ['money', 'L'], ['matrix', 'XL'], ['risks', 'M'], ['duties', 'M'], ['targets', 'M'], ['plans', 'L'], ['activity', 'L'], ['projects', 'XL']],
        'Public Relations Manager' => [['needs', 'L'], ['coming', 'S'], ['waiting', 'S'], ['pipeline', 'L'], ['money', 'L'], ['projects', 'XL'], ['duties', 'M'], ['transfers', 'M'], ['reviews', 'M'], ['activity', 'L'], ['inbox', 'L']],
        'Public Relations' => [['needs', 'L'], ['coming', 'L'], ['tasks', 'L'], ['milestones', 'L'], ['projects', 'XL'], ['activity', 'L'], ['inbox', 'L']],
        'MEAL' => [['needs', 'L'], ['validate', 'S'], ['templates', 'S'], ['matrix', 'XL'], ['targets', 'L'], ['myteam', 'L'], ['projects', 'XL'], ['reviews', 'M'], ['coming', 'M'], ['inbox', 'M']],
        'Financial' => [['needs', 'L'], ['money', 'L'], ['transfers', 'M'], ['deliverables', 'M'], ['duties', 'M'], ['projects', 'XL'], ['reviews', 'M'], ['activity', 'W']],
        'Managers' => [['needs', 'L'], ['coming', 'S'], ['waiting', 'S'], ['matrix', 'XL'], ['tasks', 'L'], ['milestones', 'L'], ['projects', 'XL'], ['myteam', 'M'], ['risks', 'M'], ['inbox', 'M']],
        'Media Team' => [['needs', 'L'], ['deliverables', 'L'], ['coming', 'L'], ['tasks', 'L'], ['projects', 'XL']],
        'Team Member' => [['needs', 'L'], ['tasks', 'L'], ['coming', 'L'], ['milestones', 'L'], ['projects', 'XL']],
    ];

    /**
     * The role whose layout someone gets when none of their roles has one.
     */
    private const string FallbackRole = 'Team Member';

    /**
     * Every panel the given user may see, keyed by id, in library order.
     *
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}>
     */
    public function availableTo(User $user): array
    {
        $panels = [];

        foreach ($this->definitions() as $id => $panel) {
            if (! ($panel['allows'])($user)) {
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
     * The user's Desk: their own arrangement, or their role's default, with
     * every panel they may not see (and every unknown entry) left out.
     *
     * @return list<array{panel: string, size: string}>
     */
    public function layoutFor(User $user): array
    {
        $available = $this->availableTo($user);
        $stored = is_array($user->desk_layout) ? $user->desk_layout : $this->defaultLayoutFor($user);
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

        return array_map(
            fn (array $entry): array => ['panel' => $entry[0], 'size' => $entry[1]],
            self::RoleLayouts[$role ?? self::FallbackRole],
        );
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
            'risks' => ['icon' => 'flag', 'size' => 'M', 'register' => 'risks.index', 'allows' => $can('risks.view')],
            'targets' => ['icon' => 'chart', 'size' => 'L', 'register' => 'targets.index', 'allows' => $can('targets.view')],
            'duties' => ['icon' => 'file', 'size' => 'M', 'register' => 'reporting-duties.index', 'allows' => fn (User $user): bool => $user->can('reports.aggregate') || $user->can('funding-agreements.update') || $user->hasReportingDuties()],
            'activity' => ['icon' => 'undo', 'size' => 'L', 'register' => 'activity.index', 'allows' => $can('activity-log.view')],
            'inbox' => ['icon' => 'bell', 'size' => 'M', 'register' => 'notifications.index', 'allows' => $everyone],
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
