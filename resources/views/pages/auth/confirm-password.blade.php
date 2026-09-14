<x-layouts::auth :title="__('authentication.confirm_password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('authentication.confirm_password')"
            :description="__('authentication.this_is_a_secure_area_of_the_application_please_confirm_your')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('authentication.confirm_with_passkey')"
            :loading-label="__('authentication.confirming')"
            :separator="__('authentication.or_confirm_with_password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('common.password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('common.password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('common.confirm') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
