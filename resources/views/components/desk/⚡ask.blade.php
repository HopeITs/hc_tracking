<?php

use App\Enums\TaskStatus;
use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Support\Desk\AskEngine;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Ask bar: one box that answers questions ("what is late?", "due today",
 * "my tasks this week", "waiting for my review", "money", "how many
 * projects?"), takes actions ("new proposal" opens that register's create
 * form), and finds records and pages, in English or Arabic, with typos.
 * AskEngine reads the text; the answers come from the same queries the Desk
 * panels read, so an answer never shows a row its panel would hide.
 *
 * config('desk.ask_ai') reserves an "Ask AI" fallback row for later; it is
 * off, and nothing here calls an AI service.
 */
new class extends Component {
    use ChartsDashboard;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * Results per group; the palette is a jump list, not a register.
     */
    private const GroupLimit = 6;

    /**
     * Attention / upcoming row key prefix per module, to narrow an answer ("late tasks").
     *
     * @var array<string, string>
     */
    private const KeyPrefixes = ['tasks' => 'task-', 'milestones' => 'milestone-', 'transfers' => 'transfer-', 'risks' => 'risk-', 'reports' => 'report-', 'proposals' => 'review-'];

    public string $query = '';

    /**
     * Answers, then groups of rows, for what was typed; suggestions when it is empty.
     *
     * @return array{answers: list<array{title: string, body: string}>, groups: list<array{name: string, items: list<array{icon: string, label: string, sub: string, href: string, action?: bool}>}>, suggestions: list<string>, ai: bool}
     */
    #[Computed]
    public function results(): array
    {
        $user = auth()->user();
        $ask = app(AskEngine::class);
        $meaning = $ask->interpret($this->query);
        $intents = $meaning['intents'];
        $entity = $meaning['entity'];
        $answers = [];
        $groups = [];

        if ($meaning['normalized'] === '') {
            return ['answers' => [], 'groups' => [], 'suggestions' => $this->suggestions(), 'ai' => false];
        }

        $actions = array_map(fn (array $action): array => ['icon' => 'plus', 'label' => $action['label'], 'sub' => __('desk.ask_action_hint'), 'href' => $action['href'], 'action' => true], $ask->actions($user, $meaning));
        $wantsCreate = in_array('create', $intents, true);

        if ($wantsCreate && $actions !== []) {
            $groups[] = ['name' => __('desk.ask_group_do'), 'items' => $actions];
        }

        if (in_array('late', $intents, true)) {
            $late = $this->narrow($this->attentionItems->filter(fn (array $item): bool => ($item['daysLate'] ?? 0) > 0), $entity)->sortByDesc('daysLate')->values();
            $answers[] = ['title' => trans_choice('desk.ask_late_title', $late->count(), ['count' => $late->count()]), 'body' => __('desk.ask_late_body')];
            $groups[] = ['name' => __('desk.ask_group_answer'), 'items' => $late->take(12)->map(fn (array $item): array => ['icon' => 'info', 'label' => $item['title'], 'sub' => $item['context'].' · '.$item['badge'], 'href' => $item['href']])->all()];
        } elseif ($entity === 'tasks' && in_array('mine', $intents, true) || ($entity === null && in_array('mine', $intents, true) && ! in_array('reviews', $intents, true))) {
            $window = in_array('today', $intents, true) ? 'today' : (in_array('week', $intents, true) ? 'week' : null);
            $tasks = $this->myTasks($window);
            $answers[] = ['title' => trans_choice('desk.ask_my_tasks_title.'.($window ?? 'open'), $tasks->count(), ['count' => $tasks->count()]), 'body' => __('desk.ask_my_tasks_body')];
            $groups[] = ['name' => __('desk.ask_group_answer'), 'items' => $tasks->take(12)->map(fn (Task $task): array => [
                'icon' => 'check',
                'label' => $task->title,
                'sub' => $task->due_date !== null ? __('desk.ask_due_on', ['date' => $task->due_date->translatedFormat(__('desk.date_short'))]) : __('desk.ask_no_due_date'),
                'href' => route('tasks.show', $task),
            ])->all()];
        } elseif (in_array('today', $intents, true) || in_array('week', $intents, true)) {
            $window = in_array('today', $intents, true) ? 'today' : 'week';
            $until = $window === 'today' ? CarbonImmutable::today() : CarbonImmutable::today()->addDays(7);
            $due = $this->narrow($this->upcoming->filter(fn (array $item): bool => $item['date'] !== null && $item['date']->lessThanOrEqualTo($until)), $entity)->sortBy('date')->values();
            $answers[] = ['title' => trans_choice('desk.ask_due_title.'.$window, $due->count(), ['count' => $due->count()]), 'body' => __('desk.ask_due_body')];
            $groups[] = ['name' => __('desk.ask_group_answer'), 'items' => $due->take(12)->map(fn (array $item): array => ['icon' => 'cal', 'label' => $item['title'], 'sub' => $item['context'].' · '.$item['date']->translatedFormat(__('desk.date_short')), 'href' => $item['href']])->all()];
        }

        if (in_array('reviews', $intents, true)) {
            $reviews = $this->attentionItems->filter(fn (array $item): bool => str_starts_with($item['key'], 'review-'))->values();
            $answers[] = ['title' => trans_choice('desk.ask_reviews_title', $reviews->count(), ['count' => $reviews->count()]), 'body' => __('desk.ask_reviews_body')];
            $groups[] = ['name' => __('desk.ask_group_reviews'), 'items' => $reviews->take(12)->map(fn (array $item): array => ['icon' => 'search', 'label' => $item['title'], 'sub' => $item['context'], 'href' => $item['href']])->all()];
        }

        if (in_array('money', $intents, true) && $this->canViewFundingPosition()) {
            foreach ($this->fundingPosition as $line) {
                $answers[] = [
                    'title' => __('desk.ask_money_title', [
                        'received' => Number::format($line['received'], maxPrecision: 0),
                        'committed' => Number::format($line['committed'], maxPrecision: 0),
                        'currency' => $line['currency'],
                    ]),
                    'body' => $line['share'] === null ? '' : __('desk.ask_money_body', ['share' => $line['share'], 'currency' => $line['currency']]),
                ];
            }
        }

        if (in_array('count', $intents, true) && $entity !== null) {
            $count = $this->countOf($entity, in_array('mine', $intents, true));

            if ($count !== null) {
                $answers[] = ['title' => __('desk.ask_count_title', ['count' => Number::format($count), 'things' => __('desk.ask_things.'.$entity)]), 'body' => __('desk.ask_count_body')];
            }
        }

        $terms = implode(' ', $meaning['terms']);

        if (mb_strlen($terms) >= 2) {
            foreach ($this->records($terms, $entity) as $group) {
                $groups[] = $group;
            }
        }

        if (! $wantsCreate && $actions !== []) {
            $groups[] = ['name' => __('desk.ask_group_do'), 'items' => $actions];
        }

        $pages = array_map(fn (array $page): array => ['icon' => $page['icon'], 'label' => $page['label'], 'sub' => '', 'href' => route($page['route'])], $ask->pages($user, $meaning));

        if ($pages !== []) {
            $groups[] = ['name' => __('desk.ask_group_go'), 'items' => $pages];
        }

        $groups = array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));

        return ['answers' => $answers, 'groups' => $groups, 'suggestions' => $answers === [] && $groups === [] ? $this->suggestions() : [], 'ai' => (bool) config('desk.ask_ai', false)];
    }

    /**
     * Example questions for an empty box, each one the viewer can use.
     *
     * @return list<string>
     */
    private function suggestions(): array
    {
        $user = auth()->user();

        return array_values(array_filter([
            __('desk.ask_suggest.late'),
            __('desk.ask_suggest.today'),
            $user->can('tasks.view') ? __('desk.ask_suggest.my_tasks') : null,
            $this->attentionItems->contains(fn (array $item): bool => str_starts_with($item['key'], 'review-')) ? __('desk.ask_suggest.reviews') : null,
            $this->canViewFundingPosition() ? __('desk.ask_suggest.money') : null,
            $user->can('tasks.create') ? __('desk.ask_suggest.new_task') : null,
            $user->can('projects.view') ? __('desk.ask_suggest.count_projects') : null,
        ]));
    }

    /**
     * Keep only the rows about one module ("late tasks"), or all of them.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function narrow(Collection $items, ?string $entity): Collection
    {
        $prefix = $entity === null ? null : (self::KeyPrefixes[$entity] ?? null);

        return $prefix === null ? $items : $items->filter(fn (array $item): bool => str_starts_with((string) $item['key'], $prefix));
    }

    /**
     * The viewer's own open tasks, soonest first, optionally due by today or within a week.
     *
     * @return Collection<int, Task>
     */
    private function myTasks(?string $window): Collection
    {
        $employeeId = auth()->user()->employee_id;

        if ($employeeId === null || ! $this->canView('tasks')) {
            return new Collection;
        }

        return Task::query()
            ->whereNull('parent_task_id')
            ->where('status', '!=', TaskStatus::Done)
            ->whereHas('taskAssignees', fn (Builder $assignee) => $assignee->where('employee_id', $employeeId))
            ->when($window === 'today', fn (Builder $query) => $query->whereDate('due_date', '<=', CarbonImmutable::today()))
            ->when($window === 'week', fn (Builder $query) => $query->whereDate('due_date', '<=', CarbonImmutable::today()->addDays(7)))
            ->orderByRaw('due_date is null, due_date')
            ->limit(20)
            ->get(['id', 'slug', 'title', 'due_date']);
    }

    /**
     * How many rows of a module the viewer may see; null for modules without a count.
     */
    private function countOf(string $module, bool $mine): ?int
    {
        $query = match ($module) {
            'projects' => Project::query(),
            'proposals' => Proposal::query(),
            'donors' => Donor::query(),
            'tasks' => Task::query()->whereNull('parent_task_id')->where('status', '!=', TaskStatus::Done)
                ->when($mine, fn (Builder $query) => $query->whereHas('taskAssignees', fn (Builder $assignee) => $assignee->where('employee_id', auth()->user()->employee_id ?? 0))),
            'milestones' => Milestone::query(),
            'risks' => Risk::query(),
            'transfers' => TransfersTracking::query(),
            'indicators' => Indicator::query(),
            'annual-plans' => AnnualPlan::query(),
            'strategies' => Strategy::query(),
            default => null,
        };

        return $query === null ? null : $this->visible($query, $module)->count();
    }

    /**
     * Records whose name holds every typed word, per module the viewer may
     * view; only the named module when the question names one.
     *
     * @return list<array{name: string, items: list<array{icon: string, label: string, sub: string, href: string}>}>
     */
    private function records(string $terms, ?string $entity): array
    {
        $sources = [
            'projects' => [Project::query(), 'name', 'folder', __('common.projects'), fn (Project $project): array => ['label' => $project->name, 'sub' => __('common.projects'), 'href' => route('projects.show', $project)]],
            'proposals' => [Proposal::query(), 'name', 'file', __('common.proposals'), fn (Proposal $proposal): array => ['label' => $proposal->name, 'sub' => $proposal->status->label(), 'href' => route('proposals.show', $proposal)]],
            'donors' => [Donor::query(), 'name', 'users', __('common.donors'), fn (Donor $donor): array => ['label' => $donor->name, 'sub' => __('common.donors'), 'href' => route('donors.show', $donor)]],
            'tasks' => [Task::query()->whereNull('parent_task_id'), 'title', 'check', __('common.tasks'), fn (Task $task): array => ['label' => $task->title, 'sub' => $task->status->label(), 'href' => route('tasks.show', $task)]],
            'milestones' => [Milestone::query(), 'name', 'flag', __('common.milestones'), fn (Milestone $milestone): array => ['label' => $milestone->name, 'sub' => __('common.milestones'), 'href' => route('milestones.show', $milestone)]],
            'risks' => [Risk::query(), 'name', 'info', __('common.risks'), fn (Risk $risk): array => ['label' => $risk->name, 'sub' => __('common.risks'), 'href' => route('risks.index')]],
        ];

        $groups = [];

        foreach ($sources as $module => [$query, $column, $icon, $name, $row]) {
            if (($entity !== null && $entity !== $module) || ! $this->canView($module)) {
                continue;
            }

            foreach (preg_split('/\s+/u', $terms) ?: [] as $word) {
                $query->where($column, 'like', Like::contains(Like::term($word)));
            }

            $groups[] = ['name' => $name, 'items' => $query->orderBy($column)->limit(self::GroupLimit)->get()
                ->map(fn (Model $model): array => ['icon' => $icon, ...$row($model)])->all()];
        }

        return $groups;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function visible(Builder $query, string $module): Builder
    {
        return $this->canView($module) ? $query : $query->whereRaw('1 = 0');
    }

    protected function canView(string $module): bool
    {
        return auth()->user()?->can("{$module}.view") === true;
    }

    protected function canViewFundingPosition(): bool
    {
        return $this->canView('funding-agreements') && $this->canView('transfers');
    }
};
?>

