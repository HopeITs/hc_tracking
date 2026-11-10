@props(['showsInbox' => false])

@php
    $user = auth()->user();
    $destinations = app(\App\Support\Desk\Destinations::class);
    $routeName = request()->route()?->getName() ?? '';
    $groups = $destinations->sidebarFor($user);
    $moduleCounts = $showsInbox ? app(\App\Notifications\UnreadCounts::class)->for($user)['modules'] : [];
    $currentLink = $destinations->currentFor($user, $routeName)['route'] ?? null;
    $isCurrent = fn (string $route): bool => $route === $currentLink;
    $countOf = fn (array $item): int => $item['module'] !== null ? (int) ($moduleCounts[$item['module']] ?? 0) : 0;
    $badge = fn (int $count): string => $count > 99 ? '99+' : (string) $count;
    $dueModules = app(\App\Support\Desk\NavDeadlines::class)->modulesFor($user);
    /* On My desk every page can be dragged onto the Desk, or added with its "+" (resources/js/desk-drop.js). */
    $onDesk = $routeName === 'dashboard';

    /* Every page the sidebar offers, by route, for the Pinned zone. */
    $pages = collect($groups)->flatMap(fn (array $group): array => $group['items'])->keyBy('route');
    $pinnable = $pages->map(fn (array $item): array => [
        'label' => $item['label'],
        'href' => route($item['route']),
        'count' => $countOf($item),
    ])->all();
    $pins = collect($user->nav_pins ?? [])->filter(fn (mixed $pin): bool => is_string($pin) && $pages->has($pin))->values()->all();

    /* The records this person works in most, by use (RemembersRecentRecord::mostUsed). */
    $recent = collect(\App\Http\Middleware\RemembersRecentRecord::mostUsed($user->nav_recent))
        ->filter(fn (array $entry): bool => Route::has($entry['route']))
        ->map(fn (array $entry): array => [
            'href' => route($entry['route'], $entry['key']),
            'label' => $entry['label'],
            'kind' => __('navigation.kind_'.$entry['kind']),
        ])->values()->all();

    /* Inside a project, the sidebar leads with that project's own sections. */
    $project = $routeName === 'projects.show' ? request()->route('project') : null;
    $project = $project instanceof \App\Models\Project ? $project : null;
    $sections = [];

    if ($project !== null) {
        $tab = (string) request()->query('tab', '');
        $sections = collect([
            ['tab' => '', 'label' => __('navigation.section_story'), 'icon' => 'nav-book-open'],
            ['tab' => 'tasks', 'label' => __('navigation.section_tasks'), 'icon' => 'nav-square-check-big'],
            ['tab' => 'risks', 'label' => __('navigation.section_risks'), 'icon' => 'nav-siren'],
            ['tab' => 'targets', 'label' => __('navigation.section_targets'), 'icon' => 'nav-crosshair'],
            ['tab' => 'proposals', 'label' => __('navigation.section_funding'), 'icon' => 'nav-wallet'],
            ['tab' => 'files', 'label' => __('navigation.section_files'), 'icon' => 'nav-paperclip'],
        ])->map(fn (array $section): array => $section + [
            'href' => route('projects.show', $section['tab'] === '' ? [$project] : [$project, 'tab' => $section['tab']]),
            'current' => $section['tab'] === $tab || ($section['tab'] === '' && in_array($tab, ['', 'milestones'], true)),
        ])->all();
    }
@endphp

{{--
    The application sidebar: every page the viewer's role may open (App\Support\Desk\Destinations).
    It rests as an icon rail; the toggle expands it to labels, kept in localStorage (hc-nav)
    and on <html class="dk-nav-open"> before first paint. On the rail a group's links fly out on hover;
    expanded, each group folds open in place. Above the groups sit the viewer's pinned pages and the
    records they opened last; inside a project the sidebar leads with that project's sections.
    Below 900px the top bar's menu button lays the expanded sidebar over the page.
--}}
<nav
    @class(['dk-side dk-scope', 'dk-side-ondesk' => $onDesk])
    aria-label="{{ __('navigation.application_navigation') }}"
    x-data="hcSideNav({ pins: @js($pins), pages: @js($pinnable), pinUrl: @js(route('nav.pins.toggle')), context: @js($project !== null) })"
    data-side-nav
