@php
    $navigationGroupClasses = '[&>div:first-child>div]:text-[11px]! [&>div:first-child>div]:font-semibold! [&>div:first-child>div]:tracking-[0.12em] [&>div:first-child>div]:text-secondary-light! [&>div:first-child>div]:uppercase dark:[&>div:first-child>div]:text-secondary-soft!';
    $navigationItemClasses = 'touch-manipulation rounded-lg! text-secondary-dark! hover:bg-secondary-soft/70! max-lg:h-11! data-current:border-primary! data-current:bg-primary! data-current:font-semibold! data-current:text-white! data-current:shadow-sm! dark:text-secondary-soft! dark:hover:bg-white/10! dark:hover:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!';
@endphp

<flux:sidebar
    sticky
    collapsible="mobile"
    aria-label="{{ __('navigation.application_navigation') }}"
    data-navigation-sidebar
    x-on:keydown.escape.window="if (window.matchMedia('(max-width: 63.999rem)').matches && ! $el.hasAttribute('data-flux-sidebar-collapsed-mobile')) { $dispatch('flux-sidebar-toggle') }"
    class="gap-3! border-e border-secondary-soft bg-white p-3! text-secondary-dark shadow-[1px_0_0_0_rgba(0,0,0,0.02)] duration-200 ease-out motion-reduce:duration-0 motion-reduce:transition-none max-lg:w-[min(18rem,calc(100vw_-_2.75rem))]! max-lg:shadow-2xl lg:w-[clamp(16rem,18vw,18rem)]! dark:border-secondary-medium dark:bg-secondary-dark dark:text-white dark:shadow-none"
>
    <flux:sidebar.header class="min-h-11 border-b border-secondary-soft pb-3 dark:border-secondary-medium/60">
        <x-app-logo
            :sidebar="true"
            :href="route('dashboard')"
            class="touch-manipulation text-secondary-dark hover:bg-secondary-soft/70 max-lg:min-h-11 dark:text-white dark:hover:bg-white/10"
            wire:navigate
        />

        <div class="ms-auto flex shrink-0 items-center gap-1" data-sidebar-preferences>
            <flux:button
                x-data
                x-on:click="$flux.dark = ! $flux.dark"
                variant="ghost"
                size="sm"
                square
                class="size-9! touch-manipulation text-secondary-medium! hover:bg-secondary-soft/70! hover:text-secondary-dark! max-lg:size-11! dark:text-secondary-soft! dark:hover:bg-white/10! dark:hover:text-white!"
                :aria-label="__('navigation.toggle_theme')"
                :tooltip="__('navigation.toggle_theme')"
                data-theme-toggle
            >
                <flux:icon.moon x-show="! $flux.dark" variant="mini" />
                <flux:icon.sun x-show="$flux.dark" x-cloak variant="mini" />
            </flux:button>

            @php($otherLocale = \App\Enums\Locale::current()->other())
            <form method="POST" action="{{ route('locale.update') }}" class="contents">
                @csrf
                <input type="hidden" name="locale" value="{{ $otherLocale->value }}">
                <flux:button
                    type="submit"
                    variant="ghost"
                    size="sm"
                    square
                    class="size-9! touch-manipulation text-sm! font-semibold! text-secondary-medium! hover:bg-secondary-soft/70! hover:text-secondary-dark! max-lg:size-11! dark:text-secondary-soft! dark:hover:bg-white/10! dark:hover:text-white!"
                    :aria-label="__('navigation.switch_language')"
                    :tooltip="__('navigation.switch_language')"
                    data-locale-toggle
                >
                    <span lang="{{ $otherLocale->value }}">{{ $otherLocale === \App\Enums\Locale::Arabic ? 'ع' : 'EN' }}</span>
                </flux:button>
            </form>
        </div>

        <flux:sidebar.collapse
            class="size-11! touch-manipulation text-secondary-medium lg:hidden dark:text-secondary-soft"
            data-mobile-navigation-close
        />
    </flux:sidebar.header>

    <flux:sidebar.nav aria-label="{{ __('navigation.primary_navigation') }}" class="min-h-0 flex-1 gap-3 overflow-y-auto">
        <flux:sidebar.group
            :heading="__('navigation.overview')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="home"
                :href="route('dashboard')"
                :current="request()->routeIs('dashboard')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.dashboard') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="chart-pie"
                :href="route('executive-summary.index')"
                :current="request()->routeIs('executive-summary.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.executive_summary') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('common.strategy')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="chart-bar-square"
                :href="route('strategies.index')"
                :current="request()->routeIs('strategies.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.strategies') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="briefcase"
                :href="route('programs.index')"
                :current="request()->routeIs('programs.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.programs') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="calendar-days"
                :href="route('yearly-plans.index')"
                :current="request()->routeIs('yearly-plans.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.annual_plans') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('common.delivery')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="rectangle-stack"
                :href="route('projects.index')"
                :current="request()->routeIs('projects.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.projects') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="flag"
                :href="route('milestones.index')"
                :current="request()->routeIs('milestones.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.milestones') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="clipboard-document-check"
                :href="route('tasks.index')"
                :current="request()->routeIs('tasks.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.tasks') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="exclamation-triangle"
                :href="route('risks.index')"
                :current="request()->routeIs('risks.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.risks') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('navigation.results_learning')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="chart-bar"
                :href="route('results.index')"
                :current="request()->routeIs('results.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.results_framework') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="variable"
                :href="route('indicators.index')"
                :current="request()->routeIs('indicators.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.indicators') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="flag"
                :href="route('targets.index')"
                :current="request()->routeIs('targets.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.targets') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="document-chart-bar"
                :href="route('reports.index')"
                :current="request()->routeIs('reports.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.reports') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('common.funding')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="document-text"
                :href="route('proposals.index')"
                :current="request()->routeIs('proposals.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.proposals') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="building-library"
                :href="route('donors.index')"
                :current="request()->routeIs('donors.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.donors') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="banknotes"
                :href="route('funding-agreements.index')"
                :current="request()->routeIs('funding-agreements.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.funding_agreements') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="arrow-right-circle"
                :href="route('transfers.index')"
                :current="request()->routeIs('transfers.*')"
                :class="$navigationItemClasses.' rtl:[&_svg]:-scale-x-100'"
                wire:navigate
            >
                {{ __('common.transfers') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('navigation.administration')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="credit-card"
                :href="route('admin.accounts.index')"
                :current="request()->routeIs('admin.accounts.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.accounts') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="map-pin"
                :href="route('admin.cities.index')"
                :current="request()->routeIs('admin.cities.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.cities') }}
            </flux:sidebar.item>

            @if (auth()->user()?->getRawOriginal('role') !== null)
                @can('viewAny', App\Models\User::class)
                    <flux:sidebar.item
                        icon="users"
                        :href="route('admin.users.index')"
                        :current="request()->routeIs('admin.users.*')"
                        :class="$navigationItemClasses"
                        wire:navigate
                    >
                        {{ __('navigation.user_directory') }}
                    </flux:sidebar.item>
                @endcan
            @endif
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('common.settings')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="user-circle"
                :href="route('profile.edit')"
                :current="request()->routeIs('profile.edit')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.profile') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="paint-brush"
                :href="route('appearance.edit')"
                :current="request()->routeIs('appearance.edit')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.appearance') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="shield-check"
                :href="route('security.edit')"
                :current="request()->routeIs('security.edit')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.security') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    </flux:sidebar.nav>

    <div class="hidden border-t border-secondary-soft pt-3 lg:block dark:border-secondary-medium/60">
        <x-desktop-user-menu :name="auth()->user()->name" />
    </div>
</flux:sidebar>
