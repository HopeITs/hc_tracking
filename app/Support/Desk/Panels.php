<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\ProposalReviewer;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The panels a Desk can hold, who may see each one, and the layout each role
 * starts from.
 *
 * A layout is an ordered list of [panel, size, cols, rows]: cols (3–12) is how
 * many of the Desk's twelve columns the panel spans and rows (2–8) how many row
 * units it is tall; size is the nearest Sizes step, kept for older readers. A person's
 * own arrangement is stored on users.desk_layout; until they change it they
 * get their role's default. A stored layout is always read through
 * layoutFor(), which drops panels the person may no longer see and unknown
 * ids or sizes, so a changed permission never shows a forbidden panel.
 *
 * Every layout is read flush (flush()): the cards, in order, fill rows of
 * exactly twelve columns and each row shares one height, so the Desk never
 * shows a hole between cards. resources/js/desk-rows.js mirrors these rules
 * for the live preview while a card is dragged or resized.
 *
 * Besides its hand-made panels the Desk holds every page and every action:
 * a page with no panel of its own as a page card ("p-…", PageCards), and any
 * page or "New …" action as a button ("g-…" / "a-…", Shortcuts). A button may
 * be one row unit tall (MinTileRows); everything else is at least MinRows.
 * itemForRoute() names what stands for a page when it is dragged in from the
 * sidebar or added from its own header.
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
     * The free cell grid's limits: a panel spans 3–12 columns and 2–8 row units.
     */
    public const int MinCols = 3;

    public const int MaxCols = 12;

    public const int MinRows = 2;

    public const int MaxRows = 8;

    /**
     * A button (Shortcuts) may be one row unit tall; a row of buttons reads as a strip.
     */
    public const int MinTileRows = 1;

    /**
     * The hand-made panel that stands for a page, by the page's route.
     *
     * @var array<string, string>
     */
    private const array RoutePanels = [
        'reviews.index' => 'reviews',
        'projects.index' => 'projects',
        'milestones.index' => 'milestones',
        'targets.index' => 'targets',
        'reports.monthly-matrix' => 'matrix',
        'monthly-reports.index' => 'validate',
        'report-templates.index' => 'templates',
        'donor-reports.index' => 'duties',
        'donors.index' => 'donors',
        'proposals.index' => 'pipeline',
        'transfers.index' => 'transfers',
        'tasks.index' => 'tasks',
        'department-tasks.index' => 'deliverables',
        'calendar.index' => 'coming',
    ];

    /**
     * The starting layout per role, in the order the panels appear.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const array RoleLayouts = [
        'General Manager' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['glance', 'XL'], ['reviews', 'L'], ['pipeline', 'L'], ['money', 'L']],
        'Public Relations Manager' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['coming', 'L'], ['waiting', 'L'], ['pipeline', 'L'], ['money', 'L'], ['projects', 'XL']],
        'Public Relations' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['coming', 'L'], ['tasks', 'L'], ['milestones', 'XL'], ['projects', 'XL']],
        'MEAL' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['validate', 'L'], ['templates', 'L'], ['matrix', 'XL'], ['targets', 'XL'], ['projects', 'XL']],
        'Financial' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['money', 'L'], ['transfers', 'L'], ['deliverables', 'L'], ['duties', 'L'], ['projects', 'XL']],
        'Managers' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['coming', 'L'], ['matrix', 'XL'], ['tasks', 'L'], ['myteam', 'L']],
        'Media Team' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['deliverables', 'L'], ['coming', 'L'], ['tasks', 'XL'], ['projects', 'XL']],
        'Team Member' => [['needs', 'L'], ['overdue', 'L'], ['flow', 'XL'], ['tasks', 'L'], ['coming', 'L'], ['milestones', 'L'], ['projects', 'XL']],
    ];

    /**
     * The Desk's "Start something" buttons, with Flux icon names. Each opens a register with
     * ?quick=create, which presses that page's own create button
     * (resources/js/desk-ask.js), so the usual form and permissions apply.
     *
     * @var list<array{key: string, route: string, ability: string, icon: string}>
     */
    private const array QuickActions = [
        ['key' => 'task', 'route' => 'tasks.index', 'ability' => 'tasks.create', 'icon' => 'clipboard-document-check'],
        ['key' => 'report', 'route' => 'reports.index', 'ability' => 'reports.create', 'icon' => 'document-chart-bar'],
        ['key' => 'proposal', 'route' => 'proposals.index', 'ability' => 'proposals.create', 'icon' => 'document-plus'],
        ['key' => 'donor', 'route' => 'donors.index', 'ability' => 'donors.create', 'icon' => 'gift'],
        ['key' => 'project', 'route' => 'projects.index', 'ability' => 'projects.create', 'icon' => 'folder-plus'],
        ['key' => 'milestone', 'route' => 'milestones.index', 'ability' => 'milestones.create', 'icon' => 'flag'],
        ['key' => 'agreement', 'route' => 'funding-agreements.index', 'ability' => 'funding-agreements.create', 'icon' => 'document-currency-dollar'],
        ['key' => 'transfer', 'route' => 'transfers.index', 'ability' => 'transfers.create', 'icon' => 'banknotes'],
    ];

    /**
     * Row units (desk.css --row-unit) a panel is tall when its definition does not say.
     */
    public const int DefaultRows = 4;

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
        'programs' => [['projects', 'XL'], ['targets', 'L']],
        'strategies' => [['glance', 'XL'], ['targets', 'L']],
        'annual-plans' => [['targets', 'L'], ['matrix', 'XL']],
        'milestones' => [['milestones', 'L'], ['coming', 'L'], ['tasks', 'L']],
        'risks' => [['needs', 'L'], ['projects', 'XL']],
        'results' => [['targets', 'L'], ['validate', 'L'], ['matrix', 'XL']],
        'indicators' => [['targets', 'L'], ['validate', 'L'], ['matrix', 'XL']],
        'indicator-library' => [['targets', 'L'], ['templates', 'L']],
        'targets' => [['targets', 'L'], ['validate', 'L'], ['matrix', 'XL']],
        'reports' => [['validate', 'L'], ['duties', 'L'], ['matrix', 'XL']],
        'monthly-reports' => [['validate', 'L'], ['templates', 'L'], ['matrix', 'XL']],
        'donor-reports' => [['duties', 'L'], ['deliverables', 'L'], ['donors', 'L']],
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
     * The ready layouts of the Customize sheet: each names the panel without
     * which it is not offered (core) and its rows, every row an ordered list
     * of [panel, cols, rows] that totals twelve columns and shares one height.
     * Only panels and page cards: buttons live in Quick access, never on the Desk.
     *
     * @var array<string, array{core: string, rows: list<list<array{0: string, 1: int, 2: int}>>}>
     */
    private const array ReadyLayouts = [
        'today' => ['core' => 'needs', 'rows' => [
            [['needs', 7, 4], ['coming', 5, 4]],
            [['tasks', 8, 4], ['milestones', 4, 4]],
            [['overdue', 6, 3], ['waiting', 6, 3]],
        ]],
        'money' => ['core' => 'money', 'rows' => [
            [['money', 8, 5], ['transfers', 4, 5]],
            [['p-funding-agreements', 6, 4], ['duties', 6, 4]],
            [['p-admin-accounts', 6, 3], ['overdue', 6, 3]],
        ]],
        'donors' => ['core' => 'donors', 'rows' => [
            [['donors', 7, 5], ['duties', 5, 5]],
            [['pipeline', 8, 4], ['coming', 4, 4]],
            [['p-donor-reports', 6, 3], ['p-funding-agreements', 6, 3]],
        ]],
        'tasks' => ['core' => 'tasks', 'rows' => [
            [['tasks', 8, 6], ['coming', 4, 6]],
            [['deliverables', 6, 4], ['waiting', 6, 4]],
        ]],
        'delivery' => ['core' => 'projects', 'rows' => [
            [['flow', 12, 2]],
            [['projects', 8, 5], ['milestones', 4, 5]],
            [['tasks', 6, 4], ['targets', 6, 4]],
            [['p-annual-plans', 6, 3], ['p-programs', 6, 3]],
        ]],
        'impact' => ['core' => 'targets', 'rows' => [
            [['targets', 7, 5], ['validate', 5, 5]],
            [['matrix', 12, 4]],
            [['templates', 4, 3], ['p-indicators', 4, 3], ['p-results', 4, 3]],
        ]],
        'fundraising' => ['core' => 'pipeline', 'rows' => [
            [['pipeline', 8, 5], ['reviews', 4, 5]],
            [['donors', 6, 4], ['needs', 6, 4]],
        ]],
        'team' => ['core' => 'myteam', 'rows' => [
            [['myteam', 7, 5], ['waiting', 5, 5]],
            [['tasks', 8, 4], ['needs', 4, 4]],
            [['milestones', 6, 3], ['deliverables', 6, 3]],
        ]],
        'overview' => ['core' => 'glance', 'rows' => [
            [['glance', 12, 2]],
            [['needs', 4, 4], ['flow', 8, 4]],
            [['money', 6, 4], ['projects', 6, 4]],
            [['matrix', 8, 4], ['targets', 4, 4]],
        ]],
    ];

    /**
     * What each panel holds, for the tint of its cell on a ready layout's mini map.
     *
     * @var array<string, 'money'|'people'|'work'|'results'>
     */
    private const array ReadyKinds = [
        'money' => 'money', 'transfers' => 'money', 'pipeline' => 'money', 'donors' => 'money',
        'myteam' => 'people', 'waiting' => 'people', 'reviews' => 'people',
        'needs' => 'work', 'coming' => 'work', 'overdue' => 'work', 'tasks' => 'work', 'deliverables' => 'work', 'projects' => 'work', 'flow' => 'work', 'milestones' => 'work', 'todo' => 'work',
        'targets' => 'results', 'validate' => 'results', 'matrix' => 'results', 'templates' => 'results', 'duties' => 'results', 'glance' => 'results',
    ];

    /**
     * The ready layout each role is suggested, closest role first. An
     * administrator gets the General Manager's, anyone else FallbackReady.
     *
     * @var array<string, string>
     */
    private const array ReadyFavourites = [
        'General Manager' => 'overview',
        'Public Relations Manager' => 'fundraising',
        'Public Relations' => 'donors',
        'MEAL' => 'impact',
        'Financial' => 'money',
        'Managers' => 'team',
        'Media Team' => 'tasks',
        'Team Member' => 'today',
    ];

    private const string FallbackReady = 'today';

    /**
     * A ready layout with fewer panels than this left is not offered.
     */
    private const int MinReadyPanels = 2;

    /**
     * The whole catalogue, before any viewer's permissions.
     *
     * @return array<string, array{core: string, rows: list<list<array{0: string, 1: int, 2: int}>>}>
     */
    public function readyCatalogue(): array
    {
        return self::ReadyLayouts;
    }

    /**
     * The ready layouts this viewer is offered, composed for what they may
     * see. A panel they may not see leaves its row: the row's last card widens
     * to the grid's edge and an empty row disappears, but no panel ever moves
     * to another row, so every row keeps one height. A layout goes when its
     * core panel is unavailable or fewer than MinReadyPanels are left.
     *
     * @return list<array{key: string, title: string, purpose: string, core: string, entries: list<array{panel: string, size: string, cols: int, rows: int}>}>
     */
    public function readyLayoutsFor(User $user): array
    {
        $available = $this->availableTo($user);
        $offered = [];

        foreach (self::ReadyLayouts as $key => $layout) {
            if (! isset($available[$layout['core']])) {
                continue;
            }

            $entries = [];

            foreach ($layout['rows'] as $row) {
                $kept = array_values(array_filter($row, fn (array $cell): bool => isset($available[$cell[0]])));

                foreach ($kept as $i => [$panel, $cols, $rows]) {
                    $cols += $i === array_key_last($kept) ? self::MaxCols - array_sum(array_column($kept, 1)) : 0;
                    $entries[] = $this->sized($panel, $this->nearestSize($cols), $cols, $rows);
                }
            }

            if (count($entries) >= self::MinReadyPanels) {
                $offered[] = [
                    'key' => $key,
                    'title' => (string) __("desk.ready.layouts.{$key}.title"),
                    'purpose' => (string) __("desk.ready.layouts.{$key}.purpose"),
                    'core' => $layout['core'],
                    'entries' => $entries,
                ];
            }
        }

        return $offered;
    }

    /**
     * Which ready layout the viewer's role leans on, among those offered:
     * the first offered when neither it nor the fallback is.
     *
     * @param  list<string>  $offered
     */
    public function favouriteReadyLayoutFor(User $user, array $offered): ?string
    {
        $roles = $user->getRoleNames()->all();
        $favourite = $user->isAdministrator() ? self::ReadyFavourites['General Manager'] : self::FallbackReady;

        if (! $user->isAdministrator()) {
            foreach (self::ReadyFavourites as $role => $key) {
                if (in_array($role, $roles, true)) {
                    $favourite = $key;

                    break;
                }
            }
        }

        return match (true) {
            in_array($favourite, $offered, true) => $favourite,
            in_array(self::FallbackReady, $offered, true) => self::FallbackReady,
            default => $offered[0] ?? null,
        };
    }

    /**
     * How the true rows of a layout span below 1280px, where a card's width
     * alone would break them (8 + 4 becomes 12 + 6, and the 6 then sits beside
     * a card of another height). A row is true when its two to four cards
     * total twelve columns and share one height: two cards halve the width, a
     * third takes the full width under them, and four make two halved lines.
     *
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>  $layout
     * @return array<string, int> panel => columns
     */
    public function narrowSpansOf(array $layout): array
    {
        $spans = [];

        foreach ($this->rowsIn($layout) as $row) {
            $isTrueRow = in_array(count($row), [2, 3, 4], true)
                && array_sum(array_column($row, 'cols')) === self::MaxCols
                && count(array_unique(array_column($row, 'rows'))) === 1;

            if ($isTrueRow) {
                foreach ($row as $i => $cell) {
                    $spans[$cell['panel']] = $i < 2 || count($row) === 4 ? 6 : self::MaxCols;
                }
            }
        }

        return $spans;
    }

    /**
     * What a panel holds, for its group in the Customize sheet's Add panels list.
     */
    public function readyKindOf(string $panel): string
    {
        return match (true) {
            PageCards::isCard($panel) => 'pages',
            str_starts_with($panel, Shortcuts::ActionPrefix) => 'actions',
            Shortcuts::isShortcut($panel) => 'buttons',
            default => self::ReadyKinds[$panel] ?? 'work',
        };
    }

    /**
     * Everything the given user may put on the Desk, keyed by id: the
     * hand-made panels in library order, then the page cards, then the
     * buttons (actions first, then pages). kind says which.
     *
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null, kind: 'panel'|'card'|'action'|'page'}>
     */
    public function availableTo(User $user): array
    {
        return $this->available[$this->memoKey($user)] ??= $this->buildAvailable($user);
    }

    /**
     * @var array<string, array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null, kind: 'panel'|'card'|'action'|'page'}>>
     */
    private array $available = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $routeItems = [];

    /**
     * Who is asking, as their access stands now: a role given or taken a permission in the
     * same request reads afresh.
     */
    private function memoKey(User $user): string
    {
        return $user->id.':'.md5($user->getAllPermissions()->pluck('name')->sort()->implode(',').'|'.$user->getRoleNames()->implode(','));
    }

    /**
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null, kind: 'panel'|'card'|'action'|'page'}>
     */
    private function buildAvailable(User $user): array
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
                'kind' => 'panel',
            ];
        }

        $cards = app(PageCards::class);
        $destinations = app(Destinations::class);

        foreach ($cards->routes() as $route) {
            $page = $destinations->find($route);

            if ($page === null || ! Route::has($route) || ! $cards->allows($user, $route)) {
                continue;
            }

            $id = PageCards::idFor($route);
            $describes = 'desk-workspace.card.about.'.Destinations::slugOf($route);
            $panels[$id] = [
                'id' => $id,
                'title' => $page['label'],
                'description' => Lang::has($describes) ? (string) __($describes) : (string) __('desk-workspace.card.about_any', ['page' => $page['label']]),
                'icon' => $cards->iconOf($route),
                'size' => 'L',
                'register' => $route,
                'kind' => 'card',
            ];
        }

        foreach (app(Shortcuts::class)->for($user) as $id => $button) {
            $panels[$id] = [
                'id' => $id,
                'title' => $button['label'],
                'description' => (string) __('desk-workspace.tile.about_'.$button['kind'], ['page' => $button['label']]),
                'icon' => $button['icon'],
                'size' => 'S',
                'register' => $button['route'],
                'kind' => $button['kind'],
            ];
        }

        return $panels;
    }

    /**
     * What stands for a page on this user's Desk: its hand-made panel, else
     * its page card, else a button that opens it; null when they may not
     * open the page. The sidebar's drag-and-drop and every page's "Add to
     * desk" read this, so a page always lands as the same thing.
     */
    public function itemForRoute(User $user, string $route): ?string
    {
        return $this->itemAmong($this->availableTo($user), $route);
    }

    /**
     * What stands for each page the user may open (itemForRoute), read in
     * one pass: route => item id, pages with nothing left out.
     *
     * @return array<string, string>
     */
    public function itemsForRoutes(User $user): array
    {
        return $this->routeItems[$this->memoKey($user)] ??= $this->buildItemsForRoutes($user);
    }

    /**
     * @return array<string, string>
     */
    private function buildItemsForRoutes(User $user): array
    {
        $available = $this->availableTo($user);
        $items = [];

        foreach (app(Destinations::class)->for($user) as $page) {
            $id = $this->itemAmong($available, $page['route']);

            if ($id !== null) {
                $items[$page['route']] = $id;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $available
     */
    private function itemAmong(array $available, string $route): ?string
    {
        $candidates = [self::RoutePanels[$route] ?? null, PageCards::idFor($route), Shortcuts::PagePrefix.Destinations::slugOf($route)];

        foreach ($candidates as $id) {
            if ($id !== null && isset($available[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The layout with one more item: at $position in the order (the end
     * when null), at the item's own size, read flush. Unchanged when the
     * item is already placed or not available to the user.
     *
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>  $layout
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
     */
    public function withItem(User $user, array $layout, string $id, ?int $position = null): array
    {
        $available = $this->availableTo($user);

        if (! isset($available[$id]) || in_array($id, array_column($layout, 'panel'), true)) {
            return $layout;
        }

        $entry = $this->sized($id, $available[$id]['size']);
        array_splice($layout, $position === null ? count($layout) : max(0, min($position, count($layout))), 0, [$entry]);

        return $this->flush(array_values($layout));
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
     * every panel they may not see (and every unknown entry) left out. Buttons
     * are never on it: they live in Quick access (QuickAccess::addItem).
     *
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
     */
    public function layoutFor(User $user): array
    {
        return is_array($user->desk_layout) ? $this->clean($this->withoutButtons($user->desk_layout), $user) : $this->startingLayoutFor($user);
    }

    /**
     * The button ids (Shortcuts, `a-…` / `g-…`) a stored Desk layout still
     * holds, in its order. The Desk keeps only panels and page cards; buttons
     * live in Quick access, and a Desk saved before that moves them there.
     *
     * @param  array<mixed>  $stored
     * @return list<string>
     */
    public function buttonsIn(array $stored): array
    {
        $buttons = [];

        foreach ($stored as $entry) {
            $panel = is_array($entry) ? ($entry['panel'] ?? null) : null;

            if (is_string($panel) && Shortcuts::isShortcut($panel) && ! in_array($panel, $buttons, true)) {
                $buttons[] = $panel;
            }
        }

        return $buttons;
    }

    /**
     * @param  array<mixed>  $stored
     * @return array<mixed>
     */
    private function withoutButtons(array $stored): array
    {
        return array_values(array_filter($stored, fn (mixed $entry): bool => ! (is_array($entry) && is_string($entry['panel'] ?? null) && Shortcuts::isShortcut($entry['panel']))));
    }

    /**
     * The role's starting layout as this user would see it (forbidden
     * panels left out), for the Customize sheet's Reset.
     *
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
     */
    public function startingLayoutFor(User $user): array
    {
        return $this->clean($this->defaultLayoutFor($user), $user);
    }

    /**
     * The panels behind the top bar's Panels button on one page (by route
     * name): the user's own set for that page, or the page's default.
     *
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
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
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>|null  $layout
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
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
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
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
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
            $size = is_string($size) && isset(self::Sizes[$size]) ? $size : $available[$panel]['size'];
            $layout[] = $this->sized($panel, $size, $entry['cols'] ?? null, $entry['rows'] ?? null);
        }

        return $this->flush($layout);
    }

    /**
     * The layout with no hole in it: its cards, in order, fill rows of
     * exactly twelve columns (a short row's cards share the spare columns,
     * fitRow()), and every card in a row takes the row's tallest height.
     * A layout that is already flush comes back unchanged.
     *
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>  $layout
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
     */
    public function flush(array $layout): array
    {
        $flushed = [];

        foreach ($this->rowsIn($layout) as $row) {
            $height = max(array_column($row, 'rows'));

            foreach ($this->fitRow(array_column($row, 'cols')) as $i => $cols) {
                $flushed[] = $this->sized($row[$i]['panel'], $row[$i]['size'], $cols, $height);
            }
        }

        return $flushed;
    }

    /**
     * Which Desk panels the person pinned to the top (in the order they
     * pinned them) and which they folded into a small tile, as saved on
     * users.desk_panel_flags. Anything malformed is dropped; panels no
     * longer on the Desk are left for the caller to filter.
     *
     * @return array{pinned: list<string>, folded: list<string>}
     */
    public function flagsFor(User $user): array
    {
        $stored = is_array($user->desk_panel_flags) ? $user->desk_panel_flags : [];
        $list = fn (string $key): array => array_values(array_unique(array_filter(is_array($stored[$key] ?? null) ? $stored[$key] : [], is_string(...))));

        return ['pinned' => $list('pinned'), 'folded' => $list('folded')];
    }

    /**
     * The layout with its pinned panels first, in the order they were
     * pinned, and every other panel after them in its own order. The cards
     * trade places, never shapes (as Move did): every slot keeps its size,
     * so pinning can never open a hole in the Desk.
     *
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>  $layout
     * @param  list<string>  $pinned
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
     */
    public function pinnedFirst(array $layout, array $pinned): array
    {
        $order = array_column($layout, 'panel');
        $first = array_values(array_intersect($pinned, $order));
        $wanted = [...$first, ...array_values(array_diff($order, $first))];

        if ($wanted === $order) {
            return $layout;
        }

        return array_map(fn (array $slot, string $panel): array => $this->sized($panel, $slot['size'], $slot['cols'], $slot['rows']), $layout, $wanted);
    }

    /**
     * The layout cut into rows, in order: a card that would pass the
     * twelfth column starts the next row.
     *
     * @template TEntry of array{cols: int}
     *
     * @param  list<TEntry>  $layout
     * @return list<non-empty-list<TEntry>>
     */
    public function rowsIn(array $layout): array
    {
        $rows = [];
        $row = [];

        foreach ($layout as $entry) {
            if ($row !== [] && array_sum(array_column($row, 'cols')) + $entry['cols'] > self::MaxCols) {
                $rows[] = $row;
                $row = [];
            }

            $row[] = $entry;
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One row's widths scaled to exactly twelve columns, in proportion and
     * never under MinCols: the spare column goes to the card that lost the
     * most in rounding, so 4 + 4 widens to 6 + 6 and 7 + 5 stays as it is.
     *
     * @param  list<int>  $cols
     * @return list<int>
     */
    public function fitRow(array $cols): array
    {
        $total = array_sum($cols);

        if ($total === self::MaxCols || $total === 0) {
            return $cols;
        }

        $shares = array_map(fn (int $width): float => $width * self::MaxCols / $total, $cols);
        $fitted = array_map(fn (float $share): int => max(self::MinCols, (int) floor($share)), $shares);

        while (array_sum($fitted) < self::MaxCols) {
            $gaps = array_map(fn (float $share, int $width): float => $share - $width, $shares, $fitted);
            $fitted[array_search(max($gaps), $gaps, true)]++;
        }

        while (array_sum($fitted) > self::MaxCols) {
            $over = array_map(fn (float $share, int $width): float => $width > self::MinCols ? $width - $share : -INF, $shares, $fitted);
            $fitted[array_search(max($over), $over, true)]--;
        }

        return $fitted;
    }

    /**
     * One layout entry with its exact cell size: stored cols/rows clamped to
     * the grid, or (older layouts, size-only entries) the size's columns and
     * the panel's own height. size always names the nearest S…XL step.
     *
     * @return array{panel: string, size: string, cols: int, rows: int}
     */
    public function sized(string $panel, string $size, mixed $cols = null, mixed $rows = null): array
    {
        $cols = is_numeric($cols) ? max(self::MinCols, min(self::MaxCols, (int) $cols)) : (self::Sizes[$size] ?? 4);
        $least = $this->minRowsOf($panel);
        $rows = is_numeric($rows) ? max($least, min(self::MaxRows, (int) $rows)) : max($least, min(self::MaxRows, $this->rowsOf($panel)));

        return ['panel' => $panel, 'size' => $this->nearestSize($cols), 'cols' => $cols, 'rows' => $rows];
    }

    /**
     * The S…XL step closest to a column count (ties go to the smaller one).
     */
    public function nearestSize(int $cols): string
    {
        $best = 'M';

        foreach (self::Sizes as $size => $columns) {
            if (abs($columns - $cols) < abs(self::Sizes[$best] - $cols)) {
                $best = $size;
            }
        }

        return $best;
    }

    /**
     * The layout of the first of the user's roles that has one.
     *
     * @return list<array{panel: string, size: string, cols: int, rows: int}>
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
     * How many grid row units (desk.css --row-unit) a panel is tall.
     */
    public function rowsOf(string $id): int
    {
        return Shortcuts::isShortcut($id) ? self::MinTileRows : ($this->definitions()[$id]['rows'] ?? self::DefaultRows);
    }

    /**
     * The fewest row units an item may be: a button one, anything else MinRows.
     */
    public function minRowsOf(string $id): int
    {
        return Shortcuts::isShortcut($id) ? self::MinTileRows : self::MinRows;
    }

    /**
     * The panel's icon (components/desk/icon), "grid" for an unknown id.
     */
    public function iconOf(string $id): string
    {
        $card = PageCards::isCard($id) ? app(PageCards::class)->routeOf($id) : null;

        return $card !== null ? app(PageCards::class)->iconOf($card) : ($this->definitions()[$id]['icon'] ?? 'grid');
    }

    /**
     * The "Start something" buttons this user may use.
     *
     * @return list<array{key: string, label: string, icon: string, href: string}>
     */
    public function quickActionsFor(User $user): array
    {
        $actions = [];

        foreach (self::QuickActions as $action) {
            if (! Route::has($action['route']) || ! $user->can($action['ability'])) {
                continue;
            }

            $actions[] = [
                'key' => $action['key'],
                'label' => (string) __("desk.start.{$action['key']}"),
                'icon' => $action['icon'],
                'href' => route($action['route'], ['quick' => 'create']),
            ];
        }

        return $actions;
    }

    /**
     * @return array<string, array{icon: string, size: string, rows?: int, register: string|null, allows: Closure(User): bool}>
     */
    private function definitions(): array
    {
        $everyone = fn (): bool => true;
        $can = fn (string $ability): Closure => fn (User $user): bool => $user->can($ability);

        return [
            'glance' => ['icon' => 'chart', 'size' => 'XL', 'rows' => 3, 'register' => null, 'allows' => $everyone],
            'flow' => ['icon' => 'chart', 'size' => 'XL', 'rows' => 2, 'register' => 'projects.index', 'allows' => $can('projects.view')],
            'todo' => ['icon' => 'check', 'size' => 'S', 'rows' => 4, 'register' => null, 'allows' => $everyone],
            'overdue' => ['icon' => 'flag', 'size' => 'L', 'rows' => 4, 'register' => null, 'allows' => $everyone],
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
            /* The Department Tasks surface in panel form, so it follows the page's rule (W-14, D-65). */
            'deliverables' => ['icon' => 'board', 'size' => 'M', 'register' => 'department-tasks.index', 'allows' => fn (User $user): bool => $user->can('viewDepartmentQueue', Task::class)],
            'myteam' => ['icon' => 'users', 'size' => 'M', 'register' => null, 'allows' => fn (User $user): bool => $user->employee_id !== null || $user->isAdministrator()],
            'targets' => ['icon' => 'chart', 'size' => 'L', 'register' => 'targets.index', 'allows' => $can('targets.view')],
            'duties' => ['icon' => 'file', 'size' => 'M', 'register' => 'donor-reports.index', 'allows' => fn (User $user): bool => $user->can('reports.aggregate') || $user->can('funding-agreements.update') || $user->hasReportingDuties()],
            'milestones' => ['icon' => 'flag', 'size' => 'M', 'register' => 'milestones.index', 'allows' => $can('milestones.view')],
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
