<?php

use App\Actions\Supervisions\AddSupervision;
use App\Actions\Supervisions\RemoveSupervision;
use App\Enums\Locale;
use App\Models\Supervision;
use App\Notifications\EmployeeRecipients;
use App\Support\SupervisionMap;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The Supervision tab of Users & Roles (T23, contracts §10, D-13): an org
 * tree of the HR direct-manager links (read-only) and the local links General
 * Manager adds or removes, a side panel for the selected supervisor, and the
 * active staff nobody supervises. Supervision routes reminders and
 * escalations; it grants no permission. Every method re-checks supervisions.manage.
 */
new class extends Component {
    public string $search = '';

    /** all | hr | added */
    public string $source = 'all';

    public bool $showAddModal = false;

    public string $supervisorEmployeeId = '';

    /** @var list<string> */
    public array $superviseeEmployeeIds = [];

    public bool $showRemoveModal = false;

    #[Locked]
    public ?int $removingId = null;

    public function mount(): void
    {
        Gate::authorize('manage', Supervision::class);
    }

    public function updatedSource(string $value): void
    {
        if (! in_array($value, ['all', 'hr', 'added'], true)) {
            $this->source = 'all';
        }
    }

    /**
     * The tree the view draws: every person in a link keyed by employee id,
     * the top-level supervisors, the active staff linked to nobody, and which
     * people the search found (null when not searching).
     *
     * @return array{
     *     nodes: array<int, array{id: int, name: string, initials: string, inactive: bool, children: list<array{id: int, supervisionId: int|null, fromHr: bool, climbed: bool}>, parents: list<array{id: int, fromHr: bool}>, total: int, added: int, path: list<int>}>,
     *     roots: list<int>,
     *     unassigned: list<array{id: int, name: string, initials: string}>,
     *     matches: list<int>|null,
     *     counts: array{all: int, hr: int, added: int}
     * }
     */
    #[Computed]
    public function directory(): array
    {
        $allLinks = SupervisionMap::links();
        $activeIds = SupervisionMap::activeEmployeeIds();
        $active = array_fill_keys($activeIds, true);

        $links = array_values(array_filter($allLinks, fn (array $link): bool => match ($this->source) {
            'hr' => $link['supervisionId'] === null,
            'added' => $link['supervisionId'] !== null,
            default => true,
        }));

        $linked = [];

        foreach ($allLinks as $link) {
            $linked[$link['supervisor']] = true;
            $linked[$link['supervisee']] = true;
        }

        $noContract = array_fill_keys(SupervisionMap::hrIssues()['noContract'], true);
        $unassignedIds = $this->source === 'all' ? array_values(array_filter($activeIds, fn (int $id): bool => ! isset($linked[$id]) && ! isset($noContract[$id]))) : [];

        $locale = Locale::current()->value;
        $names = app(EmployeeRecipients::class)->names(array_merge(array_column($links, 'supervisor'), array_column($links, 'supervisee'), $unassignedIds));
        $nameOf = fn (int $id): string => $names[$id][$locale] ?? '#'.$id;

        $nodes = [];

        foreach ($links as $link) {
            foreach ([$link['supervisor'], $link['supervisee']] as $id) {
                $nodes[$id] ??= ['id' => $id, 'name' => $nameOf($id), 'initials' => self::initials($nameOf($id)), 'inactive' => ! isset($active[$id]), 'children' => [], 'parents' => [], 'total' => 0, 'added' => 0, 'path' => []];
            }

            $fromHr = $link['supervisionId'] === null;
            $nodes[$link['supervisor']]['children'][] = ['id' => $link['supervisee'], 'supervisionId' => $link['supervisionId'], 'fromHr' => $fromHr, 'climbed' => $link['climbed']];
            $nodes[$link['supervisee']]['parents'][] = ['id' => $link['supervisor'], 'fromHr' => $fromHr];

            if (! $fromHr) {
                $nodes[$link['supervisor']]['added']++;
            }
        }

        $byName = fn (int $first, int $second): int => strcasecmp($nodes[$first]['name'], $nodes[$second]['name']);

        foreach (array_keys($nodes) as $id) {
            /* A team lists the people who lead teams of their own first, then everyone by name. */
            usort($nodes[$id]['children'], fn (array $first, array $second): int => [$nodes[$first['id']]['children'] === [], $nodes[$first['id']]['name']] <=> [$nodes[$second['id']]['children'] === [], $nodes[$second['id']]['name']]);
            $nodes[$id]['total'] = count(self::walk($nodes, $id, 'children'));
            $nodes[$id]['path'] = self::path($nodes, $id);
        }

        $roots = array_values(array_filter(array_keys($nodes), fn (int $id): bool => $nodes[$id]['parents'] === [] && $nodes[$id]['children'] !== []));
        $reached = [];

        foreach ($roots as $root) {
            $reached += array_fill_keys([$root, ...self::walk($nodes, $root, 'children')], true);
        }

        /* A loop with nobody above it would never be drawn: its first unreached member becomes a top-level node. */
        foreach (array_keys($nodes) as $id) {
            if (! isset($reached[$id]) && $nodes[$id]['children'] !== []) {
                $roots[] = $id;
                $reached += array_fill_keys([$id, ...self::walk($nodes, $id, 'children')], true);
            }
        }

        usort($roots, $byName);

        $unassigned = array_map(fn (int $id): array => ['id' => $id, 'name' => $nameOf($id), 'initials' => self::initials($nameOf($id))], $unassignedIds);
        usort($unassigned, fn (array $first, array $second): int => strcasecmp($first['name'], $second['name']));

        $matches = null;
        $search = Str::lower(trim($this->search));

        if ($search !== '') {
            $isMatch = fn (int $id): bool => str_contains(Str::lower(implode(' ', $names[$id] ?? [])), $search);
            $matches = array_values(array_filter(array_keys($nodes), $isMatch));
            usort($matches, $byName);
            $unassigned = array_values(array_filter($unassigned, fn (array $person): bool => $isMatch($person['id'])));
        }

        $localCount = count(array_filter($allLinks, fn (array $link): bool => $link['supervisionId'] !== null));

        return [
            'nodes' => $nodes,
            'roots' => $roots,
            'unassigned' => $unassigned,
            'matches' => $matches,
            'counts' => ['all' => count($allLinks), 'hr' => count($allLinks) - $localCount, 'added' => $localCount],
        ];
    }

    /**
     * HR records that keep the org chart from reading cleanly, named, for the "Fix in HR" section.
     *
     * @return list<array{kind: string, title: string, people: list<string>}>
     */
    #[Computed]
    public function hrProblems(): array
    {
        $issues = SupervisionMap::hrIssues();
        $locale = Locale::current()->value;
        $ids = array_merge($issues['noContract'], $issues['severalContracts'], ...array_column($issues['vacantSeats'], 'staff'), ...array_column($issues['sharedSeats'], 'holders'));
        $names = app(EmployeeRecipients::class)->names($ids);
        $nameList = function (array $employeeIds) use ($names, $locale): array {
            $list = array_map(fn (int $id): string => $names[$id][$locale] ?? '#'.$id, $employeeIds);
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);

            return $list;
        };
        $titleOf = fn (array $seat): string => (string) ($locale === 'ar' && filled($seat['title_ar']) ? $seat['title_ar'] : ($seat['title_en'] ?? '#'.$seat['positionId']));

        $problems = [];

        foreach ($issues['vacantSeats'] as $seat) {
            $problems[] = ['kind' => 'vacant', 'title' => __('users.supervision_issue_vacant', ['position' => $titleOf($seat)]), 'people' => $nameList($seat['staff'])];
        }

        foreach ($issues['sharedSeats'] as $seat) {
            $problems[] = ['kind' => 'shared', 'title' => __('users.supervision_issue_shared', ['position' => $titleOf($seat), 'count' => count($seat['holders'])]), 'people' => $nameList($seat['holders'])];
        }

        if ($issues['noContract'] !== []) {
            $problems[] = ['kind' => 'noContract', 'title' => __('users.supervision_issue_no_contract'), 'people' => $nameList($issues['noContract'])];
        }

        if ($issues['severalContracts'] !== []) {
            $problems[] = ['kind' => 'severalContracts', 'title' => __('users.supervision_issue_several_contracts'), 'people' => $nameList($issues['severalContracts'])];
        }

        return $problems;
    }

    /**
     * The names shown in the remove confirmation.
     *
     * @return array{supervisor: string, supervisee: string}
     */
    #[Computed]
    public function removing(): array
    {
        $supervision = $this->removingId === null ? null : Supervision::query()->find($this->removingId);

        if ($supervision === null) {
            return ['supervisor' => '', 'supervisee' => ''];
        }

        $locale = Locale::current()->value;
        $names = app(EmployeeRecipients::class)->names([$supervision->supervisor_employee_id, $supervision->supervisee_employee_id]);

        return [
            'supervisor' => $names[$supervision->supervisor_employee_id][$locale] ?? '',
            'supervisee' => $names[$supervision->supervisee_employee_id][$locale] ?? '',
        ];
    }

    /**
     * Open the add modal, filled with a supervisor (from a team) or a supervisee (from "No supervisor") when given.
     */
    public function openAddModal(?int $supervisorId = null, ?int $superviseeId = null): void
    {
        Gate::authorize('manage', Supervision::class);

        $this->resetValidation();
        $this->supervisorEmployeeId = $supervisorId === null ? '' : (string) $supervisorId;
        $this->superviseeEmployeeIds = $superviseeId === null ? [] : [(string) $superviseeId];
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
        $this->reset('supervisorEmployeeId', 'superviseeEmployeeIds');
        $this->resetValidation();
    }

    /**
     * Add one link per chosen supervisee, all or nothing: a refusal names the person and adds none.
     */
    public function addSupervision(AddSupervision $addSupervision): void
    {
        Gate::authorize('manage', Supervision::class);

        $this->validate([
            'supervisorEmployeeId' => ['required', 'integer'],
            'superviseeEmployeeIds' => ['required', 'array', 'min:1'],
            'superviseeEmployeeIds.*' => ['integer'],
        ], [
            'supervisorEmployeeId.*' => __('users.supervision_choose_an_active_employee'),
            'superviseeEmployeeIds.*' => __('users.supervision_choose_an_active_employee'),
        ]);

        $supervisorId = (int) $this->supervisorEmployeeId;
        $superviseeIds = array_values(array_unique(array_map(intval(...), $this->superviseeEmployeeIds)));

        try {
            DB::transaction(function () use ($addSupervision, $supervisorId, $superviseeIds): void {
                foreach ($superviseeIds as $superviseeId) {
                    try {
                        $addSupervision->handle($supervisorId, $superviseeId);
                    } catch (ValidationException $exception) {
                        throw $this->refusalFor($exception, $superviseeId, count($superviseeIds) > 1);
                    }
                }
            });
        } finally {
            SupervisionMap::flush();
        }

        $this->closeAddModal();
        unset($this->directory);

        Flux::toast(variant: 'success', text: trans_choice('users.supervision_added_count', count($superviseeIds), ['count' => count($superviseeIds)]));
    }

    public function confirmRemove(int $supervisionId): void
    {
        Gate::authorize('manage', Supervision::class);

        $this->removingId = $supervisionId;
        $this->showRemoveModal = true;
    }

    public function closeRemoveModal(): void
    {
        $this->showRemoveModal = false;
        $this->removingId = null;
    }

    public function removeSupervision(RemoveSupervision $removeSupervision): void
    {
        Gate::authorize('manage', Supervision::class);

        $removed = $this->removingId !== null && $removeSupervision->handle($this->removingId);

        $this->closeRemoveModal();
        unset($this->directory);

        Flux::toast(
            variant: $removed ? 'success' : 'warning',
            text: $removed ? __('users.supervision_removed') : __('users.supervision_already_removed'),
        );
    }

    /**
     * Move AddSupervision's supervisee error onto the multi-pick field, naming the person when several were picked.
     */
    private function refusalFor(ValidationException $exception, int $superviseeId, bool $nameThePerson): ValidationException
    {
        $errors = $exception->errors();

        if (isset($errors['supervisorEmployeeId'])) {
            return $exception;
        }

        $message = $errors['superviseeEmployeeId'][0] ?? __('users.supervision_choose_an_active_employee');
        $name = app(EmployeeRecipients::class)->names([$superviseeId])[$superviseeId][Locale::current()->value] ?? '';

        return ValidationException::withMessages([
            'superviseeEmployeeIds' => $nameThePerson && $name !== '' ? __('users.supervision_refused_for', ['name' => $name, 'message' => $message]) : $message,
        ]);
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::upper(implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }

    /**
     * Everyone reached from a person along 'children' (below) or 'parents' (above), each once, loops cut.
     *
     * @param  array<int, array{children: list<array{id: int}>, parents: list<array{id: int}>}>  $nodes
     * @return list<int>
     */
    private static function walk(array $nodes, int $id, string $direction): array
    {
        $seen = [$id => true];
        $stack = [$id];

        while ($stack !== []) {
            foreach ($nodes[array_pop($stack)][$direction] ?? [] as $relative) {
                if (! isset($seen[$relative['id']])) {
                    $seen[$relative['id']] = true;
                    $stack[] = $relative['id'];
                }
            }
        }

        unset($seen[$id]);

        return array_keys($seen);
    }

    /**
     * The chain of ids from the top down to the person, through the first supervisor at each step.
     *
     * @param  array<int, array{parents: list<array{id: int}>}>  $nodes
     * @return list<int>
     */
    private static function path(array $nodes, int $id): array
    {
        $chain = [$id];
        $seen = [$id => true];

        while (($parent = $nodes[$id]['parents'][0]['id'] ?? null) !== null && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            array_unshift($chain, $parent);
            $id = $parent;
        }

        return $chain;
    }
}; ?>

