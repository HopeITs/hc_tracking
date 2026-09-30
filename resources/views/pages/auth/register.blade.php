<x-layouts::auth :title="__('authentication.register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('authentication.create_an_account')" :description="__('authentication.enter_your_details_below_to_create_your_account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('common.name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('authentication.full_name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('authentication.email_address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('common.password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('common.password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('authentication.confirm_password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('authentication.confirm_password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('authentication.create_account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-ink-muted">
            <span>{{ __('authentication.already_have_an_account') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('authentication.log_in_2') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
