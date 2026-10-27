@props(['showsInbox' => false])

@php
    $user = auth()->user();
    $groups = app(\App\Support\Desk\Destinations::class)->sidebarFor($user);
    $moduleCounts = $showsInbox ? app(\App\Notifications\UnreadCounts::class)->for($user)['modules'] : [];
    $currentRoute = request()->route()?->getName() ?? '';
    /* A register's own sub-pages (projects.show…) light up their register's link, unless the page has a link of its own (reports.monthly-matrix is not reports.index). */
    $hasExactLink = collect($groups)->pluck('items')->flatten(1)->contains('route', $currentRoute);
    $isCurrent = fn (string $route): bool => $currentRoute === $route
        || (! $hasExactLink && $route !== 'dashboard' && str_ends_with($route, '.index') && str_starts_with($currentRoute, \Illuminate\Support\Str::beforeLast($route, '.index').'.'));
@endphp

{{--
    The application sidebar: every page the viewer's role may open (App\Support\Desk\Destinations),
    grouped like the old sidebar. It rests as an icon rail; the toggle expands it to labels and the
    choice is kept in localStorage (hc-nav) and on <html class="dk-nav-open">, set by an inline script
    in partials/head before first paint so a navigation never flashes the other state. Below 900px it is
    hidden and the top bar's menu button lays it over the page (html.dk-nav-mobile).
--}}
<nav class="dk-side dk-scope" aria-label="{{ __('navigation.application_navigation') }}" x-data="hcSideNav" data-side-nav>
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

    <div class="dk-side-scroll" x-ref="scroll" x-on:scroll.passive="remember()" x-on:mouseover="tip($event)" x-on:mouseleave="untip()" x-on:focusin="tip($event)" x-on:focusout="untip()">
        @foreach ($groups as $group)
            <section class="dk-side-g" data-side-group="{{ $group['group'] }}">
                <p class="dk-side-gh">{{ $group['label'] }}</p>
                <ul>
                    @foreach ($group['items'] as $item)
                        @php
                            $count = $item['module'] !== null ? (int) ($moduleCounts[$item['module']] ?? 0) : 0;
                            $current = $isCurrent($item['route']);
                        @endphp
                        <li>
                            <a
                                href="{{ route($item['route']) }}"
                                wire:navigate
                                @class(['dk-side-a', 'dk-on' => $current])
                                @if ($current) aria-current="page" @endif
                                data-label="{{ $item['label'] }}"
                                data-side-link="{{ $item['route'] }}"
                            >
                                <flux:icon :name="$item['hero']" variant="outline" class="dk-side-ic" />
                                <span class="dk-side-l">{{ $item['label'] }}</span>
                                @if ($count > 0)
                                    <span class="dk-side-badge dk-num" data-side-badge>{{ $count > 99 ? '99+' : $count }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>

    <template x-teleport="body">
        <div class="dk-scope">
            <div class="dk-side-tip" x-show="tipText !== ''" x-cloak x-bind:style="`top:${tipTop}px;${tipSide}:${tipInset}px`" x-text="tipText" role="tooltip"></div>
        </div>
    </template>
</nav>

<div class="dk-side-scrim dk-scope" x-data x-on:click="window.hcSideNavClose?.()" data-side-scrim></div>
