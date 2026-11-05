@props(['showsInbox' => false])

@php
    $user = auth()->user();
    $current = \App\Enums\Locale::current();
    $other = $current === \App\Enums\Locale::Arabic ? \App\Enums\Locale::English : \App\Enums\Locale::Arabic;
    $otherName = $other === \App\Enums\Locale::Arabic ? 'العربية' : 'English';
    $routeName = request()->route()?->getName() ?? '';
    $where = $user ? app(\App\Support\Desk\Destinations::class)->currentFor($user, $routeName) : null;
    $guidelineUrl = 'https://docs.google.com/document/d/1-cQQQrmqRirFbkwU22RL9YkpIY1_D8n8/edit';
    $reportUrl = 'https://forms.gle/5QfGGarkeejAe3HS6';
@endphp

{{--
    The top bar, in three zones: where you are (the page's sidebar group and link on a dot-thread),
    the Ask bar (with the Panels button on My desk), then the bell, the Help menu (tour, guide, report
    a problem, install), the language and theme pair, and the avatar menu. Below 560px the language
    and theme pair moves into the avatar menu. The bar gains a shadow once the page scrolls (top-bar.js).
--}}
<header class="dk-top dk-scope" data-desk-top-bar data-top-bar-actions>
    <div class="dk-top-in" id="top">
        <div class="dk-top-start">
            <button type="button" class="dk-iconbtn dk-top-menu" x-data x-on:click="window.hcSideNavOpenMobile?.()" aria-label="{{ __('navigation.open_menu') }}" data-side-nav-open>
                <flux:icon.bars-3 variant="outline" class="size-5" />
            </button>

            <a class="dk-top-mark" href="{{ route('dashboard') }}" wire:navigate aria-label="{{ __('desk.logo_label') }}">
                <img src="{{ asset('logo-tracking-icon.svg') }}" alt="" data-no-dim />
            </a>

            @if ($where)
                <nav class="dk-where" aria-label="{{ __('ui.breadcrumb') }}" data-top-where>
                    <ol>
                        <li class="dk-where-g"><i aria-hidden="true"></i><span>{{ $where['group_label'] }}</span></li>
                        <li class="dk-where-p">
                            <i aria-hidden="true"></i>
                            <a href="{{ route($where['route']) }}" wire:navigate @if ($where['route'] === $routeName) aria-current="page" @endif>{{ $where['label'] }}</a>
                        </li>
                    </ol>
                </nav>
            @endif
        </div>

        <div class="dk-top-find">
            <button type="button" class="dk-ask" x-data x-on:click="$dispatch('dk-ask-open')" aria-label="{{ __('desk.ask_placeholder') }}" aria-keyshortcuts="Control+K Meta+K /" data-desk-ask>
                <span class="dk-inner">
                    <flux:icon.magnifying-glass variant="outline" class="size-5" />
                    <span class="dk-ph">{{ __('desk.ask_placeholder') }}</span>
                </span>
            </button>

            {{-- Panels belong to My desk only (user decision 2026-10-28). --}}
            @if ($showsInbox && request()->routeIs('dashboard'))
                <button type="button" class="dk-btn dk-panels-btn" x-data x-on:click="$dispatch('dk-panels-open')" aria-haspopup="dialog" data-panels-trigger>
                    <flux:icon.squares-2x2 variant="outline" class="size-5" />
                    <span class="dk-panels-l">{{ __('navigation.panels') }}</span>
                </button>
            @endif
        </div>

        <div class="dk-top-end">
            <div class="dk-tools" role="group" aria-label="{{ __('navigation.tools') }}" data-desk-tools>
                @if ($showsInbox)
                    <livewire:notifications.bell :can-manage-rules="(bool) $user?->can('notification-settings.manage')" />
                @endif

                <div class="dk-pmenu dk-helpmenu" x-data="hcTopMenu" x-on:click.outside="close()" x-on:keydown.escape.stop="close(true)" data-tour="help" data-help-menu>
                    <button type="button" class="dk-iconbtn dk-tip" x-ref="trigger" x-on:click="toggle()" x-on:keydown.arrow-down.prevent="show(true)" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" aria-label="{{ __('navigation.help') }}" data-tip="{{ __('navigation.help') }}" data-help-trigger>
                        <flux:icon.question-mark-circle variant="outline" class="size-5" />
                        <span class="dk-fresh" x-data="hcTourTrigger" x-show="fresh" x-on:hc-tour-seen.window="fresh = false" x-cloak aria-hidden="true"></span>
                    </button>
                    <div class="dk-pop dk-menu dk-helpmenu-pop" role="menu" aria-label="{{ __('navigation.help') }}" x-ref="menu" x-show="open" x-cloak x-on:keydown="navigate($event)" x-transition:enter="dk-menu-in" x-transition:enter-start="dk-menu-from" x-transition:leave="dk-menu-out" x-transition:leave-end="dk-menu-from">
                        <p class="dk-pop-h">{{ __('navigation.help') }}</p>
                        <button type="button" role="menuitem" x-on:click="startTour()" data-test="tour-start-button">
                            <flux:icon.sparkles variant="outline" class="dk-menu-ic" />
                            <span class="dk-menu-t"><strong>{{ __('tour.start') }}</strong><small>{{ __('navigation.help_tour_hint') }}</small></span>
                        </button>
                        <a role="menuitem" href="{{ route('help.ai-features') }}" wire:navigate x-on:click="close()" data-ai-features-link>
                            <flux:icon.cpu-chip variant="outline" class="dk-menu-ic" />
                            <span class="dk-menu-t"><strong>{{ __('navigation.ai_features') }}</strong><small>{{ __('navigation.help_ai_hint') }}</small></span>
                        </a>
                        <a role="menuitem" href="{{ $guidelineUrl }}" target="_blank" rel="noopener noreferrer" x-on:click="close()" data-guideline>
                            <flux:icon.book-open variant="outline" class="dk-menu-ic" />
                            <span class="dk-menu-t"><strong>{{ __('navigation.guideline') }}</strong><small>{{ __('navigation.help_guideline_hint') }}</small></span>
                            <flux:icon.arrow-up-right variant="micro" class="dk-menu-ext dk-flip" />
                        </a>
                        <a role="menuitem" href="{{ $reportUrl }}" target="_blank" rel="noopener noreferrer" x-on:click="close()" data-report-bug>
                            <flux:icon.bug-ant variant="outline" class="dk-menu-ic" />
                            <span class="dk-menu-t"><strong>{{ __('navigation.report_a_problem') }}</strong><small>{{ __('navigation.help_report_hint') }}</small></span>
                            <flux:icon.arrow-up-right variant="micro" class="dk-menu-ext dk-flip" />
                        </a>
                        <span x-data="{ state: window.hcApp?.state() ?? 'unavailable' }" x-on:hc-app-install-change.window="state = $event.detail" x-show="state === 'available'" x-cloak class="contents">
                            <button type="button" role="menuitem" x-on:click="close(); $flux.modal('install-app').show()" aria-haspopup="dialog" data-test="top-bar-install-app">
                                <flux:icon.arrow-down-tray variant="outline" class="dk-menu-ic" />
                                <span class="dk-menu-t"><strong>{{ __('desk.install_app') }}</strong><small>{{ __('navigation.help_install_hint') }}</small></span>
                            </button>
                        </span>
                    </div>
                </div>

                <div class="dk-seg" role="group" aria-label="{{ __('navigation.display') }}">
                    <form method="POST" action="{{ route('locale.update') }}" class="contents" data-locale-toggle>
                        @csrf
                        <button type="submit" name="locale" value="{{ $other->value }}" class="dk-iconbtn dk-tip dk-seg-lang" aria-label="{{ __('desk.switch_language_to', ['language' => $otherName]) }}" data-tip="{{ $otherName }}" lang="{{ $other->value }}">
                            {{ $other === \App\Enums\Locale::Arabic ? 'ع' : 'EN' }}
                        </button>
                    </form>

                    <button type="button" class="dk-iconbtn dk-tip dk-seg-theme" x-data x-on:click="hcToggleAppearance()" aria-label="{{ __('common.appearance') }}" data-tip="{{ __('common.appearance') }}" data-theme-toggle>
                        <flux:icon.sun variant="outline" class="size-[18px]" x-show="! $flux.dark" x-cloak />
                        <flux:icon.moon variant="outline" class="size-[18px]" x-show="$flux.dark" x-cloak />
                    </button>
                </div>
            </div>

            @auth
                <div class="dk-pmenu dk-usermenu" x-data="hcTopMenu" x-on:click.outside="close()" x-on:keydown.escape.stop="close(true)" data-tour="profile" data-user-menu>
                    <button type="button" class="dk-me" x-ref="trigger" x-on:click="toggle()" x-on:keydown.arrow-down.prevent="show(true)" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" aria-label="{{ __('desk.account_menu') }}" data-user-menu-trigger>
                        <x-ui.avatar :src="$user?->avatarUrl()" class="dk-avatar" />
                    </button>
                    <div class="dk-pop dk-menu dk-usermenu-pop" role="menu" aria-label="{{ __('desk.account_menu') }}" x-ref="menu" x-show="open" x-cloak x-on:keydown="navigate($event)" x-transition:enter="dk-menu-in" x-transition:enter-start="dk-menu-from" x-transition:leave="dk-menu-out" x-transition:leave-end="dk-menu-from">
                        <div class="dk-usermenu-who">
                            <x-ui.avatar :src="$user?->avatarUrl()" class="dk-avatar dk-avatar-lg" />
                            <span class="min-w-0">
                                <strong>{{ $user->name }}</strong>
                                <small>{{ $user->email }}</small>
                            </span>
                        </div>
                        <a role="menuitem" href="{{ route('security.edit') }}" wire:navigate x-on:click="close()" data-test="security-menu-item">
                            <flux:icon.lock-closed variant="outline" class="dk-menu-ic" />
                            <span class="dk-menu-t"><strong>{{ __('common.security') }}</strong></span>
                        </a>
                        {{-- Below 560px the language and theme pair leaves the bar and sits here. --}}
                        <div class="dk-narrow-tools">
                            <form method="POST" action="{{ route('locale.update') }}" class="contents">
                                @csrf
                                <button type="submit" role="menuitem" name="locale" value="{{ $other->value }}" lang="{{ $other->value }}">
                                    <flux:icon.language variant="outline" class="dk-menu-ic" />
                                    <span class="dk-menu-t"><strong>{{ $otherName }}</strong></span>
                                </button>
                            </form>
                            <button type="button" role="menuitem" x-on:click="hcToggleAppearance()">
                                <flux:icon.sun variant="outline" class="dk-menu-ic" x-show="! $flux.dark" x-cloak />
                                <flux:icon.moon variant="outline" class="dk-menu-ic" x-show="$flux.dark" x-cloak />
                                <span class="dk-menu-t"><strong>{{ __('common.appearance') }}</strong></span>
                            </button>
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="dk-usermenu-out">
                            @csrf
                            <button type="submit" role="menuitem" class="dk-danger" data-test="logout-button">
                                <flux:icon.arrow-right-start-on-rectangle variant="outline" class="dk-menu-ic dk-flip" />
                                <span class="dk-menu-t"><strong>{{ __('common.log_out') }}</strong></span>
                            </button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</header>
