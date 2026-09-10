@php
    $navigationGroupClasses = '[&>div:first-child>div]:text-[11px]! [&>div:first-child>div]:font-semibold! [&>div:first-child>div]:tracking-[0.12em] [&>div:first-child>div]:text-secondary-soft! [&>div:first-child>div]:uppercase';
    $navigationItemClasses = 'touch-manipulation text-secondary-soft! hover:bg-white/10! hover:text-white! max-lg:h-11! data-current:border-primary! data-current:bg-primary! data-current:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!';
@endphp

<flux:sidebar
    sticky
    collapsible="mobile"
    aria-label="{{ __('Application navigation') }}"
    data-navigation-sidebar
    x-on:keydown.escape.window="if (window.matchMedia('(max-width: 63.999rem)').matches && ! $el.hasAttribute('data-flux-sidebar-collapsed-mobile')) { $dispatch('flux-sidebar-toggle') }"
    class="gap-3! border-e border-secondary-medium bg-secondary-dark p-3! text-white duration-200 ease-out motion-reduce:duration-0 motion-reduce:transition-none max-lg:w-[min(18rem,calc(100vw_-_2.75rem))]! max-lg:shadow-2xl lg:w-[clamp(16rem,18vw,18rem)]!"
>
    <flux:sidebar.header class="min-h-11 border-b border-secondary-medium/60 pb-3">
        <x-app-logo
            :sidebar="true"
            :href="route('dashboard')"
            class="touch-manipulation text-white hover:bg-white/10 max-lg:min-h-11"
            wire:navigate
        />

        <flux:sidebar.collapse
            class="size-11! touch-manipulation text-secondary-soft lg:hidden"
            data-mobile-navigation-close
        />
    </flux:sidebar.header>

    <flux:sidebar.nav aria-label="{{ __('Primary navigation') }}" class="min-h-0 flex-1 gap-3 overflow-y-auto">
        <flux:sidebar.group
            :heading="__('Overview')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="home"
                :href="route('dashboard')"
                :current="request()->routeIs('dashboard')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Dashboard') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="chart-bar-square"
                :href="route('strategies.index')"
                :current="request()->routeIs('strategies.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Strategies') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="briefcase"
                :href="route('programs.index')"
                :current="request()->routeIs('programs.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Programs') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('Settings')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="user-circle"
                :href="route('profile.edit')"
                :current="request()->routeIs('profile.edit')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Profile') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="paint-brush"
                :href="route('appearance.edit')"
                :current="request()->routeIs('appearance.edit')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Appearance') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="shield-check"
                :href="route('security.edit')"
                :current="request()->routeIs('security.edit')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Security') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('Administration')"
            :class="$navigationGroupClasses"
        >
            <flux:sidebar.item
                icon="map-pin"
                :href="route('admin.cities.index')"
                :current="request()->routeIs('admin.cities.*')"
                :class="$navigationItemClasses"
                wire:navigate
            >
                {{ __('Cities') }}
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
                        {{ __('User directory') }}
                    </flux:sidebar.item>
                @endcan
            @endif
        </flux:sidebar.group>
    </flux:sidebar.nav>

    <div class="hidden border-t border-secondary-medium/60 pt-3 lg:block">
        <x-desktop-user-menu :name="auth()->user()->name" />
    </div>
</flux:sidebar>
