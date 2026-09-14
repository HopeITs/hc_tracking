<x-layouts::auth :title="__('authentication.forgot_password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('authentication.forgot_password')" :description="__('authentication.enter_your_email_to_receive_a_password_reset_link')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('authentication.email_address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('authentication.email_password_reset_link') }}
            </flux:button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-400">
            <span>{{ __('authentication.or_return_to') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('authentication.log_in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
