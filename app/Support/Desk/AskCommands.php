<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Everything the Ask bar can do without asking the server, shipped once with
 * the page (components/desk/⚡ask) and searched in the browser on every key
 * (resources/js/ask-index.js): every page the viewer may open, every "New …"
 * action they may take, what stands for each page on their Desk, the ready
 * layouts, and the verbs that turn a name into a command ("add … to desk",
 * "remove …", "new …", "open …", "switch to …", "close tabs").
 *
 * Each entry carries its normalised vocabulary in both languages, read from
 * AskEngine, so the instant results and the server's answers agree on what
 * a word means. The server still answers questions and finds records.
 */
final class AskCommands
{
    /**
     * Shortcuts action key => the AskEngine module whose names find it.
     *
     * @var array<string, string>
     */
    private const array ActionModules = [
        'task' => 'tasks', 'proposal' => 'proposals', 'project' => 'projects', 'milestone' => 'milestones', 'report' => 'reports',
        'donor' => 'donors', 'agreement' => 'funding-agreements', 'transfer' => 'transfers', 'risk' => 'risks', 'indicator' => 'indicators',
        'target' => 'targets', 'result' => 'results', 'annual-plan' => 'annual-plans', 'strategy' => 'strategies', 'account' => 'accounts',
        'library-entry' => 'indicator-library',
    ];

    /**
     * The words of each command verb, both languages (normalised when shipped).
     *
     * @var array<string, list<string>>
     */
    private const array Verbs = [
        'add' => ['add', 'pin', 'put', 'place', 'attach', 'keep', 'اضف', 'ضع', 'ثبت', 'اضافه', 'حط'],
        'desk' => ['desk', 'dashboard', 'مكتب', 'مكتبي', 'المكتب', 'لوحه', 'لوحتي'],
        'remove' => ['remove', 'unpin', 'delete', 'drop', 'hide', 'off', 'ازل', 'احذف', 'ازاله', 'اخف', 'شيل'],
        'open' => ['open', 'go', 'goto', 'show', 'view', 'visit', 'jump', 'take', 'افتح', 'اذهب', 'اعرض', 'انتقل', 'روح'],
        'tab' => ['tab', 'tabs', 'switch', 'تبويب', 'تبويبات', 'التبويب'],
        'close' => ['close', 'shut', 'اغلق', 'سكر'],
        'all' => ['all', 'every', 'everything', 'كل', 'جميع', 'الكل'],
        'card' => ['card', 'panel', 'widget', 'بطاقه', 'لوحه'],
        'button' => ['button', 'shortcut', 'link', 'quick', 'زر', 'اختصار', 'رابط', 'سريع', 'السريع'],
    ];

    /**
     * Built-in commands: id => the words that find it.
     *
     * @var array<string, list<string>>
     */
    private const array Commands = [
        'theme' => ['dark', 'light', 'theme', 'mode', 'night', 'day', 'appearance', 'colors', 'داكن', 'فاتح', 'وضع', 'مظهر', 'ليلي', 'نهاري', 'الوان'],
        'language' => ['arabic', 'english', 'language', 'lang', 'عربي', 'عربيه', 'انجليزي', 'انكليزي', 'لغه'],
        'close_tabs' => ['close', 'tabs', 'all', 'اغلق', 'تبويبات', 'كل'],
        'customize' => ['customize', 'customise', 'arrange', 'edit', 'organize', 'layout', 'panels', 'تخصيص', 'ترتيب', 'تعديل', 'تنظيم', 'لوحات'],
        'tour' => ['tour', 'help', 'guide', 'learn', 'how', 'جوله', 'مساعده', 'شرح', 'تعلم'],
        'desk' => ['desk', 'home', 'dashboard', 'back', 'مكتب', 'مكتبي', 'الرئيسيه', 'رجوع'],
    ];

    /**
     * Words that only join a command's parts ("add it to my desk as a button").
     *
     * @var list<string>
     */
    private const array ExtraStopWords = ['as', 'into', 'onto', 'it', 'them', 'me', 'my', 'mine', 'your', 'now', 'here', 'there', 'الى', 'لي', 'هنا', 'كـ'];

