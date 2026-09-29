{{--
    The one-time sign-in code email (App\Notifications\EmailLoginCode).
    The inbox preview line never contains the code, so it doesn't show on a
    lock screen.
--}}
<x-mail::message :message="$message ?? null" :preheader="__('security.login_code_preheader', ['minutes' => $minutesValid])">
# {{ __('security.login_code_heading') }}

{{ __('security.login_code_intro') }}

<x-mail::code :caption="__('security.login_code_expires', ['minutes' => $minutesValid])">
{{ $code }}
</x-mail::code>

<x-mail::panel>
{{ __('security.login_code_if_it_was_not_you') }}
</x-mail::panel>
</x-mail::message>
