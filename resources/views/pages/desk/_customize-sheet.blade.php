@php
    $library = $this->library;
    $deskPanels = app(\App\Support\Desk\Panels::class);
    $starting = $deskPanels->startingLayoutFor(auth()->user());
    $readyLayouts = collect($this->readyLayouts);
    $favourite = $deskPanels->favouriteReadyLayoutFor(auth()->user(), $readyLayouts->pluck('key')->all());
    $readyChoices = $readyLayouts->map(fn (array $ready): array => \Illuminate\Support\Arr::only($ready, ['key', 'title', 'purpose', 'core', 'entries']))->values()->all();
    $panelChoices = collect($library)->map(fn (array $panel, string $id): array => [
        'title' => $panel['title'],
        'search' => mb_strtolower($panel['title'].' '.$panel['description']),
        'kind' => $deskPanels->readyKindOf($id),
        ...$deskPanels->sized($id, $panel['size']),
    ])->all();
    $pluralForms = fn (string $key): array => [
        'one' => trans_choice($key, 1, ['count' => 1]),
        'two' => trans_choice($key, 2, ['count' => 2]),
        'few' => trans_choice($key, 3, ['count' => '__N__']),
        'many' => trans_choice($key, 11, ['count' => '__N__']),
    ];
    $sheetTexts = [
        'count' => ['zero' => trans_choice('desk.draft_count', 0), ...$pluralForms('desk.draft_count')],
        'added' => $pluralForms('desk.customize_sheet.added'),
        'removed' => $pluralForms('desk.customize_sheet.removed'),
        'separator' => __('desk.customize_sheet.separator'),
        'use' => __('desk.customize_sheet.use', ['layout' => '__L__']),
        'done' => __('desk.done'),
        'suggested' => __('desk.ready.suggested'),
        'current' => __('desk.ready.current'),
    ];
    $moveEarlier = \Illuminate\Support\Js::from(__('desk.customize_sheet.move_panel_earlier', ['panel' => '__P__']));
    $moveLater = \Illuminate\Support\Js::from(__('desk.customize_sheet.move_panel_later', ['panel' => '__P__']));
    $removePanel = \Illuminate\Support\Js::from(__('desk.customize_sheet.remove_panel', ['panel' => '__P__']));
    /*
     * What can be added, in three tabs of folding groups: hand-made panels by what they hold; every page
     * as a card, by its sidebar section; and buttons: the "New ..." actions, then pages by sidebar section.
     */
    $destinations = app(\App\Support\Desk\Destinations::class);
    $sectionLabels = collect($destinations->sidebarFor(auth()->user()))->pluck('label', 'group')->all();
    $sectionOf = [];
    foreach ($destinations->for(auth()->user()) as $page) {
        $slug = \App\Support\Desk\Destinations::slugOf($page['route']);
        $section = (string) ($sectionLabels[$page['group']] ?? __('desk.customize_sheet.other_pages'));
        $sectionOf[\App\Support\Desk\PageCards::Prefix.$slug] = $section;
        $sectionOf[\App\Support\Desk\Shortcuts::PagePrefix.$slug] = $section;
    }
    $ofKind = fn (string $kind) => collect($library)->filter(fn (array $panel, string $id): bool => $deskPanels->readyKindOf($id) === $kind);
    $bySection = fn (string $kind, string $prefix) => $ofKind($kind)
        ->groupBy(fn (array $panel, string $id): string => $sectionOf[$id] ?? __('desk.customize_sheet.other_pages'), preserveKeys: true)
        ->map(fn ($items, string $title): array => ['key' => $prefix.'-'.\Illuminate\Support\Str::slug($title), 'title' => $title, 'items' => $items])
        ->values();
    $addTabs = collect([
        'panels' => collect(['money', 'work', 'results', 'people'])->map(fn (string $kind): array => ['key' => $kind, 'title' => __('desk.ready.kind.'.$kind), 'items' => $ofKind($kind)]),
        'cards' => $bySection('pages', 'pages'),
        'buttons' => collect([['key' => 'actions', 'title' => __('desk.ready.kind.actions'), 'items' => $ofKind('actions')]])->concat($bySection('buttons', 'buttons')),
    ])->map(fn ($groups) => $groups->filter(fn (array $group): bool => $group['items']->isNotEmpty())->values())->filter->isNotEmpty();
    $firstGroups = $addTabs->map(fn ($groups): string => $groups->first()['key'])->all();
    /* What the Desk itself can hold (panels and page cards): buttons go to Quick access. */
    $deskItemCount = collect($library)->filter(fn (array $item): bool => in_array($item['kind'], ['panel', 'card'], true))->count();
@endphp

