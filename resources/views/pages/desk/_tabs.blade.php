@php
    $deskUser = auth()->user();
    $tabPages = collect(app(\App\Support\Desk\Destinations::class)->for($deskUser))
        ->reject(fn (array $page): bool => $page['route'] === 'dashboard' || ! \Illuminate\Support\Facades\Route::has($page['route']))
        ->mapWithKeys(fn (array $page): array => [parse_url(route($page['route']), PHP_URL_PATH) ?: '/' => ['icon' => $page['icon'], 'label' => $page['label']]])
        ->all();
    $tabsConfig = [
        'user' => (int) $deskUser->id,
        'desk' => parse_url(route('dashboard'), PHP_URL_PATH) ?: '/',
        'param' => \App\Support\Desk\DeskTab::Param,
        'pages' => $tabPages,
        'texts' => [
            'loading' => __('desk-workspace.tabs.loading'),
            'close' => __('desk-workspace.tabs.close', ['tab' => '__T__']),
            'untitled' => __('desk-workspace.tabs.untitled'),
            'opened_background' => __('desk-workspace.tabs.opened_background', ['tab' => '__T__']),
            'closed' => __('desk-workspace.tabs.closed', ['tab' => '__T__']),
        ],
    ];
@endphp

{{--
    Desk tabs (resources/js/desk-tabs.js): on My desk, a page opened from a panel, a button,
    the sidebar or the Ask bar loads in a tab here instead of leaving the Desk. The Desk is
    always the first tab; every other tab is the real page in a frame, drawn without its own
    sidebar and top bar (App\Support\Desk\DeskTab), so it works exactly as it does on its own.
    Tabs are kept in this browser and come back after a reload; only the tab in front loads.
    wire:ignore: the Desk re-renders often and must never reload a tab's frame.
--}}
<div class="dk-tabs" wire:ignore x-data="hcDeskTabs(@js($tabsConfig))" x-bind:class="{ 'dk-tabs-on': tabs.length > 0 }" data-desk-tabs>
    <div class="dk-tabs-bar" x-show="tabs.length > 0" x-cloak x-transition.opacity.duration.150ms>
        <div class="dk-tabs-strip" role="tablist" aria-label="{{ __('desk-workspace.tabs.label') }}" x-ref="strip" x-on:keydown="onStripKey($event)" x-on:wheel.passive="scrollStrip($event)">
            <div class="dk-tab dk-tab-desk" x-bind:class="{ 'dk-on': active === 'desk' }" role="presentation">
                <button type="button" role="tab" id="dk-tab-desk" class="dk-tab-b" x-bind:aria-selected="(active === 'desk').toString()" x-bind:tabindex="active === 'desk' ? 0 : -1" aria-controls="dk-tabpanel-desk" x-on:click="activate('desk')" data-tab="desk" title="{{ __('desk.your_desk') }} (Alt+0)">
                    <x-desk.icon name="grid" class="dk-tab-ic" />
                    <span class="dk-tab-t">{{ __('desk.your_desk') }}</span>
                </button>
            </div>

            <template x-for="(tab, i) in tabs" x-bind:key="tab.id">
                <div class="dk-tab" role="presentation" x-bind:class="{ 'dk-on': active === tab.id, 'dk-loading': tab.loading, 'dk-asleep': ! tab.live && active !== tab.id, 'dk-dragging': dragging === tab.id }" x-bind:data-tab="tab.id" x-on:auxclick="if ($event.button === 1) { $event.preventDefault(); close(tab.id) }" x-on:contextmenu.prevent="openMenu(tab.id, $event)" x-on:pointerdown="startDrag($event, tab.id)">
                    <button type="button" role="tab" class="dk-tab-b" x-bind:id="'dk-tab-' + tab.id" x-bind:aria-selected="(active === tab.id).toString()" x-bind:tabindex="active === tab.id ? 0 : -1" x-bind:aria-controls="'dk-tabpanel-' + tab.id" x-bind:title="tab.title + (i < 9 ? ' (Alt+' + (i + 1) + ')' : '')" x-on:click="activate(tab.id)">
                        <span class="dk-tab-ic" aria-hidden="true">
                            <svg class="dk-i" x-show="! tab.loading"><use x-bind:href="'#dk-sym-' + tab.icon"></use></svg>
                            <span class="dk-tab-spin" x-show="tab.loading" x-cloak></span>
                        </span>
                        <span class="dk-tab-t" x-text="tab.title"></span>
                    </button>
                    <button type="button" class="dk-tab-x" tabindex="-1" x-bind:aria-label="texts.close.replace('__T__', tab.title)" x-on:click.stop="close(tab.id)" x-on:pointerdown.stop>
                        <x-desk.icon name="x" />
                    </button>
                </div>
            </template>

            {{-- While a page is carried from the sidebar or the Ask bar: where it lands as a new tab (desk-drop.js). --}}
            <div class="dk-tab-drop" aria-hidden="true" data-tab-drop>
                <x-desk.icon name="plus" class="dk-tab-ic" />
                <span class="dk-tab-t">{{ __('desk-workspace.tabs.drop_here') }}</span>
            </div>
        </div>

        <div class="dk-tabs-tools" role="group" aria-label="{{ __('desk-workspace.tabs.tools') }}">
            <button type="button" class="dk-iconbtn dk-sm" x-show="active !== 'desk'" x-on:click="reload(active)" aria-label="{{ __('desk-workspace.tabs.reload') }}" title="{{ __('desk-workspace.tabs.reload') }}" data-tabs-reload><x-desk.icon name="reload" /></button>
            <button type="button" class="dk-iconbtn dk-sm" x-show="active !== 'desk'" x-on:click="popOut(active)" aria-label="{{ __('desk-workspace.tabs.pop_out') }}" title="{{ __('desk-workspace.tabs.pop_out') }}" data-tabs-popout><x-desk.icon name="popout" /></button>
            <button type="button" class="dk-iconbtn dk-sm" x-on:click="closeAll()" aria-label="{{ __('desk-workspace.tabs.close_all') }}" title="{{ __('desk-workspace.tabs.close_all') }}" data-tabs-close-all><x-desk.icon name="x" /></button>
        </div>
    </div>

    {{-- A tab's own menu (right click): the browser's tab menu, for the Desk's tabs. --}}
    <div class="dk-pop dk-menu dk-tab-menu" role="menu" x-show="menu" x-cloak x-bind:style="menuStyle" x-on:click.outside="menu = null" x-on:keydown.escape.window="menu = null" data-tab-menu>
        <button type="button" role="menuitem" x-on:click="reload(menu); menu = null"><x-desk.icon name="reload" class="dk-menu-ic" /><span class="dk-menu-t"><strong>{{ __('desk-workspace.tabs.reload') }}</strong></span></button>
        <button type="button" role="menuitem" x-on:click="duplicate(menu); menu = null"><x-desk.icon name="plus" class="dk-menu-ic" /><span class="dk-menu-t"><strong>{{ __('desk-workspace.tabs.duplicate') }}</strong></span></button>
        <button type="button" role="menuitem" x-on:click="addToDesk(menu); menu = null" data-tab-add-to-desk><x-desk.icon name="pin" class="dk-menu-ic" /><span class="dk-menu-t"><strong>{{ __('desk-workspace.add.to_desk') }}</strong><small>{{ __('desk-workspace.tabs.add_hint') }}</small></span></button>
        <button type="button" role="menuitem" x-on:click="popOut(menu); menu = null"><x-desk.icon name="popout" class="dk-menu-ic" /><span class="dk-menu-t"><strong>{{ __('desk-workspace.tabs.pop_out') }}</strong></span></button>
        <button type="button" role="menuitem" x-on:click="closeOthers(menu); menu = null"><x-desk.icon name="x" class="dk-menu-ic" /><span class="dk-menu-t"><strong>{{ __('desk-workspace.tabs.close_others') }}</strong></span></button>
        <button type="button" role="menuitem" class="dk-danger" x-on:click="close(menu); menu = null"><x-desk.icon name="x" class="dk-menu-ic" /><span class="dk-menu-t"><strong>{{ __('desk-workspace.tabs.close_tab') }}</strong></span></button>
    </div>

    {{-- The tabs' frames: created by desk-tabs.js when a tab is first shown, then kept alive while it is open. --}}
    <div class="dk-tabframes" x-ref="frames" x-show="active !== 'desk'" x-cloak x-bind:class="{ 'dk-loading': activeLoading() }" data-desk-tabframes></div>

    <p class="dk-sr" aria-live="polite" x-text="announce"></p>
</div>
