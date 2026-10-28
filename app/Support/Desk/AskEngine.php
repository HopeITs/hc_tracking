<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Reads what someone typed into the Ask bar, in English or Arabic, with
 * typos: which question it asks (late, due today, this week, mine, waiting
 * for review, money, how many, create), which part of the system it is about
 * (projects, tasks, donors…), and which pages and "new …" actions match.
 *
 * It only interprets; the Ask bar (components/desk/⚡ask) runs the queries,
 * always through the viewer's permissions. The word lists are vocabulary to
 * recognise, never shown, so they live here rather than in lang files.
 */
final class AskEngine
{
    /**
     * Question words per intent, both languages, already normalised.
     *
     * @var array<string, list<string>>
     */
    private const array Intents = [
        'late' => ['late', 'overdue', 'behind', 'delayed', 'delay', 'stuck', 'missed', 'متاخر', 'متاخره', 'تاخر', 'تاخير', 'فائت', 'متعثر'],
        'today' => ['today', 'tonight', 'اليوم'],
        'week' => ['week', 'weekly', 'upcoming', 'soon', 'coming', 'next', 'due', 'deadline', 'deadlines', 'اسبوع', 'الاسبوع', 'قادم', 'قادمه', 'القادمه', 'قريبا', 'مستحق', 'مستحقه', 'موعد', 'مواعيد'],
        'mine' => ['my', 'mine', 'me', 'i', 'assigned', 'لي', 'خاصتي', 'مهامي', 'مشاريعي', 'مقترحاتي', 'تقاريري'],
        'reviews' => ['review', 'reviews', 'approve', 'approval', 'approvals', 'waiting', 'pending', 'signoff', 'مراجعه', 'مراجعات', 'موافقه', 'بانتظار', 'معلق'],
        'money' => ['money', 'funding', 'funds', 'fund', 'received', 'arrived', 'cash', 'budget', 'spent', 'مال', 'تمويل', 'مستلم', 'ميزانيه', 'اموال', 'مبالغ'],
        'count' => ['how', 'many', 'count', 'number', 'total', 'كم', 'عدد', 'مجموع'],
        'create' => ['new', 'add', 'create', 'جديد', 'جديده', 'اضف', 'اضافه', 'انشئ', 'انشاء'],
    ];

    /**
     * The words that name each part of the system (keys are register modules).
     *
     * @var array<string, list<string>>
     */
    private const array Entities = [
        'projects' => ['project', 'projects', 'proj', 'مشروع', 'مشاريع', 'مشاريعي'],
        'proposals' => ['proposal', 'proposals', 'application', 'applications', 'concept', 'مقترح', 'مقترحات', 'مقترحاتي', 'طلب'],
        'donors' => ['donor', 'donors', 'funder', 'funders', 'مانح', 'مانحين', 'مانحون', 'الجهات'],
        'tasks' => ['task', 'tasks', 'todo', 'todos', 'مهمه', 'مهام', 'مهامي'],
        'milestones' => ['milestone', 'milestones', 'deliverable', 'deliverables', 'مرحله', 'مراحل', 'معلم'],
        'risks' => ['risk', 'risks', 'issue', 'issues', 'خطر', 'مخاطر', 'مخاطره'],
        'transfers' => ['transfer', 'transfers', 'payment', 'payments', 'tranche', 'tranches', 'installment', 'تحويل', 'تحويلات', 'دفعه', 'دفعات'],
        'funding-agreements' => ['agreement', 'agreements', 'contract', 'contracts', 'grant', 'grants', 'mou', 'اتفاقيه', 'اتفاقيات', 'عقد', 'عقود', 'منحه'],
        'indicators' => ['indicator', 'indicators', 'kpi', 'kpis', 'مؤشر', 'مؤشرات'],
        'targets' => ['target', 'targets', 'هدف', 'اهداف', 'مستهدف', 'مستهدفات'],
        'reports' => ['report', 'reports', 'reading', 'readings', 'تقرير', 'تقارير', 'تقاريري'],
        'results' => ['result', 'results', 'logframe', 'framework', 'outcome', 'outcomes', 'نتيجه', 'نتائج', 'اطار'],
        'annual-plans' => ['plan', 'plans', 'annual', 'workplan', 'خطه', 'خطط', 'سنويه'],
        'strategies' => ['strategy', 'strategies', 'strategic', 'استراتيجيه', 'استراتيجيات'],
        'accounts' => ['account', 'accounts', 'bank', 'حساب', 'حسابات', 'بنك'],
        'indicator-library' => ['library', 'مكتبه'],
    ];

