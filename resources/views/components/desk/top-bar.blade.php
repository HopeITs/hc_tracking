@props(['showsInbox' => false])

@php
    $user = auth()->user();
    $current = \App\Enums\Locale::current();
    $other = $current === \App\Enums\Locale::Arabic ? \App\Enums\Locale::English : \App\Enums\Locale::Arabic;
    $initials = collect(preg_split('/\s+/u', trim((string) $user?->name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
    $guidelineUrl = 'https://docs.google.com/document/d/1-cQQQrmqRirFbkwU22RL9YkpIY1_D8n8/edit';
    $reportUrl = 'https://forms.gle/5QfGGarkeejAe3HS6';
@endphp

{{--
    The top bar: menu button (narrow screens), the Ask bar, the Panels button, then the old header's
    tools (bell, install, report a problem, guideline, tour, language, theme) and the avatar menu.
    Below 1100px the install / report / guideline / tour tools fold into the avatar menu.
--}}
<header class="dk-top dk-scope" data-desk-top-bar data-top-bar-actions>
    <div class="dk-top-in" id="top">
        <button type="button" class="dk-iconbtn dk-top-menu" x-data x-on:click="window.hcSideNavOpenMobile?.()" aria-label="{{ __('navigation.open_menu') }}" data-side-nav-open>
            <flux:icon.bars-3 variant="outline" class="size-5" />
        </button>

        <a class="dk-top-mark" href="{{ route('dashboard') }}" wire:navigate aria-label="{{ __('desk.logo_label') }}">
            <img src="{{ asset('logo-tracking-icon.svg') }}" alt="" data-no-dim />
        </a>

        <div class="dk-top-find">
            <button type="button" class="dk-ask" x-data x-on:click="$dispatch('dk-ask-open')" aria-label="{{ __('desk.ask_placeholder') }}" data-desk-ask>
                <span class="dk-inner">
                    <flux:icon.magnifying-glass variant="outline" class="size-5" />
                    <span class="dk-ph">{{ __('desk.ask_placeholder') }}</span>
                    <kbd>/</kbd>
                </span>
            </button>

            @if ($showsInbox)
                <button type="button" class="dk-btn dk-panels-btn" x-data x-on:click="$dispatch('dk-panels-open')" aria-haspopup="dialog" data-panels-trigger>
                    <flux:icon.squares-2x2 variant="outline" class="size-5" />
                    <span class="dk-panels-l">{{ __('navigation.panels') }}</span>
                </button>
            @endif
        </div>

        <div class="dk-tools dk-capsule" role="toolbar" aria-label="{{ __('navigation.tools') }}" data-desk-tools>
            @if ($showsInbox)
                <livewire:notifications.bell :can-manage-rules="(bool) $user?->can('notification-settings.manage')" />
            @endif

            <span class="dk-wide-tool" x-data="{ state: window.hcApp?.state() ?? 'unavailable' }" x-on:hc-app-install-change.window="state = $event.detail" x-show="state === 'available'" x-cloak>
                <button type="button" class="dk-iconbtn" x-on:click="$flux.modal('install-app').show()" aria-haspopup="dialog" aria-label="{{ __('desk.install_app') }}" title="{{ __('desk.install_app') }}" data-test="top-bar-install-app">
                    <flux:icon.arrow-down-tray variant="outline" class="size-[18px]" />
                </button>
            </span>

            <a class="dk-iconbtn dk-wide-tool" href="{{ $reportUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('navigation.report_a_problem') }}" title="{{ __('navigation.report_a_problem') }}" data-report-bug>
                <flux:icon.bug-ant variant="outline" class="size-[18px]" />
            </a>

            <a class="dk-iconbtn dk-wide-tool" href="{{ $guidelineUrl }}" target="_blank" rel="noopener noreferrer" aria-label="{{ __('navigation.guideline') }}" title="{{ __('navigation.guideline') }}" data-guideline>
                <flux:icon.book-open variant="outline" class="size-[18px]" />
            </a>

            <button type="button" class="dk-iconbtn dk-tourbtn dk-wide-tool" x-data="hcTourTrigger" x-on:click="begin()" x-on:hc-tour-seen.window="fresh = false" aria-haspopup="dialog" aria-label="{{ __('tour.start') }}" title="{{ __('tour.start') }}" data-tour="help" data-test="tour-start-button">
                <flux:icon.sparkles variant="outline" class="size-[18px]" />
            </button>

            <form method="POST" action="{{ route('locale.update') }}" class="contents" data-locale-toggle>
                @csrf
                <button type="submit" name="locale" value="{{ $other->value }}" class="dk-iconbtn" aria-label="{{ __('desk.switch_language_to', ['language' => $other === \App\Enums\Locale::Arabic ? 'العربية' : 'English']) }}" lang="{{ $other->value }}">
                    {{ $other === \App\Enums\Locale::Arabic ? 'ع' : 'EN' }}
                </button>
            </form>

            <button type="button" class="dk-iconbtn" x-data x-on:click="hcToggleAppearance()" aria-label="{{ __('common.appearance') }}" data-theme-toggle>
                <flux:icon.sun variant="outline" class="size-[18px]" x-show="! $flux.dark" x-cloak />
                <flux:icon.moon variant="outline" class="size-[18px]" x-show="$flux.dark" x-cloak />
            </button>
        </div>

        <div class="dk-tools">
            @auth
                <div class="dk-pmenu dk-usermenu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" data-tour="profile" data-user-menu>
                    <button type="button" class="dk-iconbtn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" aria-label="{{ __('desk.account_menu') }}" data-user-menu-trigger>
                        <span class="dk-avatar">{{ $initials }}</span>
                    </button>
                    <div class="dk-pop dk-usermenu-pop" role="menu" x-show="open" x-cloak x-transition.opacity.duration.120ms>
                        <div class="dk-usermenu-who">
                            <span class="dk-avatar">{{ $initials }}</span>
                            <span class="min-w-0">
                                <strong>{{ $user->name }}</strong>
                                <small>{{ $user->email }}</small>
                            </span>
                        </div>
                        <a role="menuitem" href="{{ route('security.edit') }}" wire:navigate data-test="security-menu-item"><x-desk.icon name="lock" />{{ __('common.security') }}</a>
                        <a role="menuitem" href="{{ route('notification-settings.edit') }}" wire:navigate><x-desk.icon name="bell" />{{ __('desk.my_notifications') }}</a>
                        @can('notification-settings.manage')
                            <a role="menuitem" href="{{ route('admin.notification-settings.edit') }}" wire:navigate data-user-menu-rules><flux:icon.adjustments-horizontal variant="outline" class="size-[18px]" />{{ __('navigation.notification_settings') }}</a>
                        @endcan
                        <div class="dk-narrow-tools">
                            <span x-data="{ state: window.hcApp?.state() ?? 'unavailable' }" x-on:hc-app-install-change.window="state = $event.detail" x-show="state === 'available'" x-cloak class="contents">
                                <button type="button" role="menuitem" x-on:click="open = false; $flux.modal('install-app').show()" data-test="settings-install-app"><x-desk.icon name="download" />{{ __('desk.install_app') }}</button>
                            </span>
                            <a role="menuitem" href="{{ $reportUrl }}" target="_blank" rel="noopener noreferrer"><x-desk.icon name="info" />{{ __('navigation.report_a_problem') }}</a>
                            <a role="menuitem" href="{{ $guidelineUrl }}" target="_blank" rel="noopener noreferrer"><x-desk.icon name="file" />{{ __('navigation.guideline') }}</a>
                            <button type="button" role="menuitem" x-data="hcTourTrigger" x-on:click="begin()"><x-desk.icon name="spark" />{{ __('tour.start') }}</button>
                        </div>
                        <form method="POST" action="{{ route('logout') }}" class="dk-usermenu-out">
                            @csrf
                            <button type="submit" role="menuitem" class="dk-danger" data-test="logout-button"><x-desk.icon name="back" class="dk-flip" />{{ __('common.log_out') }}</button>
                        </form>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</header>
