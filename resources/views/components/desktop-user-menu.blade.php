<flux:dropdown position="bottom" align="start" {{ $attributes }}>
    <flux:sidebar.profile
        :name="auth()->user()->name"
        icon:trailing="chevrons-up-down"
        data-test="sidebar-menu-button"
    >
        <x-slot:avatar>
            <flux:icon.user class="size-5 text-secondary-soft" />
        </x-slot:avatar>
    </flux:sidebar.profile>

    <flux:menu>
        <div class="px-1 py-1.5 text-start text-sm">
            <div class="grid text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('common.settings') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer rtl:[&_svg]:-scale-x-100"
                    data-test="logout-button"
                >
                    {{ __('common.log_out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
