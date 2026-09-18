@php
    $navigationGroupClasses = '[&>div:first-child>div]:text-[11px]! [&>div:first-child>div]:font-semibold! [&>div:first-child>div]:tracking-[0.12em] [&>div:first-child>div]:text-secondary-light! [&>div:first-child>div]:uppercase dark:[&>div:first-child>div]:text-secondary-soft! in-data-flux-sidebar-collapsed-desktop:flex! in-data-flux-sidebar-collapsed-desktop:[&>div:first-child]:hidden!';
    $navigationItemClasses = 'touch-manipulation rounded-lg! text-secondary-dark! hover:bg-secondary-soft/70! max-lg:h-11! data-current:border-primary! data-current:bg-primary! data-current:font-semibold! data-current:text-white! data-current:shadow-sm! dark:text-secondary-soft! dark:hover:bg-white/10! dark:hover:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!';
@endphp

<flux:sidebar
    sticky
    :collapsible="true"
    aria-label="{{ __('navigation.application_navigation') }}"
    data-navigation-sidebar
    x-on:keydown.escape.window="if (window.matchMedia('(max-width: 63.999rem)').matches && ! $el.hasAttribute('data-flux-sidebar-collapsed-mobile')) { $dispatch('flux-sidebar-toggle') }"
    class="relative gap-3! overflow-visible! border-e border-secondary-soft bg-white p-3! text-secondary-dark shadow-[1px_0_0_0_rgba(0,0,0,0.02)] duration-200 ease-out motion-reduce:duration-0 motion-reduce:transition-none max-lg:w-[min(18rem,calc(100vw_-_2.75rem))]! max-lg:shadow-2xl lg:w-[clamp(16rem,18vw,18rem)]! dark:border-secondary-medium dark:bg-secondary-dark dark:text-white dark:shadow-none data-[flux-sidebar-collapsed-desktop]:lg:w-[4.5rem]! data-[flux-sidebar-collapsed-desktop]:px-2! data-[flux-sidebar-collapsed-desktop]:cursor-default! data-[flux-sidebar-collapsed-desktop]:gap-4!"
>
    <div
        x-data
        x-on:click="$dispatch('flux-sidebar-toggle')"
        class="absolute -end-4 top-7 z-20 hidden size-8 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-full border border-secondary-soft bg-white text-secondary-medium shadow-[0_1px_2px_rgba(16,24,40,0.08)] hover:text-secondary-dark lg:flex dark:border-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft dark:hover:text-white"
        role="button"
        tabindex="0"
        x-on:keydown.enter="$dispatch('flux-sidebar-toggle')"
        x-on:keydown.space.prevent="$dispatch('flux-sidebar-toggle')"
        {{-- Interpolated, not Blade's :attr shorthand: that shorthand is for Blade components, so on a plain div Alpine claims :aria-label as x-bind and evaluates __() as JavaScript, throwing and leaving the toggle unnamed. --}}
        aria-label="{{ __('navigation.toggle_sidebar') }}"
        data-desktop-navigation-collapse
    >
        <flux:icon.chevron-left class="size-4 in-data-flux-sidebar-collapsed-desktop:rotate-180 rtl:rotate-180 in-data-flux-sidebar-collapsed-desktop:rtl:rotate-0 transition-transform" />
    </div>

    <flux:sidebar.header class="min-h-11 border-b border-secondary-soft pb-3 in-data-flux-sidebar-collapsed-desktop:pb-4! dark:border-secondary-medium/60">
        <x-app-logo
            :sidebar="true"
            :href="route('dashboard')"
            class="touch-manipulation text-secondary-dark max-lg:min-h-11 dark:text-white in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:static! in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100!"
            wire:navigate
        />

        <div class="ms-auto flex shrink-0 items-center gap-1 lg:hidden" data-sidebar-preferences>
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

            <x-locale-toggle />
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

            <flux:sidebar.item
                icon="calendar"
                :href="route('calendar.index')"
                :current="request()->routeIs('calendar.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('common.my_calendar') }}
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
                :href="route('annual-plans.index')"
                :current="request()->routeIs('annual-plans.*')"
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
        </flux:sidebar.group>
    </flux:sidebar.nav>
</flux:sidebar>
