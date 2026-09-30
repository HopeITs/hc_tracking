<x-layouts::auth :title="__('common.two_factor_authentication')">
    <div class="flex flex-col gap-6" x-data x-init="$nextTick(() => $refs.otp?.querySelector('input')?.focus())">
        <x-auth-header
            :title="__('authentication.email_code_title')"
            :description="__('authentication.email_code_description')"
        />

        @if (session('status'))
            <flux:text class="text-center" data-test="email-code-status">{{ session('status') }}</flux:text>
        @endif

        <form method="POST" action="{{ route('email-two-factor.login.store') }}" class="space-y-5 text-center">
            @csrf

            <div class="flex items-center justify-center my-5" x-ref="otp">
                <flux:otp
                    length="6"
                    name="code"
                    :label="__('authentication.email_code_title')"
                    label:sr-only
                    class="mx-auto"
                />
            </div>

            @error('code')
                <flux:text color="red">{{ $message }}</flux:text>
            @enderror

            <div class="flex justify-center">
                <flux:checkbox name="remember_device" value="1" checked :label="__('authentication.trust_this_device')" data-test="trust-device" />
            </div>

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-code-submit">
                {{ __('common.continue') }}
            </flux:button>
        </form>

        <form method="POST" action="{{ route('email-two-factor.resend') }}" class="text-sm text-center">
            @csrf

            <button type="submit" class="font-medium underline cursor-pointer opacity-80">
                {{ __('authentication.email_code_resend') }}
            </button>
        </form>
    </div>
</x-layouts::auth>