@php
    $results = $this->results;
    $index = 0;
@endphp

<div
    x-data="hcDeskAsk"
    x-on:dk-ask-open.window="show()"
    x-on:keydown.window="onGlobalKey($event)"
    data-desk-ask-palette
>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="dk-scope">
            <div class="dk-pal-scrim" x-on:click="hide()"></div>
            <div class="dk-pal" role="dialog" aria-modal="true" aria-label="{{ __('desk.ask_placeholder') }}" x-on:keydown.escape.stop="hide()">
                <div class="dk-pal-in">
                    <x-desk.icon name="search" />
                    <label class="dk-sr" for="dk-pal-q">{{ __('desk.search') }}</label>
                    <input
                        id="dk-pal-q"
                        x-ref="input"
                        autocomplete="off"
                        wire:model.live.debounce.150ms="query"
                        placeholder="{{ __('desk.ask_hint') }}"
                        x-on:keydown.arrow-down.prevent="move(1)"
                        x-on:keydown.arrow-up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="choose()"
                    >
                    <span class="dk-pal-busy" wire:loading.delay wire:target="query" aria-hidden="true"></span>
                    <kbd>{{ __('desk.key_esc') }}</kbd>
                </div>

                <div class="dk-pal-list" x-ref="list">
                    @if ($results['suggestions'] !== [])
                        <div class="dk-pal-try" data-ask-suggestions>
                            <p>{{ $results['groups'] === [] && $results['answers'] === [] && trim($query) !== '' ? __('desk.ask_nothing_title') : __('desk.ask_try') }}</p>
                            @if (trim($query) !== '')
                                <span class="dk-is">{{ __('desk.ask_nothing_body') }}</span>
                            @endif
                            <div class="dk-pal-chips">
                                @foreach ($results['suggestions'] as $suggestion)
                                    <button type="button" class="dk-chip" data-pi="{{ $index }}" x-bind:class="{ 'dk-sel': selected === {{ $index }} }" x-on:click="ask(@js($suggestion))">{{ $suggestion }}</button>
                                    @php
                                        $index++;
                                    @endphp
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @foreach ($results['answers'] as $answer)
                        <div class="dk-answer" data-ask-answer><strong>{{ $answer['title'] }}</strong>{{ $answer['body'] }}</div>
                    @endforeach

                    @foreach ($results['groups'] as $group)
                        <div class="dk-pal-g">{{ $group['name'] }}</div>
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['href'] }}" wire:navigate @class(['dk-pal-i', 'dk-pal-act' => $item['action'] ?? false]) data-pi="{{ $index }}" x-bind:class="{ 'dk-sel': selected === {{ $index }} }" x-on:click="hide()" @if ($item['action'] ?? false) data-ask-action @endif>
                                <span class="dk-ic"><x-desk.icon :name="$item['icon']" /></span>
                                <span>{{ $item['label'] }}@if ($item['sub'] !== '')<small>{{ $item['sub'] }}</small>@endif</span>
                            </a>
                            @php
                                $index++;
                            @endphp
                        @endforeach
                    @endforeach

                    @if ($results['ai'] && trim($query) !== '')
                        {{-- Reserved for a later AI fallback (config desk.ask_ai); off today. --}}
                        <div class="dk-pal-g">{{ __('desk.ask_group_ai') }}</div>
                        <span class="dk-pal-i" aria-disabled="true" data-ask-ai><span class="dk-ic"><x-desk.icon name="spark" /></span><span>{{ __('desk.ask_ai', ['question' => $query]) }}</span></span>
                    @endif
                </div>

                <div class="dk-pal-foot">
                    <span><kbd>↑</kbd> <kbd>↓</kbd> {{ __('desk.ask_move') }}</span>
                    <span><kbd>{{ __('desk.key_enter') }}</kbd> {{ __('desk.ask_open') }}</span>
                </div>
            </div>
        </div>
    </template>
</div>
