<?php

use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Proposal;
use App\Support\Desk\Destinations;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The Ask bar's palette: one box that finds pages, projects, proposals and
 * donors, and answers "what is late?" and "money" from the same queries the
 * Desk panels read, so an answer never shows a row its panel would hide.
 */
new class extends Component {
    use ChartsDashboard;
    use ReadsDashboardAttention;

    /**
     * Results per group; the palette is a jump list, not a register.
     */
    private const GroupLimit = 6;

    public string $query = '';

    /**
     * The answers and the jump list for what was typed, grouped in display order.
     *
     * @return array{answers: list<array{title: string, body: string}>, groups: list<array{name: string, items: list<array{icon: string, label: string, sub: string, href: string}>}>}
     */
    #[Computed]
    public function results(): array
    {
        $term = Like::term($this->query);
        $needle = $this->normalize(ltrim($term, '+ '));
        $answers = [];
        $groups = [];

        if ($needle !== '' && $this->mentions($needle, 'desk.ask_words_late')) {
            $late = $this->attentionItems->filter(fn (array $item): bool => ($item['daysLate'] ?? 0) > 0)->sortByDesc('daysLate')->values();
            $answers[] = [
                'title' => trans_choice('desk.ask_late_title', $late->count(), ['count' => $late->count()]),
                'body' => __('desk.ask_late_body'),
            ];
            $groups[] = ['name' => __('desk.ask_group_answer'), 'items' => $late->map(fn (array $item): array => [
                'icon' => 'info',
                'label' => $item['title'],
                'sub' => $item['context'].' · '.$item['badge'],
                'href' => $item['href'],
            ])->all()];
        }

        if ($needle !== '' && $this->mentions($needle, 'desk.ask_words_money') && $this->canViewFundingPosition()) {
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

        if (mb_strlen($needle) >= 2) {
            $groups[] = ['name' => __('common.projects'), 'items' => $this->search(Project::query(), 'projects', $term)
                ->map(fn (Project $project): array => ['icon' => 'folder', 'label' => $project->name, 'sub' => __('common.projects'), 'href' => route('projects.show', $project)])->all()];
            $groups[] = ['name' => __('common.proposals'), 'items' => $this->search(Proposal::query(), 'proposals', $term)
                ->map(fn (Proposal $proposal): array => ['icon' => 'file', 'label' => $proposal->name, 'sub' => $proposal->status->label(), 'href' => route('proposals.show', $proposal)])->all()];
            $groups[] = ['name' => __('common.donors'), 'items' => $this->search(Donor::query(), 'donors', $term)
                ->map(fn (Donor $donor): array => ['icon' => 'users', 'label' => $donor->name, 'sub' => __('common.donors'), 'href' => route('donors.show', $donor)])->all()];
        }

        $pages = collect(app(Destinations::class)->for(auth()->user()))
            ->filter(fn (array $page): bool => $needle === '' ? $page['section'] === Destinations::Pages : str_contains($this->normalize($page['label']), $needle))
            ->take($needle === '' ? self::GroupLimit : 12)
            ->map(fn (array $page): array => ['icon' => $page['icon'], 'label' => $page['label'], 'sub' => '', 'href' => route($page['route'])]);
        $groups[] = ['name' => __('desk.ask_group_go'), 'items' => array_values($pages->all())];

        $groups = array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));

        return ['answers' => $answers, 'groups' => $groups];
    }

    /**
     * Rows of a module the viewer may open whose name contains the term.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    private function search(Builder $query, string $module, string $term): \Illuminate\Support\Collection
    {
        if (! $this->canView($module)) {
            return collect();
        }

        return $query->where('name', 'like', Like::contains($term))->orderBy('name')->limit(self::GroupLimit)->get();
    }

    /**
     * Whether the typed text is one of the trigger words in either language.
     */
    private function mentions(string $needle, string $wordsKey): bool
    {
        foreach (array_merge((array) trans($wordsKey, [], 'en'), (array) trans($wordsKey, [], 'ar')) as $word) {
            $word = $this->normalize((string) $word);

            if (str_contains($needle, $word) || (mb_strlen($needle) >= 3 && str_starts_with($word, $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lower case, without Arabic diacritics, so "متأخر" and "متأخّر" match.
     */
    private function normalize(string $text): string
    {
        return (string) preg_replace('/[\x{064B}-\x{065F}]/u', '', mb_strtolower(trim($text)));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function visible(Builder $query, string $module): Builder
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
                    <kbd>{{ __('desk.key_esc') }}</kbd>
                </div>

                <div class="dk-pal-list" x-ref="list">
                    @foreach ($results['answers'] as $answer)
                        <div class="dk-answer"><strong>{{ $answer['title'] }}</strong>{{ $answer['body'] }}</div>
                    @endforeach

                    @forelse ($results['groups'] as $group)
                        <div class="dk-pal-g">{{ $group['name'] }}</div>
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['href'] }}" wire:navigate class="dk-pal-i" data-pi="{{ $index }}" x-bind:class="{ 'dk-sel': selected === {{ $index }} }" x-on:click="hide()">
                                <span class="dk-ic"><x-desk.icon :name="$item['icon']" /></span>
                                <span>{{ $item['label'] }}@if ($item['sub'] !== '')<small>{{ $item['sub'] }}</small>@endif</span>
                            </a>
                            @php
                                $index++;
                            @endphp
                        @endforeach
                    @empty
                        @if ($results['answers'] === [])
                            <div class="dk-answer"><strong>{{ __('desk.ask_nothing_title') }}</strong>{{ __('desk.ask_nothing_body') }}</div>
                        @endif
                    @endforelse
                </div>

                <div class="dk-pal-foot">
                    <span><kbd>↑</kbd> <kbd>↓</kbd> {{ __('desk.ask_move') }}</span>
                    <span><kbd>{{ __('desk.key_enter') }}</kbd> {{ __('desk.ask_open') }}</span>
                </div>
            </div>
        </div>
    </template>
</div>
