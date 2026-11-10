<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\EvidenceType;
use App\Models\NarrativeQuestion;
use App\Models\RiskLibrary;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The Desk's buttons: every "New …" action and every page, each one a small
 * tile the viewer can put on the Desk ("Add to desk"), find in the Ask bar,
 * or drag in from the sidebar.
 *
 * An action opens its register with ?quick=create, which presses that page's
 * own create button (data-quick-create, resources/js/desk-ask.js), so the
 * usual form, validation and permissions apply. A page button opens the page.
 * Both are permission-filtered here and again by the page they open.
 *
 * Desk item ids: "a-<action>" for an action, "g-<page slug>" for a page
 * (Destinations::slugOf). Both are selector-safe.
 */
final class Shortcuts
{
    public const string ActionPrefix = 'a-';

    public const string PagePrefix = 'g-';

    /**
     * Every "New …" action: its register, the right to create there (an
     * ability, or a policy's create on a model), its Flux icon and its label.
     *
     * @var array<string, array{route: string, ability: string, model?: class-string, view?: string, icon: string, label: string}>
     */
    private const array Actions = [
        'task' => ['route' => 'tasks.index', 'ability' => 'tasks.create', 'icon' => 'clipboard-document-check', 'label' => 'desk.ask_new.task'],
        'proposal' => ['route' => 'proposals.index', 'ability' => 'proposals.create', 'icon' => 'document-plus', 'label' => 'desk.ask_new.proposal'],
        'project' => ['route' => 'projects.index', 'ability' => 'projects.create', 'icon' => 'folder-plus', 'label' => 'desk.ask_new.project'],
        'milestone' => ['route' => 'milestones.index', 'ability' => 'milestones.create', 'icon' => 'flag', 'label' => 'desk.ask_new.milestone'],
        'report' => ['route' => 'reports.index', 'ability' => 'reports.create', 'icon' => 'document-chart-bar', 'label' => 'desk.ask_new.report'],
        'donor' => ['route' => 'donors.index', 'ability' => 'donors.create', 'icon' => 'gift', 'label' => 'desk.ask_new.donor'],
        'agreement' => ['route' => 'funding-agreements.index', 'ability' => 'funding-agreements.create', 'icon' => 'document-currency-dollar', 'label' => 'desk.ask_new.agreement'],
        'transfer' => ['route' => 'transfers.index', 'ability' => 'transfers.create', 'icon' => 'banknotes', 'label' => 'desk.ask_new.transfer'],
        'risk' => ['route' => 'risks.index', 'ability' => 'risks.create', 'view' => 'risks.view', 'icon' => 'exclamation-triangle', 'label' => 'desk.ask_new.risk'],
        'indicator' => ['route' => 'indicators.index', 'ability' => 'indicators.create', 'icon' => 'chart-bar', 'label' => 'desk.ask_new.indicator'],
        'target' => ['route' => 'targets.index', 'ability' => 'targets.create', 'icon' => 'viewfinder-circle', 'label' => 'desk.ask_new.target'],
        'result' => ['route' => 'results.index', 'ability' => 'results.create', 'icon' => 'trophy', 'label' => 'desk.ask_new.result'],
        'annual-plan' => ['route' => 'annual-plans.index', 'ability' => 'annual-plans.create', 'icon' => 'calendar-date-range', 'label' => 'desk.ask_new.annual_plan'],
        'strategy' => ['route' => 'strategies.index', 'ability' => 'strategies.create', 'icon' => 'light-bulb', 'label' => 'desk.ask_new.strategy'],
        'account' => ['route' => 'admin.accounts.index', 'ability' => 'accounts.create', 'icon' => 'building-library', 'label' => 'desk.ask_new.account'],
        'library-entry' => ['route' => 'indicator-library.index', 'ability' => 'indicator-library.create', 'icon' => 'book-open', 'label' => 'desk.ask_new.library_entry'],
        'evidence-type' => ['route' => 'admin.evidence-types.index', 'ability' => 'create', 'model' => EvidenceType::class, 'icon' => 'paper-clip', 'label' => 'desk-workspace.action.evidence_type'],
        'sector' => ['route' => 'admin.sectors.index', 'ability' => 'create', 'model' => Sector::class, 'icon' => 'tag', 'label' => 'desk-workspace.action.sector'],
        'narrative-question' => ['route' => 'admin.narrative-questions.index', 'ability' => 'create', 'model' => NarrativeQuestion::class, 'icon' => 'question-mark-circle', 'label' => 'desk-workspace.action.narrative_question'],
        'risk-entry' => ['route' => 'admin.risk-library.index', 'ability' => 'create', 'model' => RiskLibrary::class, 'icon' => 'shield-exclamation', 'label' => 'desk-workspace.action.risk_entry'],
        'user' => ['route' => 'admin.users.index', 'ability' => 'create', 'model' => User::class, 'icon' => 'user-plus', 'label' => 'desk-workspace.action.user'],
    ];

