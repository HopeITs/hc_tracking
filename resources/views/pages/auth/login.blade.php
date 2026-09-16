<x-layouts::auth :title="__('authentication.sign_in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('authentication.sign_in')" :description="__('authentication.please_enter_your_credentials_to_sign_in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            @php
                $fieldClasses = 'rounded-lg! border! border-blue-100! bg-blue-50! px-4! py-2.5! h-auto! text-secondary-dark! placeholder:text-secondary-medium! focus:outline! focus:outline-2! focus:outline-secondary-medium! focus:outline-offset-2! focus:shadow-none! dark:border-secondary-medium! dark:bg-secondary-medium! dark:text-white! dark:placeholder:text-secondary-soft!';
            @endphp

            <!-- Email Address -->
            <div>
                <label for="login-email" class="mb-1.5 block text-sm font-semibold text-secondary-dark dark:text-white">
                    {{ __('authentication.email_address') }} <span class="text-primary">*</span>
                </label>
                <flux:input
                    id="login-email"
                    name="email"
                    :value="old('email')"
                    type="email"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="email@example.com"
                    input:class="{{ $fieldClasses }}"
                />
                <flux:error name="email" class="text-primary!" />
            </div>

            <!-- Password -->
            <div class="relative">
                <label for="login-password" class="mb-1.5 block text-sm font-semibold text-secondary-dark dark:text-white">
                    {{ __('common.password') }} <span class="text-primary">*</span>
                </label>
                <flux:input
                    id="login-password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('common.password')"
                    viewable
                    input:class="{{ $fieldClasses }}"
                />
                <flux:error name="password" class="text-primary!" />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('authentication.forgot_your_password') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox
                name="remember"
                :label="__('authentication.remember_me')"
                :checked="old('remember')"
                style="--color-accent: var(--color-primary); --color-accent-foreground: var(--color-white);"
            />

            <x-ui.button type="submit" tone="primary" class="min-h-11 w-full" data-test="login-button">
                {{ __('authentication.sign_in') }}
            </x-ui.button>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('authentication.don_t_have_an_account') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('authentication.sign_up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
