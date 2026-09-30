{{--
    Laravel's mail message, published to brand every email: the logo header,
    an optional inbox preview line, and the automated-message footer.

    Pass the mailer's $message so the logo is embedded in the email itself
    (a cid: attachment). Outlook blocks remote images by default, but shows
    embedded ones. Without it (e.g. MailMessage::render() in tests or
    previews) the logo falls back to its public URL.
--}}
@props([
    'message' => null,
    'preheader' => null,
])
@php
    $logoPath = public_path('logo-tracking-mail.png');
    $logo = $message instanceof \Illuminate\Mail\Message
        ? $message->embed($logoPath)
        : asset('logo-tracking-mail.png');
@endphp
<x-mail::layout :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :logo="$logo">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ __('app.mail_footer_automated', ['app' => config('app.name')]) }}

{{ __('app.mail_footer_copyright', ['year' => date('Y'), 'app' => config('app.name')]) }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
