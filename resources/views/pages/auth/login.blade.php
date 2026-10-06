<x-layouts::auth :title="__('authentication.sign_in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('authentication.sign_in')" :description="__('authentication.please_enter_your_credentials_to_sign_in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            @php
                $fieldClasses = 'rounded-lg! border! border-blue-100! bg-blue-50! px-4! py-2.5! h-auto! text-secondary-dark! placeholder:text-secondary-medium! focus:outline! focus:outline-2! focus:outline-secondary-medium! focus:outline-offset-2! focus:shadow-none! dark:border-field-line! dark:bg-surface-2! dark:text-ink! dark:placeholder:text-ink-faint! dark:focus:outline-ink-muted!';
            @endphp

            <!-- Email Address -->
            <div>
                <label for="login-email" class="mb-1.5 block text-sm font-semibold text-secondary-dark dark:text-ink">
                    {{ __('authentication.email_address') }} <span class="text-primary dark:text-brand-text">*</span>
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
                <flux:error name="email" class="text-primary! dark:text-brand-text!" />
            </div>

            <!-- Password -->
            <div class="relative">
                <label for="login-password" class="mb-1.5 block text-sm font-semibold text-secondary-dark dark:text-ink">
                    {{ __('common.password') }} <span class="text-primary dark:text-brand-text">*</span>
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
                <flux:error name="password" class="text-primary! dark:text-brand-text!" />
            </div>

            <!-- Remember Me -->
            <flux:checkbox
                name="remember"
                :label="__('authentication.remember_me')"
                :checked="old('remember')"
                style="--color-accent: var(--color-primary); --color-accent-foreground: var(--color-white);"
            />

            @if (\App\Support\Turnstile::isEnabled())
                <!-- Human check (Cloudflare Turnstile) -->
                <div>
                    <div
                        class="cf-turnstile"
                        data-sitekey="{{ config('services.turnstile.site_key') }}"
                        data-language="{{ app()->getLocale() }}"
                        data-size="flexible"
                        data-theme="auto"
                        data-test="login-human-check"
                    ></div>
                    <flux:error :name="\App\Support\Turnstile::ResponseField" class="text-primary! dark:text-brand-text!" />
                </div>

                <script src="{{ \App\Support\Turnstile::Origin }}/turnstile/v0/api.js" async defer></script>
            @endif

            <x-ui.button type="submit" tone="primary" class="min-h-11 w-full cursor-pointer!" data-test="login-button">
                {{ __('authentication.sign_in') }}
            </x-ui.button>
        </form>
    </div>
</x-layouts::auth>