@php
    $directory = $this->directory;
    $nodes = $directory['nodes'];
    $matches = $directory['matches'];
    $searching = $matches !== null;
    $roots = $directory['roots'];
    $teamIds = array_values(array_filter(array_keys($nodes), fn (int $id): bool => $nodes[$id]['children'] !== []));
    $unassigned = $directory['unassigned'];
    $sources = ['all' => __('users.supervision_filter_all'), 'hr' => __('users.supervision_from_hr'), 'added' => __('users.supervision_filter_added')];
    $teamNames = array_map(fn (int $id): string => $nodes[$id]['name'], array_combine($teamIds, $teamIds) ?: []);
@endphp

{{--
    Drill-down: one team on screen at a time. Every team is rendered once and
    shown by Alpine (`trail` is the breadcrumb of opened supervisors), so
    stepping in and out never waits for the server and survives re-renders.
--}}
<div
    class="grid min-w-0 gap-4"
    data-supervision-tab
    x-data="{
        trail: @js(count($roots) === 1 ? $roots : []),
        names: @js((object) $teamNames),
        get focus() { return this.trail.length ? this.trail[this.trail.length - 1] : null },
        open(id) { if (this.names[id] !== undefined) { this.trail.push(id); this.$nextTick(() => this.$refs.levels.scrollIntoView({ block: 'nearest', behavior: 'smooth' })) } },
        go(ids) { this.trail = ids.filter((id) => this.names[id] !== undefined) },
        to(index) { this.trail = this.trail.slice(0, index + 1) },
    }"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <p class="max-w-2xl text-sm leading-6 text-secondary-medium dark:text-ink-muted">{{ __('users.supervision_intro') }}</p>
        <x-ui.button tone="primary" icon="plus" wire:click="openAddModal" data-test="add-supervision-button">{{ __('users.supervision_add') }}</x-ui.button>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="w-full max-w-sm">
            <x-ui.input id="supervision-search" icon="magnifying-glass" :label="__('users.supervision_search')" :placeholder="__('users.supervision_search_placeholder')" wire:model.live.debounce.300ms="search" clearable />
        </div>

        <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="{{ __('users.supervision_filter_label') }}">
            @foreach ($sources as $value => $label)
                <button type="button" role="radio" aria-checked="{{ $source === $value ? 'true' : 'false' }}" wire:click="$set('source', '{{ $value }}')" data-test="supervision-source-{{ $value }}" @class([
                    'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium',
                    'border-primary bg-primary text-white dark:border-brand dark:bg-brand' => $source === $value,
                    'border-secondary-light bg-white text-secondary-medium hover:text-secondary-dark dark:border-line-strong dark:bg-surface-1 dark:text-ink-muted dark:hover:text-ink' => $source !== $value,
                ])>
                    {{ $label }}
                    <span class="tabular-nums opacity-80">{{ $directory['counts'][$value] }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($source === 'all' && ! $searching && $unassigned !== [])
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/40 bg-white px-4 py-2.5 dark:border-brand/50 dark:bg-surface-1" role="status" data-test="supervision-unassigned-alert">
            <span class="inline-flex items-center gap-2 text-sm text-secondary-dark dark:text-ink">
                <flux:icon.exclamation-triangle variant="mini" class="size-4 text-primary dark:text-brand-text" aria-hidden="true" />
                {{ trans_choice('users.supervision_unassigned_alert', count($unassigned), ['count' => count($unassigned)]) }}
            </span>
            <a href="#supervision-unassigned" x-on:click="trail = []" class="text-xs font-semibold text-primary hover:underline dark:text-brand-text">{{ __('users.supervision_jump_to_them') }}</a>
        </div>
    @endif

    @if ($source === 'all' && ! $searching && $this->hrProblems !== [])
        <section class="rounded-lg border border-secondary-soft bg-white dark:border-line dark:bg-surface-1" x-data="{ open: false }" data-test="supervision-hr-problems">
            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex min-h-11 w-full items-center gap-2.5 px-4 text-start">
                <flux:icon.wrench-screwdriver variant="mini" class="size-4 text-secondary-medium dark:text-ink-muted" aria-hidden="true" />
                <span class="flex-1 text-sm font-bold text-secondary-dark dark:text-ink">{{ __('users.supervision_fix_in_hr') }}</span>
                <span class="hidden text-xs text-secondary-medium sm:inline dark:text-ink-muted">{{ __('users.supervision_fix_in_hr_hint') }}</span>
                <span class="rounded-full bg-secondary-soft px-2 py-0.5 text-[0.7rem] font-semibold tabular-nums text-secondary-medium dark:bg-surface-3 dark:text-ink-muted">{{ count($this->hrProblems) }}</span>
                <flux:icon.chevron-down variant="micro" class="size-4 text-secondary-medium transition dark:text-ink-muted" x-bind:class="open && 'rotate-180'" aria-hidden="true" />
            </button>
            <ul x-show="open" x-cloak class="grid gap-2 border-t border-secondary-soft p-3 @3xl/directory:grid-cols-2 dark:border-line">
                @foreach ($this->hrProblems as $problem)
                    <li class="rounded-md bg-secondary-soft/50 px-3 py-2 dark:bg-surface-2" wire:key="hr-problem-{{ $loop->index }}" data-hr-problem="{{ $problem['kind'] }}">
                        <p class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $problem['title'] }}</p>
                        <p class="mt-0.5 text-xs leading-5 text-secondary-medium dark:text-ink-muted">{{ implode(' · ', $problem['people']) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div x-ref="levels" class="min-w-0 scroll-mt-24">
        @if ($searching)
            {{-- Search: a flat list of the people found, each with their chain; a click opens their team, or the team they sit in. --}}
            @if ($matches === [] && $unassigned === [])
                <x-ui.empty-state icon="magnifying-glass" :heading="__('users.supervision_no_match')" />
            @else
                <p class="mb-2 text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ trans_choice('users.supervision_results', count($matches) + count($unassigned), ['count' => count($matches) + count($unassigned)]) }}</p>
                <div class="grid gap-2 @xl/directory:grid-cols-2 @5xl/directory:grid-cols-3">
                    @foreach ($matches as $matchId)
                        @php
                            $match = $nodes[$matchId];
                            $target = $match['children'] !== [] ? $match['path'] : array_slice($match['path'], 0, -1);
                        @endphp
                        <button type="button" wire:key="supervision-result-{{ $matchId }}" x-on:click="go(@js($target)); $wire.set('search', '')" class="flex min-h-14 min-w-0 items-center gap-3 rounded-lg border border-secondary-soft bg-white px-3 py-2 text-start transition hover:border-primary/60 dark:border-line dark:bg-surface-1 dark:hover:border-brand/60">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-[0.7rem] font-bold text-secondary-medium dark:bg-surface-3 dark:text-ink-muted" aria-hidden="true">{{ $match['initials'] }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $match['name'] }}</span>
                                <span class="block truncate text-xs text-secondary-medium dark:text-ink-muted">{{ implode(' › ', array_map(fn (int $id): string => $nodes[$id]['name'], $match['path'])) }}</span>
                            </span>
                            @if ($match['children'] !== [])
                                <span class="shrink-0 rounded-full bg-secondary-soft px-2 py-0.5 text-[0.7rem] font-semibold tabular-nums text-secondary-medium dark:bg-surface-3 dark:text-ink-muted">{{ count($match['children']) }} ›</span>
                            @endif
                        </button>
                    @endforeach
                    @foreach ($unassigned as $person)
                        <div wire:key="supervision-result-unassigned-{{ $person['id'] }}" class="flex min-h-14 min-w-0 items-center gap-3 rounded-lg border border-dashed border-primary/40 bg-white px-3 py-2 dark:border-brand/50 dark:bg-surface-1">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-[0.7rem] font-bold text-secondary-medium dark:bg-surface-3 dark:text-ink-muted" aria-hidden="true">{{ $person['initials'] }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $person['name'] }}</span>
                                <span class="block truncate text-xs text-status-critical">{{ __('users.supervision_unassigned_title') }}</span>
                            </span>
                            <button type="button" wire:click="openAddModal(null, {{ $person['id'] }})" class="rounded px-2 py-1 text-xs font-semibold text-primary hover:bg-primary/10 dark:text-brand-text" data-test="assign-supervisor-{{ $person['id'] }}">{{ __('users.supervision_assign') }}</button>
                        </div>
                    @endforeach
                </div>
            @endif
        @elseif ($roots === [] && $unassigned === [])
            <x-ui.empty-state
                icon="users"
                :heading="$source !== 'all' ? __('users.supervision_no_match') : __('users.supervision_empty')"
                :body="$source !== 'all' ? null : __('users.supervision_empty_body')"
            />
        @else
            {{-- Breadcrumb --}}
            <nav class="mb-3 flex min-h-9 flex-wrap items-center gap-1 text-sm" aria-label="{{ __('users.supervision_breadcrumb') }}">
                <button type="button" x-on:click="trail = []" class="rounded px-1.5 py-1 font-semibold" x-bind:class="focus === null ? 'text-secondary-dark dark:text-ink' : 'text-primary hover:underline dark:text-brand-text'">{{ __('users.supervision_all_supervisors') }}</button>
                <template x-for="(id, index) in trail" :key="index">
                    <span class="inline-flex items-center gap-1">
                        <flux:icon.chevron-right variant="micro" class="size-3.5 text-secondary-light rtl:-scale-x-100 dark:text-ink-muted" aria-hidden="true" />
                        <button type="button" x-on:click="to(index)" class="rounded px-1.5 py-1 font-semibold" x-bind:class="index === trail.length - 1 ? 'text-secondary-dark dark:text-ink' : 'text-primary hover:underline dark:text-brand-text'" x-text="names[id]"></button>
                    </span>
                </template>
            </nav>

            {{-- Top level: the supervisors nobody above --}}
            <section x-show="focus === null" data-test="supervision-level-top">
                <div class="grid gap-2 @xl/directory:grid-cols-2 @5xl/directory:grid-cols-3">
                    @foreach ($roots as $rootId)
                        @include('components.admin._supervision-card', ['memberId' => $rootId, 'link' => null, 'teamId' => null])
                    @endforeach
                </div>
            </section>

            {{-- One level per team --}}
            @foreach ($teamIds as $teamId)
                @php
                    $team = $nodes[$teamId];
                    $members = $team['children'];
                @endphp
                <section x-show="focus === {{ $teamId }}" x-cloak wire:key="supervision-team-{{ $teamId }}" data-test="supervision-panel-{{ $teamId }}">
                    <div class="mb-3 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg border border-secondary-soft bg-white px-4 py-3 dark:border-line dark:bg-surface-1">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-sm font-bold text-secondary-dark dark:bg-surface-3 dark:text-ink" aria-hidden="true">{{ $team['initials'] }}</span>
                            <div class="min-w-0">
                                <p class="truncate text-base font-bold text-secondary-dark dark:text-ink">{{ $team['name'] }}</p>
                                <p class="truncate text-xs text-secondary-medium dark:text-ink-muted">
                                    {{ __('users.supervision_reports_to') }}:
                                    @forelse ($team['parents'] as $parent)
                                        <button type="button" class="font-semibold hover:underline" x-on:click="go(@js($nodes[$parent['id']]['path']))">{{ $nodes[$parent['id']]['name'] }}</button>@if (! $loop->last), @endif
                                    @empty
                                        {{ __('users.supervision_top_of_chain') }}
                                    @endforelse
                                </p>
                            </div>
                        </div>
                        <dl class="flex gap-5">
                            <div>
                                <dt class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('users.supervision_team') }}</dt>
                                <dd class="text-lg font-bold tabular-nums text-secondary-dark dark:text-ink">{{ count($members) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('users.supervision_in_total') }}</dt>
                                <dd class="text-lg font-bold tabular-nums text-secondary-dark dark:text-ink">{{ $team['total'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('users.supervision_added_here') }}</dt>
                                <dd @class(['text-lg font-bold tabular-nums', 'text-status-critical' => $team['added'] > 0, 'text-secondary-dark dark:text-ink' => $team['added'] === 0])>{{ $team['added'] }}</dd>
                            </div>
                        </dl>
                        <x-ui.button tone="primary" size="sm" icon="user-plus" wire:click="openAddModal({{ $teamId }})" data-test="add-to-team-{{ $teamId }}">{{ __('users.supervision_add_to_team') }}</x-ui.button>
                    </div>

                    <div class="grid gap-2 @xl/directory:grid-cols-2 @5xl/directory:grid-cols-3">
                        @foreach ($members as $member)
                            @include('components.admin._supervision-card', ['memberId' => $member['id'], 'link' => $member, 'teamId' => $teamId])
                        @endforeach
                    </div>
                </section>
            @endforeach

            @if ($unassigned !== [])
                <section id="supervision-unassigned" x-show="focus === null" class="mt-5 scroll-mt-24" data-test="supervision-unassigned">
                    <h3 class="mb-2 flex items-center gap-2 text-sm font-bold text-status-critical">
                        <flux:icon.user-minus variant="mini" class="size-4" aria-hidden="true" />
                        {{ __('users.supervision_unassigned_title') }}
                        <span class="rounded-full bg-secondary-soft px-2 py-0.5 text-[0.7rem] font-semibold tabular-nums text-secondary-medium dark:bg-surface-3 dark:text-ink-muted">{{ count($unassigned) }}</span>
                    </h3>
                    <div class="grid gap-2 @xl/directory:grid-cols-2 @5xl/directory:grid-cols-3">
                        @foreach ($unassigned as $person)
                            <div class="flex min-h-14 min-w-0 items-center gap-3 rounded-lg border border-dashed border-primary/40 bg-white px-3 py-2 dark:border-brand/50 dark:bg-surface-1" wire:key="unassigned-{{ $person['id'] }}">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-[0.7rem] font-bold text-secondary-medium dark:bg-surface-3 dark:text-ink-muted" aria-hidden="true">{{ $person['initials'] }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-secondary-dark dark:text-ink">{{ $person['name'] }}</span>
                                <button type="button" wire:click="openAddModal(null, {{ $person['id'] }})" class="rounded px-2 py-1 text-xs font-semibold text-primary hover:bg-primary/10 dark:text-brand-text" data-test="assign-supervisor-{{ $person['id'] }}">{{ __('users.supervision_assign') }}</button>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif
    </div>

    <x-ui.modal
        wire:model="showAddModal"
        size="md"
        :title="__('users.supervision_add')"
        :description="__('users.supervision_add_description')"
        submit="addSupervision"
        close="closeAddModal"
        required-note
    >
        @if ($showAddModal)
            <div class="grid gap-y-4">
                <x-ui.form-select wire:model="supervisorEmployeeId" :label="__('users.supervision_supervisor')" :placeholder="__('users.supervision_select_employee')" required employees />
                <x-ui.form-select wire:model="superviseeEmployeeIds" :label="__('users.supervision_supervisees')" :placeholder="__('users.supervision_select_employees')" :help="__('users.supervision_supervisees_help')" required multiple employees />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-supervision-button">{{ __('users.supervision_add') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        icon="user-minus"
        wire:model="showRemoveModal"
        close="closeRemoveModal"
        :title="__('users.supervision_remove_title')"
        :message="__('users.supervision_remove_message', $this->removing)"
        confirm="removeSupervision"
        :confirm-label="__('users.supervision_remove')"
        confirm-test="confirm-remove-supervision-button"
        tone="neutral"
    />
</div>