    public function __construct(private readonly Destinations $destinations) {}

    /**
     * Every action the user may take, keyed by its Desk item id.
     *
     * @return array<string, array{id: string, key: string, kind: 'action', label: string, icon: string, route: string, href: string}>
     */
    public function actionsFor(User $user): array
    {
        $actions = [];

        foreach (self::Actions as $key => $action) {
            if (! $this->mayTake($user, $key)) {
                continue;
            }

            $actions[self::ActionPrefix.$key] = [
                'id' => self::ActionPrefix.$key,
                'key' => $key,
                'kind' => 'action',
                'label' => (string) __($action['label']),
                'icon' => $action['icon'],
                'route' => $action['route'],
                'href' => route($action['route'], ['quick' => 'create']),
            ];
        }

        return $actions;
    }

    /**
     * Every page the user may open as a Desk button, keyed by its Desk item id.
     * The Desk itself is not one: it is where the buttons live.
     *
     * @return array<string, array{id: string, key: string, kind: 'page', label: string, icon: string, route: string, href: string}>
     */
    public function pagesFor(User $user): array
    {
        $pages = [];

        foreach ($this->destinations->for($user) as $page) {
            if ($page['route'] === 'dashboard' || ! Route::has($page['route'])) {
                continue;
            }

            $id = self::PagePrefix.Destinations::slugOf($page['route']);
            $pages[$id] = [
                'id' => $id,
                'key' => Destinations::slugOf($page['route']),
                'kind' => 'page',
                'label' => $page['label'],
                'icon' => $page['hero'],
                'route' => $page['route'],
                'href' => route($page['route']),
            ];
        }

        return $pages;
    }

    /**
     * Every button (action or page) the user may put on the Desk.
     *
     * @return array<string, array{id: string, key: string, kind: 'action'|'page', label: string, icon: string, route: string, href: string}>
     */
    public function for(User $user): array
    {
        return [...$this->actionsFor($user), ...$this->pagesFor($user)];
    }

    /**
     * One button by its Desk item id, if the user may use it.
     *
     * @return array{id: string, key: string, kind: 'action'|'page', label: string, icon: string, route: string, href: string}|null
     */
    public function find(User $user, string $id): ?array
    {
        return $this->for($user)[$id] ?? null;
    }

    /**
     * The action that creates in a register, by the register's route, if the user may take it.
     *
     * @return array{id: string, key: string, kind: 'action', label: string, icon: string, route: string, href: string}|null
     */
    public function actionForRoute(User $user, string $route): ?array
    {
        foreach ($this->actionsFor($user) as $action) {
            if ($action['route'] === $route) {
                return $action;
            }
        }

        return null;
    }

    public static function isShortcut(string $id): bool
    {
        return str_starts_with($id, self::ActionPrefix) || str_starts_with($id, self::PagePrefix);
    }

    /**
     * Whether the user may take an action: its register exists, they may
     * open it (by the sidebar's rule, for a register the sidebar lists; the
     * register's own route checks the rest), and they hold the right to
     * create there.
     */
    private function mayTake(User $user, string $key): bool
    {
        $action = self::Actions[$key];

        if (! Route::has($action['route'])) {
            return false;
        }

        if ($this->destinations->find($action['route']) !== null && ! $this->destinations->allows($user, $action['route'])) {
            return false;
        }

        // A register outside the sidebar names the right to open it here.
        if (isset($action['view']) && ! $user->can($action['view'])) {
            return false;
        }

        return isset($action['model'])
            ? $user->can($action['ability'], $action['model'])
            : $user->can($action['ability']);
    }
}
