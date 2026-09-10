<flux:sidebar
    sticky
    collapsible="mobile"
    aria-label="{{ __('Application navigation') }}"
    data-navigation-sidebar
    class="gap-3! border-e border-secondary-medium bg-secondary-dark p-3! text-white lg:w-[clamp(16rem,18vw,18rem)]!"
>
    <flux:sidebar.header class="min-h-11 border-b border-secondary-medium/60 pb-3">
        <x-app-logo
            :sidebar="true"
            :href="route('dashboard')"
            class="text-white hover:bg-white/10"
            wire:navigate
        />

        <flux:sidebar.collapse class="text-secondary-soft lg:hidden" />
    </flux:sidebar.header>

    <flux:sidebar.nav aria-label="{{ __('Primary navigation') }}" class="min-h-0 flex-1 gap-3 overflow-y-auto">
        <flux:sidebar.group
            :heading="__('Overview')"
            class="[&>div:first-child>div]:text-[11px]! [&>div:first-child>div]:font-semibold! [&>div:first-child>div]:tracking-[0.12em] [&>div:first-child>div]:text-secondary-soft! [&>div:first-child>div]:uppercase"
        >
            <flux:sidebar.item
                icon="home"
                :href="route('dashboard')"
                :current="request()->routeIs('dashboard')"
                class="text-secondary-soft! hover:bg-white/10! hover:text-white! data-current:border-primary! data-current:bg-primary! data-current:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!"
                wire:navigate
            >
                {{ __('Dashboard') }}
            </flux:sidebar.item>
        </flux:sidebar.group>

        <flux:sidebar.group
            :heading="__('Settings')"
            class="[&>div:first-child>div]:text-[11px]! [&>div:first-child>div]:font-semibold! [&>div:first-child>div]:tracking-[0.12em] [&>div:first-child>div]:text-secondary-soft! [&>div:first-child>div]:uppercase"
        >
            <flux:sidebar.item
                icon="user-circle"
                :href="route('profile.edit')"
                :current="request()->routeIs('profile.edit')"
                class="text-secondary-soft! hover:bg-white/10! hover:text-white! data-current:border-primary! data-current:bg-primary! data-current:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!"
                wire:navigate
            >
                {{ __('Profile') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="paint-brush"
                :href="route('appearance.edit')"
                :current="request()->routeIs('appearance.edit')"
                class="text-secondary-soft! hover:bg-white/10! hover:text-white! data-current:border-primary! data-current:bg-primary! data-current:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!"
                wire:navigate
            >
                {{ __('Appearance') }}
            </flux:sidebar.item>

            <flux:sidebar.item
                icon="shield-check"
                :href="route('security.edit')"
                :current="request()->routeIs('security.edit')"
                class="text-secondary-soft! hover:bg-white/10! hover:text-white! data-current:border-primary! data-current:bg-primary! data-current:text-white! dark:data-current:border-primary! dark:data-current:bg-primary! dark:data-current:text-white!"
                wire:navigate
            >
                {{ __('Security') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    </flux:sidebar.nav>

    <div class="hidden border-t border-secondary-medium/60 pt-3 lg:block">
        <x-desktop-user-menu :name="auth()->user()->name" />
    </div>
</flux:sidebar>