    /**
     * Each module's register and the permission that may create one there.
     *
     * @var array<string, array{route: string, create: string, label: string}>
     */
    private const array Creatable = [
        'proposals' => ['route' => 'proposals.index', 'create' => 'proposals.create', 'label' => 'desk.ask_new.proposal'],
        'projects' => ['route' => 'projects.index', 'create' => 'projects.create', 'label' => 'desk.ask_new.project'],
        'tasks' => ['route' => 'tasks.index', 'create' => 'tasks.create', 'label' => 'desk.ask_new.task'],
        'milestones' => ['route' => 'milestones.index', 'create' => 'milestones.create', 'label' => 'desk.ask_new.milestone'],
        'donors' => ['route' => 'donors.index', 'create' => 'donors.create', 'label' => 'desk.ask_new.donor'],
        'transfers' => ['route' => 'transfers.index', 'create' => 'transfers.create', 'label' => 'desk.ask_new.transfer'],
        'funding-agreements' => ['route' => 'funding-agreements.index', 'create' => 'funding-agreements.create', 'label' => 'desk.ask_new.agreement'],
        'risks' => ['route' => 'risks.index', 'create' => 'risks.create', 'label' => 'desk.ask_new.risk'],
        'indicators' => ['route' => 'indicators.index', 'create' => 'indicators.create', 'label' => 'desk.ask_new.indicator'],
        'targets' => ['route' => 'targets.index', 'create' => 'targets.create', 'label' => 'desk.ask_new.target'],
        'reports' => ['route' => 'reports.index', 'create' => 'reports.create', 'label' => 'desk.ask_new.report'],
        'results' => ['route' => 'results.index', 'create' => 'results.create', 'label' => 'desk.ask_new.result'],
        'annual-plans' => ['route' => 'annual-plans.index', 'create' => 'annual-plans.create', 'label' => 'desk.ask_new.annual_plan'],
        'strategies' => ['route' => 'strategies.index', 'create' => 'strategies.create', 'label' => 'desk.ask_new.strategy'],
        'accounts' => ['route' => 'admin.accounts.index', 'create' => 'accounts.create', 'label' => 'desk.ask_new.account'],
        'indicator-library' => ['route' => 'indicator-library.index', 'create' => 'indicator-library.create', 'label' => 'desk.ask_new.library_entry'],
    ];

    /**
     * Extra words that find a page besides its own name and its module's words.
     *
     * @var array<string, list<string>>
     */
    private const array PageWords = [
        'dashboard' => ['home', 'desk', 'start', 'main', 'الرئيسيه', 'مكتبي', 'المكتب'],
        'reviews.index' => ['review', 'reviews', 'approve', 'approvals', 'مراجعه', 'مراجعات'],
        'programs.index' => ['program', 'programs', 'programme', 'برنامج', 'برامج'],
        'timeline.index' => ['timeline', 'gantt', 'schedule', 'roadmap', 'جدول', 'زمني'],
        'reports.monthly-matrix' => ['matrix', 'monthly', 'مصفوفه', 'شهري'],
        'monthly-reports.index' => ['monthly', 'شهريه', 'شهري'],
        'donor-reports.compose' => ['builder', 'compose', 'donor', 'منشئ'],
        'reporting-duties.index' => ['duties', 'obligations', 'deadlines', 'التزامات', 'واجبات'],
        'department-tasks.index' => ['department', 'team', 'deliverables', 'قسم', 'الفريق'],
        'calendar.index' => ['calendar', 'schedule', 'agenda', 'تقويم', 'رزنامه'],
        'notifications.index' => ['notifications', 'inbox', 'alerts', 'messages', 'اشعارات', 'تنبيهات'],
        'admin.users.index' => ['users', 'people', 'staff', 'employees', 'roles', 'permissions', 'access', 'مستخدمين', 'موظفين', 'صلاحيات', 'ادوار'],
        'admin.notification-settings.edit' => ['rules', 'settings', 'قواعد', 'اعدادات'],
        'activity.index' => ['activity', 'log', 'history', 'audit', 'سجل', 'نشاط'],
        'admin.cities.index' => ['cities', 'city', 'locations', 'places', 'مدن', 'مدينه', 'مواقع'],
        'admin.sectors.index' => ['sectors', 'sector', 'قطاعات', 'قطاع'],
        'admin.evidence-types.index' => ['evidence', 'proof', 'شواهد', 'دليل'],
        'admin.narrative-questions.index' => ['narrative', 'questions', 'اسئله', 'سرديه'],
        'security.edit' => ['security', 'password', '2fa', 'twofactor', 'passkey', 'devices', 'امان', 'كلمه', 'مرور'],
        'notification-settings.edit' => ['preferences', 'notifications', 'settings', 'تفضيلات', 'اعدادات'],
    ];