{{--
    Customize your desk, one thing at a time. Ready layouts: a short list of
    names beside one large stage showing the layout being looked at, its live
    signal and a true-to-scale map; the footer's button uses it in one click.
    My panels: the draft's map beside what can be added. Every change is a draft
    kept in the browser (deskCustomize, resources/js/desk-customize.js) until the
    footer's button saves it in one call (saveLayout); closing the sheet any other
    way drops it. Colours: the site's red and neutrals only, guarded by a test on
    this file and desk-customize.css.
--}}
<div x-data="deskCustomize(@js($starting), @js($readyChoices), @js($favourite), @js($panelChoices), @js($sheetTexts))" data-layout="{{ json_encode($layout) }}" data-layout-saved="{{ is_array(auth()->user()->desk_layout) ? 'true' : 'false' }}" data-quick-items="{{ json_encode(app(\App\Support\Desk\QuickAccess::class)->itemIds(auth()->user())) }}" x-on:dk-customize-open.window="openSheet($event.detail?.pane, $event.detail?.layout)" x-on:keydown.escape.window="if (open) { discard() }" data-desk-customize>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="dk-scope">
            <div class="dk-sh-scrim" x-on:click="discard()"></div>
            <div class="dk-sheet dk-xwide dk-cz" role="dialog" aria-modal="true" aria-labelledby="dk-customize-title" tabindex="-1" x-trap="open" x-bind:data-pane="pane">
                <div class="dk-sh-h">
                    <h2 id="dk-customize-title">{{ __('desk.customize_title') }}</h2>
                    @if ($readyLayouts->isNotEmpty())
                        <div class="dk-cz-panes">
                            <div class="dk-seg" role="group" aria-label="{{ __('desk.customize_sheet.panes') }}" data-customize-panes>
                                <button type="button" x-on:click="pane = 'layouts'" aria-pressed="false" x-bind:aria-pressed="(pane === 'layouts').toString()">{{ __('desk.customize_sheet.pane_layouts') }}</button>
                                <button type="button" x-on:click="pane = 'desk'" aria-pressed="true" x-bind:aria-pressed="(pane === 'desk').toString()">{{ __('desk.customize_sheet.pane_desk') }}</button>
                            </div>
                        </div>
                    @endif
                    <button type="button" class="dk-iconbtn" x-on:click="discard()" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
                </div>

                <div class="dk-cz-body @if ($readyLayouts->isEmpty()) dk-cz-solo @endif" x-ref="body">
                    @if ($readyLayouts->isNotEmpty())
                        <section class="dk-cz-pane dk-cz-layouts" x-show="pane === 'layouts'" data-customize-ready aria-label="{{ __('desk.ready.title') }}">
                            <div class="dk-cz-list" x-on:keydown.arrow-down.prevent="step(1)" x-on:keydown.arrow-up.prevent="step(-1)">
                                @foreach ($readyLayouts->sortBy(fn (array $ready): int => $ready['key'] === $favourite ? 0 : 1) as $ready)
                                    @include('pages.desk._ready-layout', ['ready' => $ready])
                                @endforeach
                            </div>

                            <div class="dk-cz-stage" aria-live="polite" data-customize-stage>
                                <div class="dk-cz-stage-h">
                                    <div class="dk-cz-stage-t">
                                        <span class="dk-cz-eyebrow" x-bind:data-quiet="(viewing !== suggested).toString()" x-text="eyebrow()"></span>
                                        <h3 x-text="viewed()?.title ?? ''"></h3>
                                        <p class="dk-cz-purpose" x-text="viewed()?.purpose ?? ''"></p>
                                        <div class="dk-cz-sig" data-ready-signal>
                                            <i x-cloak x-show="signals[viewing]?.tone === 'critical'" aria-hidden="true"></i>
                                            <span x-show="signals[viewing]" x-text="signals[viewing]?.text ?? ''"></span>
                                            <span class="dk-cz-skel" x-show="! signals[viewing] && ! signalsFailed" aria-hidden="true"></span>
                                            <span class="dk-cz-sig-off" x-cloak x-show="! signals[viewing] && signalsFailed" data-ready-signal-failed>{{ __('desk.ready.signal_failed') }}</span>
                                        </div>
                                    </div>
                                    <button type="button" class="dk-btn dk-ghost dk-cz-adjust" x-on:click="adjust()" data-customize-adjust>{{ __('desk.customize_sheet.adjust') }}</button>
                                </div>

                                <div class="dk-cz-map" aria-hidden="true">
                                    <template x-for="(entry, index) in viewed()?.entries ?? []" x-bind:key="viewing + entry.panel">
                                        <div class="dk-cz-cell" x-bind:data-core="(viewed().core === entry.panel).toString()" x-bind:style="{ ...cellStyle(entry), '--i': index }">
                                            <span class="dk-cz-cell-t" x-text="titleOf(entry.panel)"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </section>
                    @endif

                    <section class="dk-cz-pane dk-cz-desk" x-show="pane === 'desk'" aria-label="{{ __('desk.customize_sheet.pane_desk') }}">
                        <div class="dk-cz-draft">
                            <div class="dk-cz-map" role="list" aria-label="{{ __('desk.customize_sheet.pane_desk') }}" x-show="draft.length > 0" data-customize-map>
                                <template x-for="(entry, index) in draft" x-bind:key="entry.panel">
                                    <div class="dk-cz-cell" role="listitem" x-bind:data-panel="entry.panel" x-bind:data-core="(chosenCore() === entry.panel).toString()" x-bind:style="cellStyle(entry)">
                                        <span class="dk-cz-cell-t" x-text="titleOf(entry.panel)"></span>
                                        <span class="dk-cz-acts">
                                            <button type="button" class="dk-iconbtn dk-sm dk-cz-prev" data-cz-act="earlier" x-on:click="move(entry.panel, -1)" x-bind:disabled="index === 0" x-bind:aria-label="{{ $moveEarlier }}.replace('__P__', titleOf(entry.panel))"><x-desk.icon name="chev" /></button>
                                            <button type="button" class="dk-iconbtn dk-sm dk-cz-next" data-cz-act="later" x-on:click="move(entry.panel, 1)" x-bind:disabled="index === draft.length - 1" x-bind:aria-label="{{ $moveLater }}.replace('__P__', titleOf(entry.panel))"><x-desk.icon name="chev" /></button>
                                            <button type="button" class="dk-iconbtn dk-sm" data-cz-act="remove" x-on:click="remove(entry.panel)" x-bind:aria-label="{{ $removePanel }}.replace('__P__', titleOf(entry.panel))"><x-desk.icon name="x" /></button>
                                        </span>
                                        {{-- Pull an edge or the corner, as on the Desk itself; the draft holds the size until Done. --}}
                                        <template x-for="edge in ['end', 'start', 'top', 'bottom', 'corner']" x-bind:key="edge">
                                            <span class="dk-cz-rz" x-bind:class="`dk-cz-rz-${edge}`" x-on:pointerdown.prevent="startResize($event, entry.panel, edge)" data-cz-resize aria-hidden="true"></span>
                                        </template>
                                        <span class="dk-cz-size dk-num" x-show="resizing?.panel === entry.panel" x-text="sizeLabel(entry)" aria-hidden="true"></span>
                                    </div>
                                </template>
                            </div>

                            {{-- On a phone a three-column cell cannot hold three 44px buttons: the map stays the picture, these rows are the controls. --}}
                            <ol class="dk-cz-placed" x-show="draft.length > 0">
                                <template x-for="(entry, index) in draft" x-bind:key="entry.panel">
                                    <li x-bind:data-panel="entry.panel">
                                        <span class="dk-cz-placed-t" x-text="titleOf(entry.panel)"></span>
                                        <button type="button" class="dk-iconbtn dk-cz-prev" data-cz-act="earlier" x-on:click="move(entry.panel, -1)" x-bind:disabled="index === 0" x-bind:aria-label="{{ $moveEarlier }}.replace('__P__', titleOf(entry.panel))"><x-desk.icon name="chev" /></button>
                                        <button type="button" class="dk-iconbtn dk-cz-next" data-cz-act="later" x-on:click="move(entry.panel, 1)" x-bind:disabled="index === draft.length - 1" x-bind:aria-label="{{ $moveLater }}.replace('__P__', titleOf(entry.panel))"><x-desk.icon name="chev" /></button>
                                        <button type="button" class="dk-iconbtn" data-cz-act="remove" x-on:click="remove(entry.panel)" x-bind:aria-label="{{ $removePanel }}.replace('__P__', titleOf(entry.panel))"><x-desk.icon name="x" /></button>
                                    </li>
                                </template>
                            </ol>

                            <div class="dk-cz-empty" x-show="draft.length === 0" x-cloak data-customize-empty>
                                <strong>{{ __('desk.customize_sheet.empty_title') }}</strong>
                                <span>{{ __('desk.customize_sheet.empty_text') }}</span>
                            </div>
                        </div>

                        {{-- Three tabs of folding groups, each with what is left to add; a search looks through all three at once. --}}
                        <div class="dk-cz-add" data-customize-add-panels x-data="{ tab: @js($addTabs->keys()->first()), open: @js(array_fill_keys(array_values($firstGroups), true)), left(ids) { return ids.filter((id) => isAddable(id)).length } }">
                            <label class="dk-cz-search">
                                <x-desk.icon name="search" />
                                <input type="search" x-ref="search" x-model.trim="search" placeholder="{{ __('desk.customize_sheet.search_placeholder') }}" aria-label="{{ __('desk.customize_sheet.search_placeholder') }}" x-on:keydown.escape="if (search !== '') { search = ''; $event.stopPropagation() }" data-customize-search>
                            </label>

                            <div class="dk-cz-tabs" role="tablist" aria-label="{{ __('desk.customize_sheet.add_tabs') }}" x-show="search === ''" data-customize-tabs>
                                @foreach ($addTabs as $tabKey => $groups)
                                    @php
                                        $tabIds = $groups->flatMap(fn (array $group) => $group['items']->keys())->values()->all();
                                    @endphp
                                    <button type="button" role="tab" x-on:click="tab = '{{ $tabKey }}'" x-bind:aria-selected="(tab === '{{ $tabKey }}').toString()" data-customize-tab="{{ $tabKey }}">
                                        <span>{{ __('desk.customize_sheet.tab_'.$tabKey) }}</span>
                                        <span class="dk-cz-n dk-num" x-text="left(@js($tabIds))"></span>
                                    </button>
                                @endforeach
                            </div>

                            @if ($addTabs->has('buttons'))
                                <p class="dk-cz-note" x-show="search === '' && tab === 'buttons'" x-cloak data-customize-buttons-note>{{ __('desk.customize_sheet.buttons_note') }}</p>
                            @endif

                            @foreach ($addTabs as $tabKey => $groups)
                                @foreach ($groups as $group)
                                    @php
                                        $groupIds = $group['items']->keys()->values()->all();
                                    @endphp
                                    <section class="dk-cz-group" x-show="(search !== '' || tab === '{{ $tabKey }}') && left(@js($groupIds)) > 0" data-customize-group="{{ $group['key'] }}">
                                        <button type="button" class="dk-cz-group-h" x-on:click="open['{{ $group['key'] }}'] = ! open['{{ $group['key'] }}']" x-bind:aria-expanded="(search !== '' || Boolean(open['{{ $group['key'] }}'])).toString()" aria-controls="dk-cz-g-{{ $group['key'] }}">
                                            <x-desk.icon name="chev" class="dk-cz-chev" />
                                            <h4>{{ $group['title'] }}</h4>
                                            <span class="dk-cz-n dk-num" x-text="left(@js($groupIds))"></span>
                                        </button>
                                        <ul id="dk-cz-g-{{ $group['key'] }}" x-show="search !== '' || open['{{ $group['key'] }}']" x-collapse>
                                            @foreach ($group['items'] as $panel)
                                                <li wire:key="cz-add-{{ $panel['id'] }}" x-show="isAddable('{{ $panel['id'] }}')" data-customize-add="{{ $panel['id'] }}">
                                                    <button type="button" class="dk-cz-pick" title="{{ $panel['description'] }}" x-on:click="pick('{{ $panel['id'] }}')" aria-label="{{ __($tabKey === 'buttons' ? 'desk.customize_sheet.add_quick' : 'desk.customize_sheet.add_panel', ['panel' => $panel['title']]) }}"><span>{{ $panel['title'] }}</span><x-desk.icon name="{{ $tabKey === 'buttons' ? 'bolt' : 'plus' }}" /></button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endforeach
                            @endforeach

                            <p class="dk-cz-none" x-show="search === '' && ! anyMatches()" data-nothing-to-add x-cloak>{{ __('desk.nothing_to_add') }}</p>
                            <p class="dk-cz-none" x-show="search !== '' && ! anyMatches()" x-cloak>{{ __('desk.customize_sheet.no_match') }}</p>
                        </div>
                    </section>
                </div>

                <div class="dk-sh-f dk-cz-f">
                    <button type="button" class="dk-btn dk-cz-reset" x-show="pane === 'desk'" x-on:click="reset()"><x-desk.icon name="undo" />{{ __('desk.reset_layout') }}</button>
                    <button type="button" class="dk-btn dk-ghost dk-cz-clear" x-show="pane === 'desk' && draft.length > 0" x-on:click="clear()" data-customize-clear><x-desk.icon name="x" />{{ __('desk.customize_sheet.remove_all') }}</button>
                    <span class="dk-is dk-num dk-cz-sum" role="status" x-show="pane === 'desk'" x-text="changeSummary()" data-customize-summary></span>
                    <span class="dk-is dk-cz-sum" x-show="pane === 'layouts'">{{ __('desk.customize_lede') }}</span>
                    <span class="dk-pspace"></span>
                    <button type="button" class="dk-btn dk-ghost" x-on:click="discard()">{{ __('common.cancel') }}</button>
                    <button type="button" class="dk-btn dk-primary" x-on:click="confirm()" x-text="confirmLabel()" data-customize-done>{{ __('desk.done') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
