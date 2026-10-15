{{--
    The monthly digest email (App\Notifications\MonthlyDigestMail): one block
    per section the reader may see, counts only (never an amount). Numbers
    sit in LTR runs so Arabic text keeps them readable.
--}}
<x-mail::message :message="$message ?? null" :preheader="__('notifications.mail.monthly_digest.preheader', ['month' => $monthLabel])">
# {{ __('notifications.mail.monthly_digest.heading', ['month' => $monthLabel]) }}

{{ $allPrograms ? __('notifications.mail.monthly_digest.scope_all') : __('notifications.mail.monthly_digest.scope_programs') }}

@if ($nothingPending)
<x-mail::panel>
{{ __('notifications.digests.monthly.nothing_pending') }}
</x-mail::panel>
@else
@foreach ($sections as $section => $lines)
## {{ __('notifications.digests.monthly.sections.'.$section) }}

<x-mail::table>
| {{ __('notifications.mail.monthly_digest.column_item') }} | {{ __('notifications.mail.monthly_digest.column_count') }} |
|:--|--:|
@foreach ($lines as $line => $count)
| {{ __('notifications.digests.monthly.lines.'.$section.'_'.$line) }} | <span dir="ltr">{{ $count }}</span> |
@endforeach
</x-mail::table>

@endforeach
@endif

{{ __('notifications.mail.monthly_digest.footer') }}
</x-mail::message>