    public function __construct(
        private readonly Destinations $destinations,
        private readonly AskEngine $engine,
        private readonly Panels $panels,
        private readonly Shortcuts $shortcuts,
    ) {}

    /**
     * The index for one viewer: only what they may open, take or place.
     *
     * @return array{pages: list<array{route: string, label: string, group: string, icon: string, href: string, words: string, item: string|null, kind: string|null}>, actions: list<array{key: string, label: string, href: string, words: string, item: string, route: string}>, layouts: list<array{key: string, label: string, words: string}>, placed: list<string>, verbs: array<string, list<string>>, commands: array<string, string>, stop: list<string>}
     */
    public function for(User $user): array
    {
        /*
         * The index is the same on every page a person opens and took ~150ms to build, so it is kept
         * for ten minutes under a key made of exactly what it depends on: the pages they may open,
         * their roles and permissions, and the language. Any change of access gives a new key.
         * What is on their Desk changes with every drop, so it is always read fresh. It also lists the
         * buttons kept in Quick access (where the Desk's buttons live), so the Ask bar says they are there.
         */
        $fingerprint = md5(json_encode([
            array_column($this->destinations->for($user), 'route'),
            $user->getRoleNames()->sort()->values()->all(),
            $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            $user->employee_id,
        ]) ?: '');
        $key = 'desk-ask-index:'.$user->id.':'.app()->getLocale().':'.$fingerprint;

        return [
            ...Cache::remember($key, now()->addMinutes(10), fn (): array => $this->build($user)),
            'placed' => [...array_column($this->panels->layoutFor($user), 'panel'), ...app(QuickAccess::class)->itemIds($user)],
        ];
    }

    /**
     * @return array{pages: list<array<string, mixed>>, actions: list<array<string, mixed>>, layouts: list<array<string, string>>, verbs: array<string, list<string>>, commands: array<string, string>, stop: list<string>}
     */
    private function build(User $user): array
    {
        $library = $this->panels->availableTo($user);
        $items = $this->panels->itemsForRoutes($user);
        $groups = collect($this->destinations->sidebarFor($user))->pluck('label', 'group')->all();
        $pages = [];

        foreach ($this->destinations->for($user) as $page) {
            if (! Route::has($page['route'])) {
                continue;
            }

            $item = $items[$page['route']] ?? null;
            $pages[] = [
                'route' => $page['route'],
                'label' => $page['label'],
                'group' => (string) ($groups[$page['group']] ?? ''),
                'icon' => $page['icon'],
                'href' => route($page['route']),
                'words' => implode(' ', $this->engine->wordsForPage($page)),
                'item' => $item,
                'kind' => $item !== null ? $library[$item]['kind'] : null,
            ];
        }

        $creating = $this->engine->wordsForIntent('create');
        $actions = [];

        foreach ($this->shortcuts->actionsFor($user) as $id => $action) {
            $module = self::ActionModules[$action['key']] ?? null;
            $page = $this->destinations->find($action['route']);
            $words = $module !== null
                ? $this->engine->wordsForModule($module)
                : ($page !== null ? $this->engine->wordsForPage($page) : []);

            $actions[] = [
                'key' => $action['key'],
                'label' => $action['label'],
                'href' => $action['href'],
                'words' => implode(' ', array_unique([...AskEngine::normalizedWords([$action['label']]), ...$words, ...$creating])),
                'item' => $id,
                'route' => $action['route'],
            ];
        }

        $layouts = array_map(fn (array $layout): array => [
            'key' => $layout['key'],
            'label' => $layout['title'],
            'words' => implode(' ', AskEngine::normalizedWords([$layout['title'], $layout['purpose'], 'layout', 'template', 'تخطيط', 'قالب'])),
        ], $this->panels->readyLayoutsFor($user));

        return [
            'pages' => $pages,
            'actions' => $actions,
            'layouts' => $layouts,
            'verbs' => [...array_map(AskEngine::normalizedWords(...), self::Verbs), 'create' => $creating],
            'commands' => array_map(fn (array $words): string => implode(' ', AskEngine::normalizedWords($words)), self::Commands),
            'stop' => array_values(array_unique([...$this->engine->stopWords(), ...AskEngine::normalizedWords(self::ExtraStopWords)])),
        ];
    }
}