>
    <div class="dk-side-h">
        <a class="dk-side-logo" href="{{ route('dashboard') }}" wire:navigate aria-label="{{ __('desk.logo_label') }}" data-desk-logo>
            <img class="dk-side-mark" src="{{ asset('logo-tracking-icon.svg') }}" alt="" data-no-dim />
            <img class="dk-side-word dk-side-word-light" src="{{ asset('logo-tracking.svg') }}" alt="{{ config('app.name') }}" data-no-dim />
            <img class="dk-side-word dk-side-word-dark" src="{{ asset('logo-tracking-white.svg') }}" alt="{{ config('app.name') }}" data-no-dim />
        </a>
    </div>

    <button type="button" class="dk-side-toggle" x-on:click="toggle()" x-bind:aria-expanded="expanded.toString()" x-bind:aria-label="expanded ? @js(__('navigation.collapse_sidebar')) : @js(__('navigation.expand_sidebar'))" data-side-nav-toggle>
        <flux:icon.chevron-right variant="micro" class="dk-side-chev size-4" />
    </button>

    <div class="dk-side-scroll" x-ref="scroll" x-on:scroll.passive="remember(); unfly()" x-on:mouseover="hover($event)" x-on:mouseleave="leave()" x-on:focusin="hover($event)" x-on:focusout="untip()">
        {{-- Inside a project: its own sections first; "All pages" swaps back to the full sidebar. --}}
        @if ($project !== null)
            <section class="dk-side-g dk-side-ctx" x-show="context" data-side-context="{{ $project->getRouteKey() }}">
                <button type="button" class="dk-side-back" x-on:click="context = false" data-label="{{ __('navigation.all_pages') }}" data-side-context-back>
                    <flux:icon.arrow-left variant="micro" class="size-4 rtl:-scale-x-100" />
                    <span class="dk-side-l">{{ __('navigation.all_pages') }}</span>
                </button>
                <p class="dk-side-ctx-t" title="{{ $project->name }}">{{ $project->name }}</p>
                <ul>
                    @foreach ($sections as $section)
                        <li>
                            <a href="{{ $section['href'] }}" wire:navigate @class(['dk-side-a', 'dk-on' => $section['current']]) @if ($section['current']) aria-current="page" @endif data-label="{{ $section['label'] }}" data-side-section="{{ $section['tab'] ?: 'story' }}">
                                <flux:icon :name="$section['icon']" variant="outline" class="dk-side-ic" />
                                <span class="dk-side-l">{{ $section['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="dk-side-all" @if ($project !== null) x-show="! context" x-cloak @endif>
            @foreach ($groups as $group)
                @php
                    $flat = in_array($group['group'], ['overview', 'you'], true);
                    $holdsCurrent = collect($group['items'])->contains(fn (array $item): bool => $isCurrent($item['route']));
                    $groupCount = collect($group['items'])->sum($countOf);
                    $isDue = collect($group['items'])->contains(fn (array $item): bool => $item['module'] !== null && in_array($item['module'], $dueModules, true));
                @endphp

                <section
                    @class(['dk-side-g', 'dk-side-flat' => $flat, 'dk-side-you' => $group['group'] === 'you'])
                    data-side-group="{{ $group['group'] }}"
                    @unless ($flat) x-data="{ open: @js($holdsCurrent) || $store.hcNav.isOpen(@js($group['group'])) }" x-bind:class="{ 'dk-side-open': open }" @endunless
                >
                    @unless ($flat)
                        <button
                            type="button"
                            @class(['dk-side-a', 'dk-side-gb', 'dk-has-on' => $holdsCurrent, 'dk-due' => $isDue])
                            x-on:click="openGroup($data, @js($group['group']), $el)"
                            x-bind:aria-expanded="open.toString()"
                            aria-controls="dk-side-list-{{ $group['group'] }}"
                            data-fly="{{ $group['group'] }}"
                            data-side-group-toggle="{{ $group['group'] }}"
                            @if ($isDue) title="{{ __('navigation.due_soon') }}" data-side-due @endif
                        >
                            <span class="dk-side-icw"><flux:icon :name="$group['icon']" variant="outline" class="dk-side-ic" /></span>
                            <span class="dk-side-l">{{ $group['label'] }}</span>
                            @if ($groupCount > 0)
                                <span class="dk-side-badge dk-num" data-side-badge>{{ $badge($groupCount) }}</span>
                            @endif
                            <flux:icon.chevron-down variant="micro" class="dk-side-gchev" />
                        </button>
                    @endunless
                    <ul id="dk-side-list-{{ $group['group'] }}" @unless ($flat) x-show="open" @if (! $holdsCurrent) x-cloak @endif @endunless>
                        @foreach ($group['items'] as $item)
                            @php
                                $count = $countOf($item);
                                $current = $isCurrent($item['route']);
                            @endphp
                            <li class="dk-side-li">
                                <a
                                    href="{{ route($item['route']) }}"
                                    wire:navigate
                                    @class(['dk-side-a', 'dk-side-sub' => ! $flat, 'dk-on' => $current])
                                    @if ($current) aria-current="page" @endif
                                    data-label="{{ $item['label'] }}"
                                    data-side-link="{{ $item['route'] }}"
                                    data-desk-route="{{ $item['route'] }}"
                                >
                                    @if ($flat)
                                        <flux:icon :name="$item['hero']" variant="outline" class="dk-side-ic" />
                                    @endif
                                    <span class="dk-side-l">{{ $item['label'] }}</span>
                                    @if ($count > 0)
                                        <span class="dk-side-badge dk-num" data-side-badge>{{ $badge($count) }}</span>
                                    @endif
                                </a>
                                @if ($onDesk && $item['route'] !== 'dashboard')
                                    <x-desk.side-add :route="$item['route']" :label="$item['label']" />
                                @endif
                                @unless ($flat)
                                    <x-desk.side-pin :route="$item['route']" :label="$item['label']" />
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{-- Fly-out for the rail: the group's links beside the rail, with counts and pins. --}}
                @unless ($flat)
                    <div class="dk-side-fly" x-show="fly === @js($group['group'])" x-cloak x-bind:style="flyStyle" x-on:mouseenter="keepFly()" x-on:mouseleave="leave()" role="menu" aria-label="{{ $group['label'] }}" data-side-fly="{{ $group['group'] }}">
                        <p class="dk-side-fly-h">{{ $group['label'] }}</p>
                        @foreach ($group['items'] as $item)
                            @php $count = $countOf($item); @endphp
                            <div class="dk-side-li">
                            <a href="{{ route($item['route']) }}" wire:navigate role="menuitem" @class(['dk-side-a', 'dk-side-sub', 'dk-on' => $isCurrent($item['route'])]) @if ($isCurrent($item['route'])) aria-current="page" @endif data-desk-route="{{ $item['route'] }}">
                                <flux:icon :name="$item['hero']" variant="outline" class="dk-side-fly-ic" />
                                <span class="dk-side-l">{{ $item['label'] }}</span>
                                @if ($count > 0)
                                    <span class="dk-side-badge dk-num">{{ $badge($count) }}</span>
                                @endif
                            </a>
                            @if ($onDesk)
                                <x-desk.side-add :route="$item['route']" :label="$item['label']" />
                            @endif
                            <x-desk.side-pin :route="$item['route']" :label="$item['label']" />
                            </div>
                        @endforeach
                    </div>
                @endunless

                {{-- After the main links: the viewer's pinned pages and recent records. --}}
                @if ($group['group'] === 'overview')
                    <section class="dk-side-g dk-side-me" data-side-personal>
                        <button type="button" class="dk-side-a dk-side-gb dk-side-star" data-fly="personal" x-on:click="toggle()" data-label="{{ __('navigation.pinned_and_recent') }}" aria-label="{{ __('navigation.pinned_and_recent') }}">
                            <span class="dk-side-icw"><flux:icon name="nav-pin" variant="outline" class="dk-side-ic" /></span>
                        </button>
                        <div class="dk-side-me-list">
                            <p class="dk-side-gh">{{ __('navigation.pinned') }}</p>
                            @include('components.desk.side-personal', ['recent' => $recent])
                        </div>
                    </section>
                    <div class="dk-side-fly" x-show="fly === 'personal'" x-cloak x-bind:style="flyStyle" x-on:mouseenter="keepFly()" x-on:mouseleave="leave()" role="menu" aria-label="{{ __('navigation.pinned_and_recent') }}" data-side-fly="personal">
                        <p class="dk-side-fly-h">{{ __('navigation.pinned') }}</p>
                        @include('components.desk.side-personal', ['recent' => $recent])
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    <template x-teleport="body">
        <div class="dk-scope">
            <div class="dk-side-tip" x-show="tipText !== ''" x-cloak x-bind:style="`top:${tipTop}px;${tipSide}:${tipInset}px`" x-text="tipText" role="tooltip"></div>
        </div>
    </template>
</nav>

<div class="dk-side-scrim dk-scope" x-data x-on:click="window.hcSideNavClose?.()" data-side-scrim></div>