    /**
     * Words that carry no meaning for matching.
     *
     * @var list<string>
     */
    private const array StopWords = [
        'what', 'whats', 'which', 'who', 'where', 'is', 'are', 'was', 'the', 'a', 'an', 'of', 'for', 'in', 'on', 'to', 'at', 'and', 'or',
        'show', 'list', 'find', 'open', 'go', 'get', 'see', 'view', 'all', 'please', 'any', 'there', 'do', 'have', 'has', 'this', 'that', 'with', 'by', 'about', 'page',
        'ما', 'ماذا', 'ماهي', 'من', 'في', 'على', 'الى', 'عن', 'هل', 'هي', 'هو', 'اعرض', 'اظهر', 'افتح', 'كل', 'جميع', 'هذا', 'هذه', 'صفحه', 'او', 'و',
    ];

    /**
     * Vocabulary words already normalised, so lists may be written naturally.
     *
     * @var array<string, string>
     */
    private static array $normalizedWords = [];

    public function __construct(private readonly Destinations $destinations) {}

    /**
     * What the typed text asks.
     *
     * @return array{normalized: string, words: list<string>, terms: list<string>, intents: list<string>, entity: string|null}
     */
    public function interpret(string $query): array
    {
        $normalized = self::normalize($query);
        $words = $normalized === '' ? [] : array_values(array_filter(explode(' ', $normalized), fn (string $word): bool => $word !== ''));
        $intents = [];
        $entity = null;
        $terms = [];

        foreach ($words as $word) {
            $matched = false;

            foreach (self::Intents as $intent => $vocabulary) {
                if (self::matchesAny($word, $vocabulary, strict: true)) {
                    $intents[$intent] = true;
                    $matched = true;
                }
            }

            foreach (self::Entities as $module => $vocabulary) {
                if (self::matchesAny($word, $vocabulary)) {
                    $entity ??= $module;
                    $matched = true;
                }
            }

            if (! $matched && ! self::isStopWord($word)) {
                $terms[] = $word;
            }
        }

        // "how" alone is a question word, not a count: "how many" / "كم" / "number" ask for one.
        if (isset($intents['count']) && ! array_intersect($words, ['many', 'count', 'number', 'total', 'كم', 'عدد', 'مجموع'])) {
            unset($intents['count']);
        }

        return [
            'normalized' => $normalized,
            'words' => $words,
            'terms' => $terms,
            'intents' => array_keys($intents),
            'entity' => $entity,
        ];
    }

    /**
     * The pages the text points at, best first; none when nothing matches.
     *
     * @param  array{normalized: string, words: list<string>, terms: list<string>, intents: list<string>, entity: string|null}  $interpretation
     * @return list<array{route: string, label: string, icon: string, hero: string, group: string, module: string|null}>
     */
    public function pages(User $user, array $interpretation, int $limit = 6): array
    {
        // Question words ("my", "late", "this week", "how many", "new") say what is asked, not where; review and money words do name pages.
        $questionWords = array_merge(...array_map(fn (string $intent): array => self::Intents[$intent], ['late', 'today', 'week', 'mine', 'count', 'create']));
        $words = array_values(array_filter(
            $interpretation['words'],
            fn (string $word): bool => ! self::isStopWord($word) && ! self::matchesAny($word, $questionWords, strict: true),
        ));

        if ($words === []) {
            return [];
        }

        $scored = [];

        foreach ($this->destinations->for($user) as $order => $page) {
            $label = self::normalize($page['label']);
            $vocabulary = array_merge(
                explode(' ', $label),
                self::Entities[$page['module'] ?? ''] ?? [],
                self::Entities[Str::before(Str::after($page['route'], 'admin.'), '.')] ?? [],
                self::PageWords[$page['route']] ?? [],
            );

            $hits = count(array_filter($words, fn (string $word): bool => self::matchesAny($word, $vocabulary)));
            $score = match (true) {
                str_contains($label, $interpretation['normalized']) => 100,
                $hits === count($words) => 70 + $hits,
                $hits > 0 => 30 + $hits,
                default => 0,
            };

            if ($score > 0) {
                $scored[] = ['score' => $score, 'order' => $order, 'page' => $page];
            }
        }

        usort($scored, fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_values(array_map(fn (array $row): array => $row['page'], array_slice($scored, 0, $limit)));
    }

    /**
     * "New …" actions the text asks for and the user may take. With a create
     * word and no module, every action they may take; with a module and no
     * create word, that module's action as a suggestion.
     *
     * @param  array{normalized: string, words: list<string>, terms: list<string>, intents: list<string>, entity: string|null}  $interpretation
     * @return list<array{module: string, label: string, href: string}>
     */
    public function actions(User $user, array $interpretation): array
    {
        $wantsCreate = in_array('create', $interpretation['intents'], true);
        $entity = $interpretation['entity'];

        if (! $wantsCreate && $entity === null) {
            return [];
        }

        $modules = $entity !== null ? [$entity] : array_keys(self::Creatable);
        $actions = [];

        foreach ($modules as $module) {
            $action = self::Creatable[$module] ?? null;

            if ($action === null || ! $user->can($action['create'])) {
                continue;
            }

            $actions[] = [
                'module' => $module,
                'label' => __($action['label']),
                'href' => route($action['route'], ['quick' => 'create']),
            ];
        }

        // A bare module name ("tasks") offers its action after the answers, never instead of them.
        return $wantsCreate ? $actions : array_slice($actions, 0, 1);
    }

    /**
     * Lower case, without Arabic diacritics, tatweel or letter variants
     * (أ إ آ → ا, ة → ه, ى → ي), the definite article "ال" dropped from long
     * words, punctuation turned to spaces.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', '’' => '', "'" => '']);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $words = array_map(
            fn (string $word): string => mb_strlen($word) > 4 && str_starts_with($word, 'ال') ? mb_substr($word, 2) : $word,
            array_filter(explode(' ', $text), fn (string $word): bool => $word !== ''),
        );

        return implode(' ', $words);
    }

    /**
     * Whether a typed word is one of the vocabulary words: exactly, as the
     * start of one while typing (3+ letters), or within a typo (one edit from
     * 4 letters, two from 7). Strict matching (intent words) skips prefixes,
     * so "new" does not catch "news".
     *
     * @param  list<string>  $vocabulary
     */
    public static function matchesAny(string $word, array $vocabulary, bool $strict = false): bool
    {
        $length = mb_strlen($word);

        foreach ($vocabulary as $candidate) {
            $candidate = self::$normalizedWords[$candidate] ??= self::normalize($candidate);

            if ($word === $candidate) {
                return true;
            }

            if (! $strict && $length >= 3 && mb_strlen($candidate) > $length && str_starts_with($candidate, $word)) {
                return true;
            }

            $allowed = $length >= 7 ? 2 : ($length >= 4 ? 1 : 0);

            if ($allowed > 0 && abs(mb_strlen($candidate) - $length) <= $allowed && self::distance($word, $candidate) <= $allowed) {
                return true;
            }
        }

        return false;
    }

    private static function isStopWord(string $word): bool
    {
        static $stopWords = null;
        $stopWords ??= array_flip(array_map(self::normalize(...), self::StopWords));

        return isset($stopWords[$word]);
    }

    /**
     * Levenshtein distance by character, so Arabic letters count as one edit.
     */
    private static function distance(string $a, string $b): int
    {
        $left = mb_str_split($a);
        $right = mb_str_split($b);
        $previous = range(0, count($right));

        foreach ($left as $i => $leftChar) {
            $current = [$i + 1];

            foreach ($right as $j => $rightChar) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($leftChar === $rightChar ? 0 : 1));
            }

            $previous = $current;
        }

        return $previous[count($right)];
    }
}
